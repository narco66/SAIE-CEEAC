<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('saie:tick')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily();
