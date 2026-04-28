# 高併發購票系統技術方案 (Laravel 11)

針對 **5,000 TPS (Transactions Per Second)** 級別的瞬時湧入場景設計，核心理念為「快取預扣、非同步削峰、最終一致性保障」。

---

## 🛠 核心架構設計

### 1. 預處理與流量防護 (Pre-processing)
* **庫存預熱 (Cache Warming)**
    * 執行 `App\Console\Commands\TicketPreloadCache` 將資料庫庫存同步至 Redis。
    * 在高併發期間，庫存扣減僅在 Redis 層進行，避免資料庫 Row Lock 導致連鎖崩潰。
* **流量整形 (Rate Limiting)**
    * 透過 Middleware 設定 `throttle`，防止 API 惡意連打或機器人腳本攻擊。

### 2. 請求處理流程 (API Endpoint)
* **即時檢查**：使用 Redis 原子性操作進行庫存預扣，確保不超賣。
* **預產訂單號**：在進入隊列前產出 Unique Order No，作為後續 **冪等性 (Idempotency)** 校驗的關鍵憑證。
* **非同步削峰**：將訂單資訊封裝進 `App\Jobs\CreateTicketOrderJob` 進入隊列處理，不直接操作 DB，確保 API 能在毫秒級回傳。

### 3. 非同步消費邏輯 (Job Worker)
* **分佈式鎖 (Distributed Locking)**：使用 Redis Lock 確保同一個 Order No 僅會被一個 Worker 消費，防止重複下單。
* **狀態機管理 (State Machine)**：
    1.  建立 DB 紀錄（初始狀態：`QUEUE`）。
    2.  呼叫外部支付 Gateway。
    3.  根據結果流轉至 `SUCCESS` 或 `FAILED`，並記錄 **Order Log** 確保歷程可追溯。
* **資料一致性**：若金流端調用失敗，立即觸發 `App\Jobs\ReconcileOrderJob` 進行非同步補償。

---

## 🛡 容錯與自動修復機制 (Reliability)

### 1. 異常自動對帳
* **Job Backoff**：設定指數退避重試（Exponential Backoff），優雅處理外部 API 暫時性超時。
* **自動對帳 Job**：針對失敗或狀態未明的訂單，主動向金流端查詢最新狀態並修正本地數據。

### 2. 定期排程兜底 (Final Safety Net)
* 執行 `App\Console\Commands\ReconcileOrder`（每 N 分鐘執行）。
* **效能優化**：使用 `chunkById` 進行分批掃描，避免在更新狀態時影響分頁查詢偏移，確保對帳不漏單。
* **異常通知**：若多次自動對帳仍無法修復，狀態標記為 `MANUAL` 並觸發告警通知由人工介入。

---

## 📂 關鍵檔案清單

| 檔案路徑 | 類型 | 職責 |
| :--- | :--- | :--- |
| `App\Console\Commands\TicketPreloadCache` | Command | 系統暖身，將庫存寫入 Cache |
| `App\Jobs\CreateTicketOrderJob` | Job | 非同步寫入 DB 與執行金流支付 |
| `App\Jobs\ReconcileOrderJob` | Job | 單筆訂單異常後的自動補償對帳 |
| `App\Console\Commands\ReconcileOrder` | Command | 全局排程掃描，確保資料最終一致性 |

## 🐳 docker 跑起服務
```
docker run -d \
  --name php-micro \
  --network docker_backend \
  -e APP_KEY=base64:oUraOgCdzhEwAvYLtxv/qB77pf7i9vqON8f0ShcphDw= \
  -e DB_HOST=mysql \
  registry.gitlab.com/weretyczx/interview.laravel11:prod-latest
```

### nginx.conf
```
server {
     listen 80;
     server_name api.micro.dd;

     root  /app/public;
     index.php index;

     location / {
         try_files $uri $uri/ /index.php?$query_string;
     }

     location ~ \.php$ {
         fastcgi_pass               php-micro:9000;
         fastcgi_index              index.php;
         fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
         include                    fastcgi_params;

         fastcgi_connect_timeout 300;
         fastcgi_read_timeout    300;
         fastcgi_send_timeout    300;
         fastcgi_buffers      16 16k;
         fastcgi_buffer_size     32k;
     }

     access_log off;
     error_log  /var/log/nginx/err.log;
 }
 ```
 測試 api ping
 ```
    curl http://api.micro.dd/api/ping
    "pong"%
 ```
---
