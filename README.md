# 投稿審查

這是 PHP＋PostgreSQL 的期中展示版。上方兩個按鈕可切換角色，作者固定使用「示範作者」身分。系統將目前角色記在 PHP Session，兩個角色共用 PostgreSQL 裡的稿件資料。

## 在 Windows 啟動

1. 確認 PostgreSQL 已啟動，並在課堂使用的 Adminer 或 pgAdmin 建立資料庫 `submission_demo`。
2. 選取這個資料庫並執行 `schema.sql`。在 Adminer 可透過「SQL 指令」貼上並執行檔案內容。
3. 在專案根目錄開 PowerShell。先用 `php -m` 確認有 `pdo_pgsql`，接著設定資料庫密碼並啟動 PHP。

```powershell
$env:SUBMISSION_DB_PASSWORD = '換成自己的 PostgreSQL 密碼'
php -S 127.0.0.1:8000 -t public
```

4. 用瀏覽器開啟 [投稿審查](http://127.0.0.1:8000/)。保留 PowerShell 視窗，結束時按 Ctrl+C。

預設連線為 `127.0.0.1:5432`、資料庫 `submission_demo`、帳號 `postgres`。如果課堂設定不同，在啟動前調整對應的環境變數。

```powershell
$env:SUBMISSION_DB_USER = '你的帳號'
$env:SUBMISSION_DSN = 'pgsql:host=127.0.0.1;port=5432;dbname=submission_demo'
```

## 上台操作

1. 點「作者」新增稿件並儲存，確認狀態顯示「編輯中」。
2. 按「送出」，確認顯示「審查中」。
3. 點「編輯」切到待審查清單，按「查看」讀全文，再按「退回」。
4. 切回作者，編輯原稿並再次送出。
5. 切到編輯並按「通過」。切回作者，確認同一篇稿件顯示「已通過」。
6. 另外新增一篇草稿，示範刪除。

## 怎麼解釋程式

- `schema.sql` 建立稿件表。每篇稿件有自己的 ID，狀態欄位存 `draft`（編輯中）、`pending`（審查中）或 `approved`（已通過）。
- `public/index.php` 上半部處理表單和查詢，下半部顯示畫面。作者清單依作者篩選，編輯清單依「審查中」篩選。
- 作者送出時，PHP 先確認角色，SQL 再確認稿件 ID、作者和目前狀態都符合條件，才改成「審查中」。編輯退回時更新同一筆稿件，所以作者可以繼續修改。
- `public/style.css` 負責版面、表單和按鈕樣式。

## 更新既有資料庫

在 pgAdmin 對 `submission_demo` 開啟 Query Tool，重新執行本版的 `schema.sql`。
這會為既有稿件表新增退回理由欄位，保留原有稿件。完成後重新整理網站即可。
若舊稿件曾於更新前退回，當時的理由無從補登，清單提示會在本版退回後出現。

編輯按「退回稿件」時須填理由，按「通過」可留空。作者可在清單看到「曾被退回」，並在編輯頁查看最近一次的退回理由。
