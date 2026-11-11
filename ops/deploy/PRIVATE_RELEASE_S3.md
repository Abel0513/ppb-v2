# PPB V2 第 45 步：GitHub → 私人 S3 版本包（不部署）

本批新加 `.github/workflows/ppb-v2-release-package.yml`，**只允許 main 分支手動觸發**。產生固定 commit 的 PHP 程式與 `composer.lock` 對應的正式 `vendor/`，經離線安全檢查，再使用 OIDC 短期憑證上傳私人 S3。**不接觸正式 EC2、不修改網站、不連資料庫、不執行 migration。**

## 前置狀態

- GitHub repo: `Abel0513/ppb-v2`，Private，`main`。
- S3 bucket: `ppb-v2-release-artifacts-429724121147-use1`（us-east-1），使用者已建立。
- `PPB-V2-GitHub-Deploy-Role` 附加 `PPB-V2-GitHub-S3-Upload`：限 `s3:PutObject` 到 `releases/*`；既有 SSM 權限不變。
- EC2 同名下載政策於後續批次使用；本次不會透過 SSM 執行任何命令。

## 版本包規則

1. Checkout `main` 當次 SHA。GitHub runner 安裝 PHP 8.4 / Composer，執行 `composer install --no-dev --no-scripts --no-plugins`；禁止 `composer setup` 及 migrate。
2. 包含允許的 Git 追蹤程式檔（`app/ bootstrap/ config/ database/ lang/ public/ resources/ routes/`、`artisan`、Composer 鎖檔）與安裝好的正式 `vendor/`。不包含 `.env`、`storage/`、`public/.well-known/`、秘密檔案或私人資料。
3. 包含 `.ppb-release.json` 的 Git commit 對應資訊，產生 deterministic tar.gz，執行既有 `check-release.py` 與全部離線測試。
4. S3 key: `releases/<commit-sha>/run-<run-id>-attempt-<attempt>.tar.gz`，SHA256 存 metadata 與 Job summary；使用 `If-None-Match: *` 阻止覆寫既有同名物件。
5. 本階段**不提供**伺服器部署、下載、切換、備份更新、回復或 Migration，不能只憑成功上傳就視為網站已更新。

## 已知界線及下一步

- 上傳成功由 Actions 日誌與 S3 物件清單驗證；不能在這個批次宣稱曾完成 AWS 實測。
- 如果安裝依賴、版本包驗證或 S3 PutObject 失敗，停止，不自行放寬 IAM 權限。
- `package-lock.json` 缺失、正式站沒有 Node/npm：本批不執行 npm 或 Vite，若未來 Laravel 畫面需要 `public/build`，必須先增加建置及驗收，不能用此包直接覆蓋。
- 未來正式部署需固定 SSM 文件、下載與 SHA256 驗證、檔案映射與保護、健康檢查、flock 防重入、獨立核准及程式回復。資料庫遷移必須另行核准；程式回復不等於資料庫回復。
- S3 物件可能依儲存、請求與歷史版本產生費用；設定 Lifecycle 前不要隨意刪除回復版本。
