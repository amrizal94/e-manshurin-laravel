<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// activitylog.delete_records_older_than_days cuma berlaku kalau perintah ini benar-benar jalan
Schedule::command('activitylog:clean')->dailyAt('02:00');
