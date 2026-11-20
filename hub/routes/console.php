<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purga por retención (R14): registros viejos que ya no sirven y pueden llevar datos personales.
Schedule::command('igr:retention:purge')->dailyAt('03:25')->withoutOverlapping()->onOneServer();
