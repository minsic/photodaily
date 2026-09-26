<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Il link per scegliere una password nuova. Porta sempre all'indirizzo della
 * famiglia dell'account, qualunque sia quello da cui è stato chiesto.
 */
class ResetPasswordLink extends Notification
{
    public function __construct(public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(User $user): string
    {
        return $user->family->url().'/password/nuova?'.http_build_query(['token' => $this->token, 'email' => $user->email]);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('Nuova password per PhotoDaily')
            ->greeting('Ciao!')
            ->line("Hai chiesto di cambiare la password del tuo account su {$notifiable->family->name}.")
            ->action('Scegli la nuova password', $this->url($notifiable))
            ->line("Il link vale {$minutes} minuti e si può usare una volta sola.")
            ->line('Se non l\'hai chiesto tu, ignora questa email: la password resta quella di prima.');
    }
}
