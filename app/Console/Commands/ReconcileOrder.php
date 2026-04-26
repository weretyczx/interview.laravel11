<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileOrderJob;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order:reconcile';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $minuteTrace = Carbon::now()->subMinute(6);

        $job = App::make(ReconcileOrderJob::class);

        Order::where('status', Order::STATUS['QUEUE'])
            ->where('created_at', '<=', $minuteTrace)
            ->chunkById(500, function ($orders) use ($job) {
                $orders->each(function ($order) use ($job) {
                    try {
                        $job->toReconcile($order);
                    } catch (Throwable $e) {
                        $message = "[order:reconcile] {$order->no} 對帳失敗需人工處裡";
                        Log::error($message);

                        // 通知人工處裡
                        // Log::channel('telegram')->error($message);

                        DB::transaction(function () use ($order) {
                            // 人工處理
                            $order->toComplete(Order::STATUS['MANUAL'], ReconcileOrder::class);
                        });

                        Log::error('[order:reconcile] err message '.$e->getMessage());
                        Log::error('[order:reconcile] err trace '.$e->getTraceAsString());
                    }
                });
            });
    }
}
