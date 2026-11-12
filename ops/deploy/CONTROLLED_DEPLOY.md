# 皮皮寶 V2：一次建置、往後手動一鍵核准部署

> 版本：2026-11-12。**本文件是待驗收的新流程，PR 合併本身不會觸發正式部署。** 此批只修改 GitHub 倉庫內的程式與工作流程。實際啟用需先安裝固定腳本、建立 SSM 文件、驗證 EC2 的本機 HTTPS 與完整備份，並再經使用者明確核准。

## 已完成的事實基準

- Repository：`Abel0513/ppb-v2` 私人主分支；AWS 區域 `us-east-1`，EC2 `i-0135d6b2fac7a54c2`。
- 現場 V2：`/www/wwwroot/ppbaos.com`，Nginx 網址 `https://v2.csdatw.xyz`；部署私人目錄 `/www/ppb-v2-deploy`（700）。
- 第一版 GitHub → 私人 S3 → SSM → EC2 版本包固定 SHA256 已在 AWS 通過。第 47 步候選目錄建立成功，6,648 個相同、6 個不同（全部 `vendor/composer` 自動載入／安裝資訊檔）、零缺少。
- 已存在私人基準快照 `/www/ppb-v2-deploy/backups/baseline-20261111T214207Z`，包含 `.snapshot-complete` 標記。保密資訊不得上傳 GitHub/S3。
- IAM：GitHub OIDC role 已有僅針對 `PPB-V2-Controlled-Deploy` 文件及指定 EC2 的 SSM SendCommand 權限；**此文件需額外建立，不能擅自擴權**。
- GitHub Free 私人 Repository 不提供 required reviewers，因此使用 `workflow_dispatch` 的明確確認字串，不能聲稱有雙人審批。

## 本批增加內容

- `ops/deploy/controlled-deploy.py`：僅能覆寫 Git 管理的 Laravel 程式範圍；驗證完整 SHA256、Git commit、tar 安全、候選逐檔雜湊；先做 PHP syntax check，逐項列出差異，再備份受影響的原檔至私人 transaction journal。HTTPS 透過本機 `127.0.0.1:443` + SNI + 原網域驗證。異常時回復本次更動，且下一次部署遇到未完成 journal 會停止。
- `ops/deploy/tests/test_controlled_deploy.py`：**TemporaryDirectory** 離線模擬，包含安全維護、失敗時回復、惡意 symlink、私有權限、SHA/commit 錯誤及未核准刪除。
- `ops/deploy/PPB-V2-Controlled-Deploy.ssm.json`：正式 SSM Command 文件（需要手動在 AWS 建立一次），只有固定 S3 key 組合、固定腳本、不接受任意 shell/URL。
- 原 `.github/workflows/ppb-v2-release-package.yml`：同一個工作流程的三種操作，預設 **package_only**；另有 **audit** 或 **deploy**，deploy 必須手動填 `DEPLOY-PPB-V2`；完成編譯、所有離線測試、S3 SHA256 上傳後，才會呼叫 SSM；部署結果寫入 GitHub Job Summary。無任何自動 `push` 部署。
- 現有 PR 測試 workflow 增加新腳本與 SSM 文件的基本語法檢查。

## 保護界線與已知限制（重要）

