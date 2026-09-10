<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs `php artisan schedule:run` every minute from cron on the server.
Schedule::command('project-tasks:send-reminders')->dailyAt('07:00')->timezone(config('reports.timezone'));
