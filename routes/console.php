<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('citas:recordatorios')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron.log'));


    // Schedule::command('citas:recordatorios')
    // ->everyThirtyMinutes()
    // ->withoutOverlapping()
    // ->appendOutputTo(storage_path('logs/cron.log'));