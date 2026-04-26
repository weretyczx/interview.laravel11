<?php

namespace App\Models\Concerns\Order;

use App\Models\OrderLog;

trait HasOrderLog
{
    public static function bootHasOrderLog()
    {
        static::saved(function ($model) {
            // 紀錄 log
            OrderLog::create([
                'no' => $model->no,
                'order_id' => $model->id,
                'status' => $model->status,
                'user_id' => $model->user_id,
                'ticket_id' => $model->ticket_id,
                'qty' => $model->qty,
                'cost' => $model->cost,
                'action_by' => $model->last_action_by,
            ]);
        });
    }
}
