# Issue #14：可重複使用的 V2 自動開發

狀態：程式／離線驗證已實作；尚未配置 GitHub Secrets、啟動 AI 或呼叫 EC2。
基準 main：2b03fcc6351b26513ccf6ead0b9e448c38a692be。
PR #13 及 WP 43/43 歷史成果不變，不包含／重做 ops/wp-test。

## 實作流程

信任的 write/maintain/admin 使用者給 open Issue `codex-run` 標籤 → main 版 controller
驗證操作者權限 → 一次性 task reservation → 官方 openai/codex-action（固定 v1 SHA）
在無 GitHub 寫入／AWS／正式憑證的 hosted runner sandbox 寫碼及測試 → patch artifact →
全新 publisher job 重新查 main/PR head 是否相同、路徑／mode／大小檢查 → scoped PAT
推至 `codex/issue-N` 並建立單一 PR → 發出 `@codex review`，每次改碼重新請求 review。
原生 Automatic review 可另外開啟；本批不自製第二套審查模型。

CI 失敗：只接受指定兩個 workflow、同 repository、同 PR 當前 SHA；由 GitHub API
讀取失敗 job/step 和最多三個 bounded job logs，交由 Codex 重現／修復。
Review：只接受 `chatgpt-codex-connector[bot]` 當前 SHA 的 P0/P1/P2 問題。
CI/review **共用最多兩次修復**，由 github-actions[bot] 的 reservation comments 計數，
同 head 不重試；失敗／無 diff 也消耗一次。跨事件依分支 concurrency 序列化。
達上限留 STOP comment。所有 event task data 都是非可信輸入，不是額外授權。

只有人工 merge 且 merger 仍有 write 權限、main head 正確、兩個必要 CI 綠燈、
`PPB_AUTO_DEPLOY=true`，才進入原發版 workflow；固定 S3 bucket／role／SSM 文件不重建。
工作流／控制管道變更、DB／Composer 變更、自動範圍外檔案停止自動部署。
**本整合 PR 自身屬控制管道變更，即使開啟變數也不會自動部署。**

Agent 可修改 app/routes/resources/tests/docs/database（migration 只能提交審查）；
不能改 workflows、ops、AGENTS、憑證、symlink、binary 或 legacy 目錄。
控制管道功能需求仍由人工授權的 Codex 互動開發，避免 agent 自行擴權。
沒有自動 Merge API。必須用 branch protection 防止 PAT/使用者誤推 main；prompt 不是權限隔離。

## 唯一一次啟用清單（管理者在設定頁完成，不在聊天貼憑證）

本清單是一次性啟用，不是每個 Issue 重建服務。先審核合併此 PR，尚不 dispatch 或 deploy。

1. Repository Settings → Secrets and variables → Actions → Secrets：
   - `OPENAI_API_KEY`：OpenAI Platform 專案 API key。僅用官方 action；Platform API **另計用量費用**，不是 ChatGPT OAuth／訂閱 Token。請在 Platform 設 budget／告警。
   - `CODEX_GITHUB_TOKEN`：限定此 repository 的 fine-grained PAT，Contents read/write、
     Pull requests read/write、Issues read/write；不給 Workflows、Administration、Merge bypass。
     使用者須能在此 repo 建立 PR。publisher 獨立 job 才取得它。採 PAT 是因 GITHUB_TOKEN
     推送/開 PR 不會觸發大多數後續 CI event；若組織限制 PAT，需換既有 GitHub App token
     （可另行審查），不能悄悄改成 GITHUB_TOKEN 並宣稱鏈路有效。
2. GitHub 建立 `codex-run` label。建議保護 main：禁止直接 push／force push，禁止 PAT bypass，
   人工決定 Merge；required checks 為 `Offline release guards (no DB, no AWS)`、`automation-tests`。
   私有 repo 方案若不支援 protection/review 必須如實記錄限制；不可宣稱已有強制門檻。
   Codex GitHub integration 開啟 repository Automatic review，或確認 PAT 發出的 `@codex review`
   可呼叫既有 bot；bot username 必須與 controller 精確一致。
3. 一次性 EC2 接入：沿用既有管理管道，審核後只安裝本批固定驗收器：

