<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Models\User;
use App\Notifications\DailyReminder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Promemoria serale, schedulato ogni 15 minuti (routes/console.php). Per ogni
 * utente con il promemoria attivo e almeno un dispositivo iscritto: se nel
 * fuso della famiglia è arrivato il suo orario e oggi non c'è ancora una foto
 * pubblicata (di nessun membro), gli manda una notifica, una sola al giorno.
 */
#[Signature('photos:send-reminders')]
#[Description('Manda il promemoria serale a chi non ha ancora la foto di oggi')]
class SendDailyReminders extends Command
{
    public function handle(): int
    {
        $sent = 0;

        $users = User::query()
            ->where('reminder_enabled', true)
            ->whereHas('pushSubscriptions')
            ->with('family')
            ->get();

        foreach ($users as $user) {
            $now = $user->family->now();
            $today = $now->toDateString();

            if ($this->day($user->reminder_last_sent_on) === $today) {
                continue;
            }

            if ($now->format('H:i') < substr((string) $user->reminder_time, 0, 5)) {
                continue;
            }

            $hasPhoto = Photo::query()
                ->where('family_id', $user->family_id)
                ->where('is_draft', false)
                ->where(fn (Builder $query) => $query->whereDate('data', $today))
                ->exists();

            if ($hasPhoto) {
                continue;
            }

            try {
                $user->notify(new DailyReminder($user->family, $today));
            } catch (Throwable $e) {
                $this->components->warn("Promemoria per l'utente {$user->id} non mandato: {$e->getMessage()}");

                continue;
            }

            // Segnato anche se un dispositivo ha rifiutato: domani si riprova.
            $user->forceFill(['reminder_last_sent_on' => $today])->save();
            $sent++;
        }

        $this->components->info("Promemoria mandati: {$sent}.");

        return self::SUCCESS;
    }

    /** La colonna è una date: su SQLite può tornare con l'ora. */
    private function day(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
