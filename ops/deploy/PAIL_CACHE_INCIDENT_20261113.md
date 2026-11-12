# PPB V2：Pail / Composer / Laravel 快取相容性修復（2026-11-13）

## 唯讀對帳，故障根因已確認

第 52 步 GitHub Actions `operation=deploy`：六個 `vendor/composer/*` 自動產生檔案變更後，本機 HTTPS 由 302 變成 HTTP 500，觸發程式回復。已經由使用者 EC2 唯讀指令確認：

- `LIVE_PAIL_PACKAGE_INSTALLED_JSON=YES`，`LIVE_PAIL_PROVIDER_FILE=YES`，`LIVE_AUTOLOAD_*_PAIL=YES`。
- `CANDIDATE_PAIL_PACKAGE_INSTALLED_JSON=NO`，`CANDIDATE_PAIL_PROVIDER_FILE=NO`，`CANDIDATE_AUTOLOAD_*_PAIL=NO`。
- `bootstrap/cache/packages.php_PAIL=YES`，`bootstrap/cache/services.php_PAIL=YES`。
- 報錯 `Laravel\Pail\PailServiceProvider` Class Not Found，後續 `translator` / ViewException。
- 自動回復後 `LOCAL_HTTPS_HTTP=302`、六檔 `RESTORED`、交易 `rolled_back`、`PENDING_DEPLOYMENT=NONE`、`CURRENT_RELEASE_STATE=NONE`。不用再重做這些已完成檢查。

**直接原因：** GitHub runner `composer install --no-dev` 移除了 `require-dev` 的 `laravel/pail`，卻只把 Composer autoload metadata 交到保留 dev provider 快取的正式機，造成 provider 載入失敗。

## 本批調整（單 PR，不修改現場）

1. 暫時使 GitHub build 和**現有正式站依賴模式一致**：從 `composer install --no-dev` 改為 `composer install`，保留 `--no-scripts --no-plugins` 與 `composer.lock`、PHP 平台需求比對；明確驗證 `class_exists("Laravel\\Pail\\PailServiceProvider")`。**不等於建議未來永久在正式站安裝開發依賴**。
2. SSM 固定文件 `PPB-V2-Controlled-Deploy` 的 `controlledRelease` 現在先跑完整唯讀 Audit 與 SHA256，再使用**候選版本的 Composer autoload**，檢查現場 `bootstrap/cache/packages.php`、`services.php` 中所有已註冊的 Service Provider 均能成功載入。缺少 Pail 或任何其他 provider、快取缺失／無法讀取時，**在任何 live 寫入前 STOP**；audit 也會失敗，不會假 PASS。
3. 首次正式部署仍保留第 52 步核准的**六個 Composer 特定檔案白名單**：`REPLACE_FILES=6`、`ADD_FILES=0`、`DELETE_FILES=0`，其他差異不得自動越權部署。如果新建包的差異量或檔名改變，需要取得新的明確核准並同步更新白名單，**不能直接再次按 deploy**。
4. 離線測試在 TempDirectory 模擬兩種狀態：候選缺 Pail 必須拒絕；候選具 Pail 才通過；另測 live provider cache 缺失與 POSIX Shell 語法。
5. 不修改 EC2 固定 `controlled-deploy.py`、DB、舊系統、Nginx、WordPress、LINE、排程。S3 與 IAM 設定完全沿用。

## 驗收順序（避免重複危險測試）

- 合併此 PR 只改 GitHub，不動正式站。
- 從此 PR 合併後的 `ops/deploy/PPB-V2-Controlled-Deploy.ssm.json` 建立 AWS 同名文件下一版，設定為 Default；不用重新建立整份文件。
- **只執行一次 `operation=audit`，confirmation 留空**。這是新的依賴模式，先確認打包能完成、SSM `RESULT=PPB_V2_PROVIDER_CACHE_COMPAT_PASS`、以及新增／替換／刪除實際範圍。
- 只有當新 Audit 和六檔白名單**全部吻合**且取得使用者單獨明確授權，才允許正式 `deploy`。若因補齊 dev 依賴使變更量擴大，**停在 audit** 並提出更小變更方案或重新核准範圍。
- 不做其他站或資料庫修改；不執行 Migration、快取清理或 Composer setup。

## 長期結構性改善（不是本批上線）

正式生產通常應使用 `--no-dev`，但**必須同時讓 Laravel 的 `packages.php`、`services.php` 與新 vendor 一致**，並將兩者的更動納入程式交易備份、相容性測試及回復。這會改動目前受保護的 `bootstrap/cache`，需要獨立風險評估與授權，不能靠「重試」或直接刪快取。