```sh
# 從已核准 commit 的可信副本執行，先核對 sha256sum 與 PR：
install -o root -g root -m 600 ops/automation/accept-v2.py /www/ppb-v2-deploy/scripts/accept-v2.py
```

   原三個固定部署腳本、S3／IAM 不重建。SSM JSON 在 controlledRelease 寫入前驗證
   驗收器固定 SHA256，缺少或不同就停止，不會先部署才發現缺失。
   由管理者更新**同一個**文件一次、留下版本：

```sh
aws ssm update-document --region us-east-1 --name PPB-V2-Controlled-Deploy --document-version '$LATEST' --document-format JSON --content file://ops/deploy/PPB-V2-Controlled-Deploy.ssm.json
aws ssm describe-document --region us-east-1 --name PPB-V2-Controlled-Deploy --document-version '$LATEST' --query 'Document.{Version:DocumentVersion,Hash:Hash,HashType:HashType}'
```

   不必重建 role；若現有權限有 document version 額外限制，管理者審查差異才改，Codex 不執行 IAM 寫入。
4. Repository Actions Variables：設定 `PPB_DEPLOY_DOCUMENT_VERSION`、`PPB_DEPLOY_DOCUMENT_SHA256`
   為步驟 3 查到的數字版號與 Hash（HashType 必須 Sha256）。原手動 audit/deploy 也改用固定版本。
   先確認這兩項與 EC2 runner 已安裝；**最後才**設 `PPB_AUTO_DEPLOY=true`。
   此開關是未來人工合併的一次性持續部署授權；不是讓 AI 合併或跳過影響檢查。

下一個任務：owner/write 使用者建立 Issue 並加 codex-run（純 open 未貼標籤不會執行）。
先用小型 V2 程式變更跑通一次並查 Actions/PR；在真正成功之前不可稱全鏈驗收完成。
Secrets 未設定會在 reservation 前 STOP，可設定後 rerun。
若初始開發失敗後需重開任務，由管理者查明原因再刪除該 Issue 的 ppb-task-start reservation
並重貼標籤；不自動反覆花費 API。不得刪除 PR 的 repair reservations 重置兩輪上限。

## EC2 驗收的實際界線

新增 functionalAcceptance 是**同一 SSM command** 中的固定 plugin；Actions 獨立取得它的輸出，
要求正確 deployed commit、登入頁真實 HTML（username/password）、三個管理入口 guest 302
指向登入頁。透過 127.0.0.1 TLS + 正式 SNI 驗證，無 redirect follow、無外部 host，
無 SQL／Artisan／WP／LINE／Cron。會碰到現場 Laravel GET/session 行為；本批沒有執行。
這比 `/` HTTP 健康檢查多了路由／頁面驗證，但**不是登入後 CRUD／稿件發布／DB／LIFF 業務測試**。
後者必須有確認屬 V2 的測資與權限契約，擴充同一受限驗收管道，不能用任意 root shell 代替。
PR #13 的專用新 WP draft 驗收仍需另行合併與核准，本批不觸發、不重做。

原受控部署的備份、PHP lint、健康檢查失敗自動回復全部保留。
新增功能驗收失敗會把 Actions/PR 報告標為失敗，**不自動重送 deploy 或盲目 rollback**；
功能驗收在檔案交易之後，不能把失敗說成已回復。管理者依該 command／commit／transaction
查核後沿用既有 rollback 指令；檔案回復不等於 DB 回復。DB/schema 變更禁止走自動發版。

## 證據與停止方式

Issue/PR 留 Actions URL、commit、修復輪數與 review request；Actions summary 留 S3 SHA、SSM command、
部署 marker 及獨立 EC2 baseline。失敗 logs 由 workflow 自動擷取，使用者無需搬運終端日誌。
關閉 `PPB_AUTO_DEPLOY` 可停自動部署；移除 codex-run／停用 Issue workflow 可停新任務；
撤銷 PAT/API key 可關閉開發。保留既有部署備份，不清 journal，不更動正式資料。

離線命令：
```sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s ops/automation/tests -v
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s ops/deploy/tests -v
```
