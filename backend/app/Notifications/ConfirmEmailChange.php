<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Al nuovo indirizzo: il link che conferma il cambio. Finché non lo si apre
 * si entra ancora con l'email di prima.
 */
class ConfirmEmailChange extends Notification
{
    public function __construct(
        public readonly User $user,
        public readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return $this->user->family->url().'/email/conferma?'.http_build_query(['token' => $this->token]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Conferma la nuova email per PhotoDaily')
            ->greeting('Ciao!')
            ->line("Hai chiesto di usare questo indirizzo per entrare in {$this->user->family->name} su PhotoDaily.")
            ->action('Conferma la nuova email', $this->url())
            ->line('Il link vale 24 ore. Finché non lo apri si entra ancora con l\'email di prima.')
            ->line('Se non l\'hai chiesto tu, ignora questa email: non cambia niente.');
    }
}
