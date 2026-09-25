#!/usr/bin/env bash
# Deploy di PhotoDaily sulla VPS, come utente photodaily:
#
#   photodaily-deploy          # ultimo commit di main
#   photodaily-deploy <ref>    # un branch o un tag
#
# Ogni deploy è una cartella nuova in releases/: si prepara tutto lì
# (dipendenze, build, migrazioni) e solo alla fine il link "current" passa
# alla nuova release. Se qualcosa va storto prima, il sito resta com'era.
set -euo pipefail

APP_DIR=/var/www/photodaily
REPO_URL=${REPO_URL:-https://github.com/minsic/photodaily.git}
REF=${1:-main}
KEEP_RELEASES=5
RELEASE=$APP_DIR/releases/$(date +%Y%m%d-%H%M%S)

if [[ $(id -un) != photodaily ]]; then
    echo "Lancia il deploy come utente photodaily: sudo -iu photodaily photodaily-deploy" >&2
    exit 1
fi

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

step "Scarico $REF in $RELEASE"
git clone --quiet --depth 1 --branch "$REF" "$REPO_URL" "$RELEASE"
trap 'echo; echo "Deploy fallito: il sito resta sulla release precedente. Cartella lasciata per controllo: $RELEASE" >&2' ERR

step "Collego .env e storage condivisi"
ln -s "$APP_DIR/shared/.env" "$RELEASE/backend/.env"
rm -rf "$RELEASE/backend/storage"
ln -s "$APP_DIR/shared/storage" "$RELEASE/backend/storage"

step "Dipendenze PHP"
cd "$RELEASE/backend"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

step "Build del frontend"
cd "$RELEASE/frontend"
npm ci --no-audit --no-fund --loglevel=error
VITE_API_URL=/api npm run build
rm -rf node_modules

step "Migrazioni e cache"
cd "$RELEASE/backend"
php artisan migrate --force
php artisan optimize

step "Attivo la release"
ln -sfn "$RELEASE" "$APP_DIR/current.new"
mv -Tf "$APP_DIR/current.new" "$APP_DIR/current"
# OPcache non ricontrolla i file: senza reload servirebbe ancora la release vecchia.
sudo /usr/bin/systemctl reload php8.4-fpm
php artisan queue:restart

step "Pulizia delle release vecchie (ne tengo $KEEP_RELEASES)"
ls -1dt "$APP_DIR"/releases/*/ | tail -n +$((KEEP_RELEASES + 1)) | xargs -r rm -rf

trap - ERR
step "Fatto: $(git -C "$RELEASE" log -1 --format='%h %s')"
