<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mvpn:sweep-expired')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('mvpn:scan-anomalies')->everyMinute()->withoutOverlapping();
Schedule::command('auth:clear-resets')->daily();
