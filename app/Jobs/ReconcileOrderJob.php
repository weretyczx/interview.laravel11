<?php

namespace App\Jobs;

use App\Models\Order;
use App\Payments\FakePay;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileOrderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    private string $orderNo;

    /**
     * Create a new job instance.
     */
    public function __construct(string $orderNo)
    {
        $this->orderNo = $orderNo;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $order = Order::where('no', $this->orderNo)
                ->where('status', Order::STATUS['QUEUE'])
                ->first();

            if (! $order) {
                return;
            }

            $this->toReconcile($order);

        } catch (Throwable $e) {
            Log::error('[ReconcileOrderJob] err message'.$e->getMessage());
            Log::error('[ReconcileOrderJob] err trace'.$e->getTraceAsString());
            throw $e; // 讓 backoff + retry 處理
        }
    }

    public function toReconcile(Order $order): void
    {
        $reconcileKey = Order::lockKey('RECONCILE', $order->no);

        // 預防其他 worker 消費
        Cache::lock($reconcileKey, 8)->get(function () use ($order) {
            try {
                $info = App::make(FakePay::class)->orderInfo($order->no);
                $response = json_decode($info);
            } catch (Throwable $e) {
                throw new Exception("[ReconcileOrderJob] {$order->no} 對帳 API 失敗等待重試");
            }

            $status = $response->success === 1 ?
                      Order::STATUS['SUCCESS'] : Order::STATUS['FAIL'];

            DB::transaction(function () use ($order, $status) {
                // 確保 OrderLog 有一起 transaction
                $order->toComplete($status, ReconcileOrderJob::class);
            });

            $order->restockOnFail($status);
            // 其餘 推播通知... 等動作
        });
    }

    public function failed(Throwable $e): void
    {
        Log::error("[ReconcileOrderJob::failed] {$this->orderNo} 對帳 Job 失敗，等待 order:reconcile 排程做最後處理 err {$e->getMessage()}");
        Log::error('[ReconcileOrderJob] err trace '.$e->getTraceAsString());
    }

    public function backoff(): array
    {
        return [10, 20, 40, 80];
    }
}
