<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Proof the hPanel cron is calling `schedule:run` (shown on the admin dashboard).
Schedule::call(fn () => Cache::forever('scheduler:last_run', now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat');

Schedule::command('mvpn:sweep-expired')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('mvpn:scan-anomalies')->everyMinute()->withoutOverlapping();
Schedule::command('auth:clear-resets')->daily();
Schedule::command('mvpn:dedupe-devices')->hourly()->withoutOverlapping();
Schedule::command('mvpn:remind-expiring')->hourly()->withoutOverlapping();
