<?php

namespace App\Models\Concerns\Order;

use App\Models\OrderLog;

trait HasOrderLog
{
    public static function bootHasOrderLog()
    {
        static::saved(function ($model) {
            // 紀錄 log
            $model->logOrder();

        });
    }

    public function logOrder(array $extra = [])
    {
        OrderLog::create(array_merge([
            'no' => $this->no,
            'order_id' => $this->id,
            'status' => $this->status,
            'user_id' => $this->user_id,
            'ticket_id' => $this->ticket_id,
            'qty' => $this->qty,
            'cost' => $this->cost,
            'action_by' => $this->last_action_by,
        ], $extra));
    }
}
