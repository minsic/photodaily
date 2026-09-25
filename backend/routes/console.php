<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Promemoria serale: ogni 15 minuti si guarda a chi è arrivato l'orario.
Schedule::command('photos:send-reminders')->everyFifteenMinutes()->withoutOverlapping();
