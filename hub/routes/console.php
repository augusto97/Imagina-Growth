<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purga por retención (R14): registros viejos que ya no sirven y pueden llevar datos personales; los mensajes pasan
// antes al archivo agregado.
Schedule::command('igr:retention:purge')->dailyAt('03:25')->withoutOverlapping()->onOneServer();

// Tasas de cambio del día (F11): la fuente se actualiza una vez al día cerca de las 00:00 UTC; la segunda corrida
// recoge el día si la primera falló.
Schedule::command('igr:fx:update')->twiceDailyAt(1, 13, 17)->withoutOverlapping()->onOneServer();
