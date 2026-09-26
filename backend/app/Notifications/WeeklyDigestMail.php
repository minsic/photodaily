<?php

namespace App\Notifications;

use App\Models\Family;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Il riepilogo della settimana, la domenica sera: le foto in miniatura,
 * quanti giorni hanno la foto, quali mancano, i cuori arrivati.
 */
class WeeklyDigestMail extends Notification
{
    /**
     * @param  array{da: string, a: string, giorni: list<array{data: string, id: string, speciale: bool, foto: int, thumbnail_url: string}>, pieni: int, totali: int, mancanti: list<string>, cuori: int}  $digest
     */
    public function __construct(
        public readonly Family $family,
        public readonly array $digest,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->family->protagonist()['name'] ?? null;
        $to = CarbonImmutable::parse($this->digest['a']);
        $url = $this->family->url();

        return (new MailMessage)
            ->subject($name ? "La settimana di {$name} su PhotoDaily" : "La settimana di {$this->family->name} su PhotoDaily")
            ->markdown('mail.weekly-digest', [
                'family' => $this->family,
                'name' => $name,
                'digest' => $this->digest,
                'days' => collect($this->digest['giorni'])->map(fn (array $day) => [
                    ...$day,
                    'label' => $this->dayLabel($day['data']),
                    'url' => "{$url}/foto/{$day['id']}",
                ])->all(),
                'missing' => array_map(fn (string $date) => $this->dayLabel($date), $this->digest['mancanti']),
                'monthUrl' => "{$url}/mese/{$to->year}/{$to->month}",
                'profileUrl' => "{$url}/profilo",
            ]);
    }

    private function dayLabel(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('it')->isoFormat('dddd D');
    }
}
