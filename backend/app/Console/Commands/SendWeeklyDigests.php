<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Notifications\WeeklyDigestMail;
use App\Services\WeeklyDigest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Riepilogo della settimana, schedulato ogni ora (routes/console.php): la
 * domenica dalle 19 nel fuso della famiglia, una volta sola, a ogni membro
 * che non l'ha spento. Una settimana senza foto non manda niente.
 */
#[Signature('photos:send-weekly-digest')]
#[Description('Manda il riepilogo della settimana ai membri delle famiglie, la domenica sera')]
class SendWeeklyDigests extends Command
{
    /** Dalle 19 in poi, nel fuso della famiglia. */
    private const HOUR = 19;

    public function handle(WeeklyDigest $weeklyDigest): int
    {
        $sent = 0;

        foreach (Family::query()->active()->get() as $family) {
            $now = $family->now();

            if (! $now->isSunday() || $now->hour < self::HOUR) {
                continue;
            }

            $sunday = $now->toDateString();
            $users = $family->users()
                ->where('weekly_digest_enabled', true)
                ->where(fn ($query) => $query->whereNull('weekly_digest_last_sent_on')->orWhereDate('weekly_digest_last_sent_on', '<', $sunday))
                ->get();

            if ($users->isEmpty()) {
                continue;
            }

            $digest = $weeklyDigest->for($family, $now);

            if ($digest['giorni'] === []) {
                continue;
            }

            foreach ($users as $user) {
                try {
                    $user->notify(new WeeklyDigestMail($family, $digest));
                } catch (Throwable $e) {
                    $this->components->warn("Riepilogo per l'utente {$user->id} non mandato: {$e->getMessage()}");

                    continue;
                }

                $user->forceFill(['weekly_digest_last_sent_on' => $sunday])->save();
                $sent++;
            }
        }

        $this->components->info("Riepiloghi mandati: {$sent}.");

        return self::SUCCESS;
    }
}