1. **不影響舊正式系統**：所有可寫入路徑被限定在 V2 `/www/wwwroot/ppbaos.com` 的管理程式檔與 `/www/ppb-v2-deploy` 私人工作區。不得操作 WP、LINE、其他站、MLB、NBA、Cron 或 Nginx 配置。
2. **不處理 DB**：不執行 migration、seeder、SQL、`artisan setup`。如版本依賴 DB schema 修改，需另外審核、整批執行、統一驗收；不得把程式回復誤認成 DB 回復。
3. **完全保留** 正式 `.env`、`storage/`、`bootstrap/cache/`、`public/.well-known/`、未納入版本包的現場檔案及既有 URL 路由。已存在 `public/assets/*` 如果與新版本不同，部署會停止並要求獨立檢查，避免無意覆蓋正式資源。
4. **檔案刪除預設禁止**：如果先前版本已追蹤、此次版本沒有，將停止，需另行核准精確刪除清單。不能假裝刪掉檔案不會影響既有流程。
5. **安全的檔案級更新，不是零停機的整站原子切換**：每個檔案用臨時檔 + `os.replace` 原子替換，整批檔案無法同時切換。此設計避免先修改寶塔/Nginx 的實體目錄結構，但大量檔案更新時可能短暫同時存在新舊程式；須先在受控環境演練並決定是否採 Laravel maintenance mode 或進階藍綠架構。**本批不聲稱達成零停機。**
6. **回復僅包括程式檔**：私人 transaction 包含每個被覆蓋或刪除的檔案及新增檔清單；可手動回復最近成功的交易，也會在執行期例外及 HTTP 健康檢查失敗時嘗試自動回復。若進程遭 SIGKILL、主機停電、硬碟損壞或 PHP-FPM 運行程序仍緩存舊碼，仍可能需要人工介入；本批不會擅自重啟 PHP-FPM 或動服務。
7. **健康檢查的界線**：目前只測本機 Nginx `https://v2.csdatw.xyz/` 的 HTTP 2xx／3xx 及狀態碼是否維持一致，**不等於完整分析師 LINE／LIFF、稿件發布、WP 寫入與管理後台的端到端測試**；這些流程要依既定 V2 驗收另設安全測試。
8. `PHP -l` 必須通過，且第一次部署有變更檔案與 snapshot 可以回復；現有六個 Composer 差異不代表正式切換已經被核准。
9. AWS SSM Agent 需支援 `interpolationType: ENV_VAR`（SSM Agent ≥3.3.2746.0）；舊版本會**停止執行**而不是使用可能遭注入的字串替換。

## 啟用（一次性，按順序）

1. PR 的所有離線 CI 通過，使用者核准並合併 **單一**整合 PR。PR 合併不部署。
2. 將固定可信版本的 `controlled-deploy.py`、既有 `stage-release.py`、`check-release.py` 安裝為 `/www/ppb-v2-deploy/scripts/` 下 root 擁有、不可供 web 使用者修改的檔案（安全通道人工部署**一次**即可）。**不要由正式 S3 程式包自動覆寫固定部署腳本**。
3. 在 AWS Systems Manager → Documents → Create document → Command，名稱固定 `PPB-V2-Controlled-Deploy`，JSON 貼上同名檔案內容。此文件不接受 shell 命令文字參數；沿用既有 IAM。
4. 先用寶塔終端機 **唯讀**驗證 EC2 的 SSM Agent 版本、`curl --resolve v2.csdatw.xyz:443:127.0.0.1 https://v2.csdatw.xyz/` 預期回應、private 目錄權限，並確認備份可復原。若健康檢查目前不返回 200/301/302/307/308，不得直接 deploy，應先處理而非放寬預設。
5. GitHub → Actions → `PPB V2 build, private S3 package and optional controlled delivery` → Run workflow → `operation=audit`：驗證新的固定 S3 下載、候選與 live 差異，不修改 live；審核輸出及異常。
6. **另外一次人工核准**，同一 Actions 工作流程選 `operation=deploy` 並填 `DEPLOY-PPB-V2`。GitHub Actions 會以當下 main 的 Git commit 建包、上傳私人 S3，SSM Download + SHA256 + PHP lint + snapshot + 套用 + 本機 HTTPS 健康檢查；失敗則嘗試程式回復。
7. 執行完統一驗收主要功能；確認無需 DB migration，無舊網站異動。往後更新只需 `PR 審查合併 → Actions 手動 deploy → 看結果`，不再重複 IAM／S3／SSM 人工配置。

## 最近成功部署的人工回復

只在使用者明確核准回復時，於 EC2 root 管理終端執行：

```bash
python3 /www/ppb-v2-deploy/scripts/controlled-deploy.py \
  --operation rollback --confirmation ROLLBACK-PPB-V2
```

只會回復最近一筆 transaction；若執行中斷，優先回復未完成 transaction。**不回復資料庫、WordPress、其他正式站**。須驗證復原後網站及既有功能。

## 後續已知待辦（不可隱藏）

- 補齊 V2 全部正式流程的唯讀、非持久性測試，特別是分析師 LINE/LIFF 及 WP Experts 關聯；不要強迫分析師登入後台。
- 評估正式更新是否需要真正零停機 A/B 釋出目錄切換，這需確認 Nginx、PHP-FPM 及寶塔 open_basedir 行為，不能未核實先改。
- S3 發布版版本保留期限及空間管理應另設合理 Lifecycle；**不能刪除唯一可回復版本**。
