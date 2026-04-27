<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('order:reconcile')
    ->everyFiveMinutes()
    ->runInBackground()
    ->withoutOverlapping(10);
