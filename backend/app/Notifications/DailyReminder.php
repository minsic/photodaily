<?php

namespace App\Notifications;

use App\Models\Family;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Oggi manca ancora la foto di Gio 📷": una sera sola, e solo se nessuno
 * della famiglia ha già caricato la foto del giorno. Il tap apre il
 * caricamento con la data di oggi.
 */
class DailyReminder extends Notification
{
    public function __construct(
        public readonly Family $family,
        /** Oggi (Y-m-d) nel fuso della famiglia. */
        public readonly string $day,
    ) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function body(): string
    {
        $name = $this->family->protagonist()['name'] ?? null;

        return $name
            ? "Oggi manca ancora la foto di {$name} 📷"
            : 'Oggi manca ancora la foto del giorno 📷';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('PhotoDaily')
            ->body($this->body())
            ->icon('/icons/android-chrome-192x192.png')
            ->badge('/icons/android-chrome-192x192.png')
            // Stesso tag per lo stesso giorno: due dispositivi non la ripetono.
            ->tag("promemoria-{$this->day}")
            ->data(['url' => "/carica?data={$this->day}"])
            // Dopo mezzanotte non ha più senso: il servizio push la butta via.
            ->options(['TTL' => 4 * 3600, 'urgency' => 'normal']);
    }
}
