<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Ticket;
use App\Payments\FakePay;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateTicketOrderJob implements ShouldQueue
{
    use Queueable;

    private string $orderNo;

    private int $ticketId;

    private int $userId;

    private int $qty;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $orderNo,
        int $userId,
        int $ticketId,
        int $qty
    ) {
        $this->orderNo = $orderNo;
        $this->userId = $userId;
        $this->ticketId = $ticketId;
        $this->qty = $qty;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $orderLockKey = Order::lockKey('CREATE', $this->orderNo);

        try {
            // 已存在 order 不處理 (冪等性)
            $exists = Order::where('no', $this->orderNo)->exists();
            if ($exists) {
                return;
            }

            // 避免 order 被多個 worker 執行鎖住保護
            Cache::lock($orderLockKey, 8)->get(function () {
                $ticket = Ticket::findOrFail($this->ticketId);

                $cost = $ticket->price * $this->qty;

                $order = DB::transaction(function () use ($cost) {
                    // Order with OrderLog
                    return Order::create([
                        'no' => $this->orderNo,
                        'status' => Order::STATUS['QUEUE'],
                        'user_id' => $this->userId,
                        'ticket_id' => $this->ticketId,
                        'qty' => $this->qty,
                        'cost' => $cost,
                        'last_action_by' => CreateTicketOrderJob::class,
                    ]);
                });

                try {
                    // call payment service
                    $response = App::make(FakePay::class)->pay($this->orderNo, $cost);
                } catch (Throwable $e) {
                    $message = '[CreateTicketOrderJob] FakePay '.$e->getMessage();
                    Log::error($message);
                    // 通知 SRE 支付 api 有異狀
                    // Log::channel('telegram')->error($message);
                    // 有問題 事後對帳
                    ReconcileOrderJob::dispatch($this->orderNo)->delay(5);

                    return;
                }

                $status = $response === 1 ? Order::STATUS['SUCCESS'] : Order::STATUS['FAIL'];

                DB::transaction(function () use ($order, $status) {
                    // 更新狀態
                    $order->toComplete($status, CreateTicketOrderJob::class);
                });

                $order->restockOnFail($status);
                // 其餘 推播通知... 等動作

            });
        } catch (Throwable $e) {
            Log::error('[CreateTicketOrderJob] err message '.$e->getMessage());
            Log::error('[CreateTicketOrderJob] err trace '.$e->getTraceAsString());
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error("[CreateTicketOrderJob::failed] {$this->orderNo} err {$e->getMessage()}");
        Log::error('[CreateTicketOrderJob] err trace '.$e->getTraceAsString());

        $exists = Order::where('no', $this->orderNo)->exists();

        // 訂單連建立都沒有就要退還庫存
        if (! $exists) {
            $cacheKey = Ticket::cacheKey('STOCK', $this->ticketId);

            Cache::increment($cacheKey, $this->qty);

            return;
        }
        // 有建立但莫名其妙後面失敗 已付錢要 後續對帳
        ReconcileOrderJob::dispatch($this->orderNo)->delay(10);
    }

    public function backoff(): array
    {
        return [5, 10];
    }
}
