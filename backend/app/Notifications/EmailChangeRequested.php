<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Al vecchio indirizzo: qualcuno ha chiesto di cambiare l'email dell'account.
 * Se non è stato il titolare, se ne accorge prima che il cambio avvenga.
 */
class EmailChangeRequested extends Notification
{
    public function __construct(public readonly string $newEmail) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Richiesta di cambio email su PhotoDaily')
            ->greeting('Ciao!')
            ->line("È stato chiesto di cambiare l'email del tuo account su {$notifiable->family->name} in {$this->newEmail}.")
            ->line('Il cambio avviene solo se si apre il link mandato a quel nuovo indirizzo.')
            ->line('Se non sei stato tu, entra e cambia la password: la richiesta si annulla anche dalle impostazioni personali.')
            ->action('Apri le impostazioni', $notifiable->family->url().'/profilo');
    }
}
