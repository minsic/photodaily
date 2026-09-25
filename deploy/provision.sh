#!/usr/bin/env bash
# Prepara una VPS Ubuntu 24.04 appena installata per PhotoDaily.
# Da lanciare dalla copia del repository sul server, come root:
#
#   sudo ACME_EMAIL=tu@esempio.it bash deploy/provision.sh
#
# Si può rilanciare: i passi già fatti vengono saltati o ripetuti senza danni.
# Non tocca mai .env, database e utente MySQL se esistono già.
set -euo pipefail

APP_USER=photodaily
APP_DIR=/var/www/photodaily
DB_NAME=photodaily
DB_USER=photodaily
PHP=8.4
NODE_MAJOR=22
DEPLOY_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
warn() { printf '\033[33m!! %s\033[0m\n' "$*"; }

[[ $EUID -eq 0 ]] || { echo "Serve root: sudo ACME_EMAIL=... bash $0" >&2; exit 1; }
[[ -n ${ACME_EMAIL:-} ]] || { echo "Imposta ACME_EMAIL (email per Let's Encrypt): sudo ACME_EMAIL=... bash $0" >&2; exit 1; }
grep -q 'VERSION_ID="24.04"' /etc/os-release || warn "Pensato per Ubuntu 24.04: su altre versioni controlla i passaggi."

export DEBIAN_FRONTEND=noninteractive

step "Aggiornamenti e pacchetti di base"
apt-get update -q
apt-get upgrade -yq -o Dpkg::Options::=--force-confold
apt-get install -yq ca-certificates curl gnupg unzip git ufw fail2ban unattended-upgrades \
    software-properties-common debian-keyring debian-archive-keyring apt-transport-https rclone
timedatectl set-timezone Europe/Rome
dpkg-reconfigure -f noninteractive unattended-upgrades

step "Swap da 2 GB (composer e build del frontend con 4 GB di RAM)"
if ! swapon --show | grep -q .; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

step "PHP $PHP"
add-apt-repository -y ppa:ondrej/php
apt-get update -q
apt-get install -yq php$PHP-fpm php$PHP-cli php$PHP-mysql php$PHP-sqlite3 php$PHP-mbstring php$PHP-xml \
    php$PHP-curl php$PHP-zip php$PHP-gd php$PHP-intl php$PHP-bcmath php$PHP-opcache
update-alternatives --set php /usr/bin/php$PHP

for sapi in fpm cli; do
    cat > /etc/php/$PHP/$sapi/conf.d/99-photodaily.ini <<'INI'
; Foto fino a 20 MB (PHOTOS_MAX_UPLOAD_KB), con margine per il resto del form.
upload_max_filesize = 25M
post_max_size = 30M
memory_limit = 256M
expose_php = Off

; Il codice cambia solo con un deploy, che fa reload di PHP-FPM.
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
INI
done

