<?php

namespace App\Payments;

class FakePay
{
    public function __construct() {}

    // 支付
    public function pay(string $orderNo, int $money): int
    {
        // always true
        return 1;
    }

    // 查看訂單狀態
    public function orderInfo(string $orderNo): string
    {
        // always true
        return json_encode([
            'success' => 1,
        ]);
    }
}
