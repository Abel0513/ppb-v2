> 待核准候選版本：實測 Expert CPT 不公開 author。此 PR 新增依 authenticated type capabilities 判斷的修正與安全診斷；執行套件 SHA 已改變，尚未在 EC2 執行。既有 SSM version 1 與原核准套件維持不變。詳見 CANDIDATE_APPROVAL.md；不得把候選 CI 通過視為真實 WP 驗收。

# WordPress Expert 自動遠端驗收（PR #13）

最新授權：Codex Cloud 完成開發並推送 `codex/issue-12-wp-draft` 後，GitHub Actions 自動以 OIDC → 私人 S3 → 固定 AWS SSM 文件 → EC2 執行 WPN2，再回收真實 WP 結果。使用者不用登入 EC2，不安裝 Codex CLI Runner，也不需要重建 WordPress 授權／evidence 檔。

## 執行路徑

1. `.github/workflows/ppb-v2-wp-draft-test.yml` 在指定分支 push 後跑離線檢查，通過後自動啟動 `execute`。PR/fork 事件僅測試，不取得 AWS 身分。main 或同一分支另可 workflow_dispatch，明確填 CREATE-ONE-EXPERT-DRAFT。
2. OIDC 沿用帳號 `429724121147` 既有 provider。WP 專用 role `PPB-V2-GitHub-WP-Draft-Test-Role` 只信任此 repo 的 main／固定開發分支；不使用有正式部署能力的 Deploy-Role，不依賴新 protected environment。
3. `remote.py` 核對 AWS account、已安裝固定文件的內容與此 commit 完全相符，固定 DocumentVersion + AWS Hash 呼叫 SSM；文件內另有管理者核准的套件 SHA 常數及單一 allowedValues，分支不能自選新程式的 SHA 來取得 root 執行。沒有人工維護版本／hash 的 GitHub vars。
4. 同一既有私人 bucket `ppb-v2-release-artifacts-429724121147-use1`，新增隔離前綴 `wp-test/<commit>/run-<id>-attempt-<attempt>.sh`。使用不可覆寫 PUT、AES256 與本機完整 SHA256。
5. SSM `PPB-V2-WP-Draft-Test` 只接受 Commit、PackageSha256、RunId、RunAttempt、固定 Approval；不接受 shell、URL、路徑或文章 ID。沿用 `aws:downloadContent`，下載到 `/www/ppb-v2-deploy/wp-test/ssm-incoming-<run>-<attempt>`，核對管理者鎖定的 SHA 後執行一次性 WPN2 套件。新 SHA 不能由呼叫者自行授權；舊的未鎖定 document versions 會在一次性設定時撤除。不安裝常駐 runner，不覆寫 Laravel／舊站。
6. WPN2 仍用舊 connector 的現場 WordPress Basic Auth／ACF v3 fields、唯讀六人 profiles 與媒體 ID。固定 `/var/tmp/ppb-codex-issue12-new-expert-v1/` journal + flock 跨 Run／attempt 防止第二筆 create；#30436 只讀，不以它的狀態阻擋新稿。
7. 固定 SSM collector 只輸出白名單驗收資料，驗證 report 的 commit／Run ID／attempt，不能把上次成功報告當成本次結果。原始 WP 回應、授權、DB 設定與 execution.log 都留 EC2 私人路徑，不上傳 GitHub。
8. Actions 自動輪詢指定 instance／Command ID 的完整 SSM 狀態，檢查 `expertDraftTest` 實際 plugin 的 ResponseCode／Output。前面下載失敗亦可辨識，不把 document-level Success 當成 WP Success。只送一次 SendCommand；不明結果不自動重送。

## 結果與驗收

每個真實遠端 Run 都會產生 Job Summary 與 `wp-acceptance-<Run ID>-<attempt>` Artifact（保留 7 天）：

- `remote-result.json`：Actions URL、commit、SSM Command ID、SSM 各步狀態、WP Expert ID、edit URL、43 ACF、六個推薦值、HTTP 狀態、#30436 可讀時的前後比對。
- `summary.md`：相同結果的簡表。

只有 SSM 實際 Success、執行結束碼 0、當次 correlated report 為 PASS、新 ID 不等於 30436、expert/draft、獨立 GET 200、43/43、六推薦 Codex測試都符合，遠端 job 才成功。OIDC／IAM／SSM／WP 任一失敗都 RESULT=STOP，沒有取得的 Command ID 或 Expert ID 一律 null，不捏造。

Cloud 無 AWS 憑證不再構成「請使用者登入 EC2」的理由；由真實 Actions 接入。如果 WP role／SSM／EC2 下載權限尚未設定，Actions 會把具體阻礙回收，管理設定見 [REMOTE_SETUP.md](REMOTE_SETUP.md)。不提供繞過 IAM 的通用 shell 權限。

## 一次性 AWS 管理設定

程式與 JSON 已隨 PR 提供：`admin-setup.py`、`iam/*.json`、`PPB-V2-WP-Draft-Test.ssm.json`。管理工具自行查出指定 EC2 目前的 instance profile／role，只加精確 S3 讀取政策，沿用原 SSM Agent／instance role。無須使用者逐項查環境，也不觸碰 EC2 應用或 WordPress。此工具需要已授權的 AWS 管理身分，不由一般 WP Actions role 自行擴權。

## 開發檢查

```sh
python3 -m unittest discover -s ops/wp-test/tests -v
php tools/wpn2/test.php
python3 tools/wpn2/build-package.py --check
python3 ops/wp-test/render-ssm.py --check
```

`ppb_wpn2_verified.sh` 現在是 Actions 傳遞給 SSM 的套件，不要求使用者手動上傳或執行。`runner.py` 保留先前 P2 修正的歷史來源與回歸測試；新的遠端文件與 workflow 不再呼叫它或要求其 private evidence／auth 檔。

## 保護與回復

不寫 legacy／V2 DB、LINE、Cron、G/E 鎖號，不執行 migration／部署。唯一 WP 寫入範圍是此任務新草稿及其 ACF；不自動 publish／刪稿。中斷時保留 journal，不刪除、不另建文章。後續 Run 只能核對／接續同一新稿。未知外掛 hook 的副作用無法由 REST 單獨證明。

要停用遠端接入，撤除 WP role 的固定 SSM SendCommand 權限或移除 workflow push 觸發；保留 journal 與測試草稿供驗收。不可藉清除 journal 重新建立測試文章。

## PHP／Python 契約與 root 風險修正

WPN2 在 run.php 明確計算 `media_verified_image_count`，初始值為 0；不依賴 legacy helper 的全域副作用。saveReport 是正式 CLI 與整合測試共用的落盤入口。跨語言測試執行真實 PHP（只有 WP HTTP 是 fixture），讀取其實際 JSON，再送入 Python collector 與 controller，不使用手寫成功報告來代替此契約。

root 權限仍由既有 SSM Agent 提供；本版限制它只能執行 AWS 管理者已審查的固定套件。管理工具先拒絕專用 role 的 SendCommand，再更新文件並撤除舊版本、讀回核對，最後才開放日常最小權限。分支無 IAM／SSM 更新權限，改寫套件或文件都不能自動取得新 root 程式的執行權限。審查細節與殘餘權限見 SECURITY_REVIEW.md。
