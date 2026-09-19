<?php

namespace App\Notifications;

use App\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FamilyInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Invite $invite,
        public readonly string $url,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $family = $this->invite->family;
        $inviter = $this->invite->inviter;

        return (new MailMessage)
            ->subject("Invito a {$family->name} su PhotoDaily")
            ->greeting('Ciao!')
            ->line(($inviter?->name ?? 'Un membro della famiglia')." ti invita a unirti a {$family->name} su PhotoDaily, il diario fotografico di famiglia.")
            ->action('Accetta l\'invito', $this->url)
            ->line('L\'invito scade il '.$this->invite->expires_at->format('d/m/Y').'.')
            ->line('Se non aspettavi questo invito puoi ignorare questa email.');
    }
}
