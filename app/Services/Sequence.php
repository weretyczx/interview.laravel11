<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class Sequence
{
    public static function no(string $prefix = '', $workerId = 0): string
    {
        $datetime = Carbon::now()->format('Ymd');

        $key = "$prefix:no:$datetime";

        $workerNo = str_pad($workerId, 2, '0', STR_PAD_LEFT);

        // set $key 0 EX 今天 NX
        // set $key + incr 非原子性 如果在意就改 lua 腳本
        Cache::add($key, 0, Carbon::now()->endOfDay());

        $incrNo = Cache::increment($key);

        $incrNo = str_pad($incrNo, 7, '0', STR_PAD_LEFT);

        // 20260404 00 0000001
        return $prefix.$datetime.$workerNo.$incrNo;
    }
}
