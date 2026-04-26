<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Kra8\Snowflake\HasSnowflakePrimary;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use Concerns\Order\HasOrderLog, HasFactory, HasSnowflakePrimary;

    protected $guarded = ['id'];

    const STATUS = [
        'QUEUE' => 0,
        'SUCCESS' => 1,
        'FAIL' => 2,
        'MANUAL' => 3, // 人工處理
    ];

    const LOCKKEY = [
        'CREATE' => 'LOCK_ORDER_CREATE',
        'RECONCILE' => 'LOCK_ORDER_RECONCILE',
    ];

    public static function lockKey(string $key, string $orderNo): string
    {
        if (! isset(self::LOCKKEY[$key])) {
            throw new Exception("Order lock key [$key] not found");
        }

        return self::LOCKKEY[$key].':'.$orderNo;
    }

    /**
     * 訂單完成
     */
    public function toComplete(int $status, string $actionBy): void
    {
        // 訂單完成
        $this->update([
            'status' => $status,
            'last_action_by' => $actionBy,
        ]);

        // 扣減庫存
        if ($status === Order::STATUS['SUCCESS']) {
            $affected = Ticket::where('id', $this->ticket_id)
                ->where('stock_qty', '>=', $this->qty)
                ->decrement('stock_qty', $this->qty);

            // 如果沒有異動到 扣減失敗要錯誤
            if ($affected === 0) {
                throw new Exception(
                    "[Order::toComplete] DB stock insufficient ticket_id={$this->ticket_id} qty={$this->qty} order_no={$this->no}"
                );
            }
        }
    }

    public function restockOnFail(int $status): void
    {
        // 失敗更新庫存
        if ($status === Order::STATUS['FAIL']) {
            $stockKey = Ticket::cacheKey('STOCK', $this->ticket_id);
            // 付款失敗補回庫存
            Cache::increment($stockKey, $this->qty);
        }
    }
}
