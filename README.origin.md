# interview.laravel11
面試問題: 購票系統 5000 筆訂單湧入下場景解法

1. 預處理
使用 command App\Console\Commands\TicketPreloadCache 先做暖身把庫存寫入 cache
2. api 端點
middleware 設置 ratelimit 防止 api 被連打
POST api/order 使用 cache 做庫存檢查，防止庫存超扣
預產 order no 做 unique 給 Job (App\Jobs\CreateTicketOrderJob) 後續 worker消費處理，不直接讓流量對資料庫
3. order job
job 確保性 冪等性 (Idempotency)
分佈鎖 lock 確保當時只有一個 worker 可以消費
先創建 DB order (status=QUEUE) 在 call 支付 api, 使用狀態機 + order log 歷程可追朔
有 api 任何錯誤執行事後對帳 Job (App\Jobs\ReconcileOrderJob)
沒錯誤可以直接完成訂單並扣減庫存 (庫存是以 DB 為主)
4. 對帳 job backoff 重試 N 次
事後對帳一樣 job 確保性 冪等性 (Idempotency)
重複 N 次仍失敗交由最後排程兜底
5. 最終排程對帳
order:reconcile (App\Console\Commands\ReconcileOrder) 每 N 分鐘對帳一次
用 chunkById 避免更改 status 數據影響chunk分頁
更改狀態機 "人工處理" 並通知相關人員排除問題