step "Composer"
if ! command -v composer >/dev/null; then
    expected=$(curl -fsSL https://composer.github.io/installer.sig)
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual=$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
    [[ $expected == "$actual" ]] || { echo "Firma dell'installer di Composer non valida" >&2; exit 1; }
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm /tmp/composer-setup.php
fi

step "Node.js $NODE_MAJOR (solo per la build del frontend)"
if ! node --version 2>/dev/null | grep -q "^v$NODE_MAJOR\."; then
    curl -fsSL https://deb.nodesource.com/setup_$NODE_MAJOR.x | bash -
    apt-get install -yq nodejs
fi

step "MySQL"
apt-get install -yq mysql-server
systemctl enable --now mysql

step "Caddy"
if ! command -v caddy >/dev/null; then
    curl -fsSL 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -fsSL 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -q
    apt-get install -yq caddy
fi

step "Utente $APP_USER e cartelle"
if ! id "$APP_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos 'PhotoDaily' "$APP_USER"
fi
# Caddy deve leggere i file del frontend.
usermod -aG "$APP_USER" caddy

# Stesse chiavi SSH dell'utente che ha lanciato lo script (su OVH è "ubuntu").
if [[ -n ${SUDO_USER:-} && -s /home/$SUDO_USER/.ssh/authorized_keys ]]; then
    install -d -m 700 -o "$APP_USER" -g "$APP_USER" /home/$APP_USER/.ssh
    install -m 600 -o "$APP_USER" -g "$APP_USER" /home/$SUDO_USER/.ssh/authorized_keys /home/$APP_USER/.ssh/authorized_keys
fi

install -d -m 750 -o "$APP_USER" -g "$APP_USER" "$APP_DIR" "$APP_DIR/releases" "$APP_DIR/shared"
for dir in app/private framework/cache/data framework/sessions framework/views logs; do
    install -d -m 750 "$APP_DIR/shared/storage/$dir"
done
# install -d assegna il proprietario solo all'ultima cartella del percorso.
chown -R "$APP_USER:$APP_USER" "$APP_DIR/shared/storage"
chmod -R u=rwX,g=rX,o= "$APP_DIR/shared/storage"

# Il deploy può solo ricaricare PHP-FPM, niente altro come root.
cat > /etc/sudoers.d/photodaily <<SUDO
$APP_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php$PHP-fpm
SUDO
chmod 440 /etc/sudoers.d/photodaily
visudo -cf /etc/sudoers.d/photodaily

install -m 755 "$DEPLOY_DIR/deploy.sh" /usr/local/bin/photodaily-deploy
install -m 755 "$DEPLOY_DIR/backup.sh" /usr/local/bin/photodaily-backup

step "Database e .env"
if [[ ! -f $APP_DIR/shared/.env ]]; then
    db_password=$(openssl rand -base64 32 | tr -d '/+=' | cut -c1-32)
    app_key="base64:$(openssl rand -base64 32)"

    mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$db_password';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$db_password';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

    sed -e "s|^APP_KEY=.*|APP_KEY=$app_key|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$db_password|" \
        "$DEPLOY_DIR/env.production.example" > "$APP_DIR/shared/.env"
    chown "$APP_USER:$APP_USER" "$APP_DIR/shared/.env"
    chmod 600 "$APP_DIR/shared/.env"
    warn "Creato $APP_DIR/shared/.env: completa R2_* e MAIL_* prima del primo deploy."
else
    echo "$APP_DIR/shared/.env esiste già: non lo tocco."
fi

step "PHP-FPM: pool dedicato a $APP_USER"
cat > /etc/php/$PHP/fpm/pool.d/photodaily.conf <<POOL
[photodaily]
user = $APP_USER
group = $APP_USER
listen = /run/php/photodaily.sock
listen.owner = caddy
listen.group = caddy
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
pm.max_requests = 500
POOL
rm -f /etc/php/$PHP/fpm/pool.d/www.conf
systemctl restart php$PHP-fpm

step "Worker della coda e cron"
install -m 644 "$DEPLOY_DIR/photodaily-queue.service" /etc/systemd/system/photodaily-queue.service
systemctl daemon-reload
# Parte davvero solo dopo il primo deploy, quando esiste "current".
systemctl enable photodaily-queue

cat > /etc/cron.d/photodaily <<CRON
# Scheduler di Laravel e backup notturno del database.
* * * * * $APP_USER [ -d $APP_DIR/current ] && cd $APP_DIR/current/backend && php artisan schedule:run >> /dev/null 2>&1
30 3 * * * root /usr/local/bin/photodaily-backup
CRON

step "Caddy"
install -d -o caddy -g caddy /var/log/caddy
sed "s|__ACME_EMAIL__|$ACME_EMAIL|" "$DEPLOY_DIR/Caddyfile" > /etc/caddy/Caddyfile
# Come utente caddy: da root validate creerebbe il file di log intestato a root,
# e poi Caddy non riuscirebbe ad aprirlo.
sudo -u caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
chown -R caddy:caddy /var/log/caddy
systemctl enable caddy
# Restart, non reload: Caddy deve ripartire col gruppo photodaily appena aggiunto.
systemctl restart caddy

step "Firewall"
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

step "SSH"
if [[ -s /home/$APP_USER/.ssh/authorized_keys ]]; then
    # sshd tiene il primo valore che trova e i file si leggono in ordine alfabetico:
    # "01-" viene prima del 50-cloud-init.conf che sulle immagini OVH riattiva le password.
    cat > /etc/ssh/sshd_config.d/01-photodaily.conf <<SSHD
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
SSHD
    sshd -t
    systemctl reload ssh || systemctl restart ssh
    echo "Accesso SSH solo con chiave, root disabilitato."
else
    warn "Nessuna chiave SSH trovata: lascio attivo l'accesso con password."
    warn "Aggiungi la chiave a ~/.ssh/authorized_keys e rilancia lo script per disattivarlo."
fi

step "Fatto"
cat <<DONE
Prossimi passi:
  1. Completa R2_* e MAIL_* in $APP_DIR/shared/.env
  2. sudo -iu $APP_USER photodaily-deploy
  3. sudo systemctl start photodaily-queue
DONE
