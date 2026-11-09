<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

Schedule::command('saie:tick')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily();
// GOV-02 : durées de conservation ; purge effective seulement si SAIE_RETENTION_AUTO_PURGE=true (sinon rien n'est lancé).
Schedule::command('saie:retention --apply')->dailyAt('02:30')->onOneServer()->when(fn (): bool => (bool) Setting::effective('retention.auto_purge'));
