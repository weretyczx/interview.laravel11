<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderCreate;
use App\Jobs\CreateTicketOrderJob;
use App\Models\Ticket;
use App\Services\Sequence;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class OrderController extends Controller
{
    public function store(OrderCreate $request)
    {
        // warm up 需先跑 ticket:preload-cache 將庫存塞入
        // 用 cache 庫存快速扣減檢查庫存
        $stockKey = Ticket::cacheKey('STOCK', $request->ticket_id);

        $remainingStock = Cache::decrement($stockKey, $request->qty);

        if ($remainingStock < 0) {
            // 庫存不足 補回已扣減部分
            Cache::increment($stockKey, $request->qty);

            return response()->json([
                'message' => 'Insufficient Stock',
                'errors' => [],
            ], 409);
        }

        // 產生訂單 no
        $orderNo = Sequence::no('T');

        // 直接丟 job 之後處理避免卡住響應
        CreateTicketOrderJob::dispatch(
            $orderNo,
            Auth::id(),
            $request->ticket_id,
            $request->qty
        );

        return response()->json([
            'data' => [
                'order_no' => $orderNo,
            ],
        ], 202);
    }
}
