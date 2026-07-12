<?php

use Illuminate\Support\Facades\Schedule;

// Wakes delayed automation runs whose wake_at has elapsed. Delays are
// persisted on the run (not as delayed queue jobs) so they survive queue
// restarts and support multi-day waits on any queue driver.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
