# Issue #14：V2 自動開發（ChatGPT Codex 訂閱授權版）

**本版本不使用 OpenAI Platform API Key 或 `openai/codex-action`。** Codex 執行於使用者以 ChatGPT 登入的固定、受信任 Codex CLI runner；仍受 ChatGPT 方案用量限制（可能耗盡、要求重新登入或補充點數）。不代表免費無上限。此版本尚未實際完成 runner 登入／Issue→PR 端到端驗收。

## 保留的既有流程

可信 GitHub 使用者為 open Issue 加 `codex-run` 標籤 → GitHub hosted `prepare` 驗證身分、GitHub 權限和限次任務 → **獨立自架 runner 的 `codex exec`（ChatGPT 帳號登入）** 修改 V2 source、測試 → 只產生 patch artifact → GitHub hosted `publish` 驗證 patch／SHA／路徑後用限定 repo 的 PAT 建立一個 PR → `@codex review` → 自動分析 CI／P0-P2 審查問題，最多兩次修正 → **人工 merge** → 沿用既有 S3／SSM／EC2 受控部署。

原 `ops/automation/control.py` 的白名單、任務保留紀錄、兩輪修復計數、PR 保護及人工合併條件都保持不變；`PPB_AUTO_DEPLOY` 預設不啟用，沒有真實部署驗收前不能設成 true。

## 為什麼需要一個常駐的受信任 runner？

Codex Cloud 不會自動接受 GitHub 的 `issues.labeled` webhook。GitHub-hosted runner 每次是臨時環境，不能安全地共用 ChatGPT 的持久登入。官方文件支援進階情境下 `codex exec` 重用 CLI 帳號登入，須自行維護受信任執行器與重新登入。這是**無另外 API 計費且保持 GitHub Issue 全自動觸發**所需的一次性基礎。

**禁止直接在正式 EC2 root 身分啟動 GitHub self-hosted runner。** 如使用既有 EC2，必須用另一個無 sudo 的 Linux 使用者，且以隔離容器或等效作業系統隔離排除舊網站程式／DB／WP 私有憑證；不能把正式 `/www/wwwroot`、`/www/ppb-v2-deploy`、root home、MySQL socket 或 Docker socket 掛進 worker。優先使用獨立、可信任的長開機器／VM 或現有電腦；電腦離線時 Issue 只會排隊。GitHub 官方指出自架 runner 在惡意程式下可能持久遭入侵，因此不可視為與 GitHub hosted runner 等效的沙盒。

## 一次性啟用（切勿把憑證貼在聊天或 GitHub）

1. 在獨立、受限的 Linux runner（x64）安裝 PHP 8.4 CLI、Composer v2、Codex CLI 與 GitHub Actions runner。可使用既有 EC2 的**完全隔離**工作環境，但不得由 root 執行 runner。安裝 runner 時依 GitHub Repository → **Settings → Actions → Runners → New self-hosted runner** 顯示的官方命令操作，並增加**唯一標籤** `ppb-codex-subscription`。此標籤只分配給可信任且已隔離的 runner。
2. 以**實際執行 GitHub runner 的同一個使用者**在該工作環境執行：
   ```sh
   codex login --device-auth
   codex -c forced_login_method=chatgpt login status
   ```
   在自己的瀏覽器完成 ChatGPT 登入，不要使用 `--with-api-key`。認證快取是高敏感憑證，需保持該使用者私有、在重開機後可用；不能加入 Git、Actions Secrets、CI artifact 或公開日誌。當 ChatGPT 工作階段失效時，需要重新登入一次。不要在自架 runner 上執行第三方／fork PR 的任意工作流程。
3. GitHub Repo → **Settings → Secrets and variables → Actions → Repository secrets**，只建立一項：`CODEX_GITHUB_TOKEN`。使用限定 `Abel0513/ppb-v2` 的 fine-grained PAT：Contents／Pull requests／Issues 為 Read and write；不要授予 Administration、Workflows、Actions、Merge bypass、AWS 或舊系統權限。**不需要建立 `OPENAI_API_KEY`。** 此 token 僅在 GitHub-hosted `publish` job 讀取，Codex 開發 job 不接觸。
4. GitHub Repo → Actions variables 先把 `PPB_SUBSCRIPTION_RUNNER_READY` 留空（或 `false`）。在固定 runner 已 Online、完成 ChatGPT 登入、PHP／Composer／CLI 可用且隔離邊界驗收後，才設成 `true`。新 Issue 若在此前貼 `codex-run`，會在 **reservation 前 STOP**。
5. 建立 `codex-run` label。建議保護 `main`（限制 push／force push，required checks 為 `Offline release guards (no DB, no AWS)`、`automation-tests`，維持人工 merge）；Codex GitHub Review 保留已有的 `@codex review` 呼叫方式，不必等待網站的自動審查偏好設定錯誤修復。
6. 實測流程：由 Repo write 使用者建立一個低風險 V2 Issue 並加 `codex-run`，確認 `prepare → develop → publish`、Codex PR、兩種 CI、自動 review；如果失敗，由 Actions/Issue 給出結果，不重設限次計數。**未實跑成功前不可宣稱已完全自動化。**
7. EC2 發版另外沿用已驗收的固定 S3／SSM 部署管道；新增功能基準驗收器 `ops/automation/accept-v2.py` 仍需人工安裝、升級同一 SSM 文件並設定 `PPB_DEPLOY_DOCUMENT_VERSION`、`PPB_DEPLOY_DOCUMENT_SHA256`。等上述與一次實際 EC2 baseline 都確認後，最後才設 `PPB_AUTO_DEPLOY=true`。**本次切換 Codex 認證不做 EC2 寫入或部署。**

## 安全與功能範圍

- agent 只能匯出 `app/routes/resources/tests/docs/database` 的安全文字程式差異；不能自動修改 workflows、ops、AGENTS、憑證、symlink 或 legacy。
- V2 程式與**確認屬於 V2 的**資料表在核准的批次內可修改，但 DB/schema 變更須另行審核；不能透過本工作流自動修改 legacy DB 或現有 WP 正式文章。
- 不建立測試 DB 或持久測試表，不對舊站／LINE／Cron 執行寫入。
- `codex exec` 使用 `workspace-write` 沙盒，開發階段網路操作與檔案權限仍受 worker 作業系統隔離；不能把 sandbox 當 root 保護。
- 同一 PR 的 CI＋重要 Codex Review 修復共用最多兩輪。使用者人工 merge 後，原發版 gate 決定能否自動部署；V2／WP／DB／LIFF 業務功能仍需真實驗收。
- 隨時可以將 `PPB_SUBSCRIPTION_RUNNER_READY=false` 停止新 AI 任務；關閉 `PPB_AUTO_DEPLOY` 停止自動部署；撤銷 PAT 或停用 runner 停止提交。保留已建立的 PR 與交易備份。
- **替代的零伺服器作法：** 若不想維護 runner，直接透過 Codex Cloud（ChatGPT 登入）輸入 Issue URL，Codex 可開發、測試、PR；但每個 Issue 仍需人工啟動一次，不能假稱 GitHub Label 自動啟動。AI Review、CI 與 Merge 後部署仍沿用相同系統。

## 歷史與驗收基準

先前 PR #15 使用 OpenAI Platform API Key 的程式已合併，現在只替換 Codex 執行區段。首次部署 Commit `2b03fcc6351b26513ccf6ead0b9e448c38a692be` 與合併 PR #15 `90d1bf99fe6978dda02662f4749bdc341931dd64` 是歷史證據，不代表本方案的新 runner 已實跑。PR #13 及 WP 43/43 歷史進度不更動。
