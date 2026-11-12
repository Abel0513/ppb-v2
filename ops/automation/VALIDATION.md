# Issue 14 本批驗證（2026-11-13）

- 基準 main：2b03fcc6351b26513ccf6ead0b9e448c38a692be。
- 新增自動化／安全離線測試：29/29 PASS。
- 既有部署回歸：49 項，42 PASS、4 SKIP、3 ERROR。
  ERROR 全為 Cloud 找不到 PHP CLI（test_audit_does_not_write_live、
  test_deploy_and_manual_rollback_preserve_runtime、test_failed_post_health_auto_rolls_back）。
  既有 Actions runner 已有 setup-php，PR CI 可真實執行；本機未用 fake PHP 偽造通過。
- actionlint 1.7.7：三個新增／修改 workflow PASS（shellcheck 未啟用）。
- SSM 所有 shell 片段語法、固定 acceptance hash、JSON、git diff --check PASS。
- 測試覆蓋：issue permission/重複任務、fork CI、review bot/過期 SHA、共用 2 次修復上限、
  同 head 重放拒絕、PR13 排除、patch 真實 Git scope/mode、base moved、人工 merge gate、
  DB 變更拒絕自動 deploy、CI gate、獨立 EC2 結果 commit/失敗/timeout，未登入路由邊界。
- 尚未端到端執行：OpenAI action、PAT push 觸發 CI、原生 Codex Review 回調／自修、
  protected main、固定新版 SSM 安裝、人工 merge 後部署、EC2 baseline。
- 無 API key／PAT secret 可用性宣稱；設定缺少必須 STOP。
- WP／DB／LIFF 完整業務驗收 NOT_RUN；本批不執行 PR13。
- 正式 EC2 檔案、AWS 權限／文件、DB、WP、LINE、Cron、公開發布、部署變更全部 0。

GitHub 實際 CI（首個提交 bb30d38）：automation-tests 與既有離線 release guard 均 success。
執行紀錄：https://github.com/Abel0513/ppb-v2/actions/runs/43305971503 、https://github.com/Abel0513/ppb-v2/actions/runs/43305971107 。
已發出 PR15 @codex review，尚未收到完成結果；不視為 AI review PASS。

## 2026-11-13 ChatGPT 訂閱授權替代方案（待驗收）

- 這是 PR #15 合併後的最小改動，切換 `develop` 工作至受信任、專用 `ppb-codex-subscription` 自架 runner，以 `codex -c forced_login_method=chatgpt exec` 使用 ChatGPT 帳號。
- 移除 `openai/codex-action` 與 `OPENAI_API_KEY` Secret 需求；`CODEX_GITHUB_TOKEN` 仍是提交 PR 的限定 repo 憑證。
- 在 `PPB_SUBSCRIPTION_RUNNER_READY` 啟用前，不保留新 Issue 任務；Agent 工作仍不含 GitHub 寫入／AWS 憑證。
- 新增訂閱模式 workflow 靜態回歸測試；完整 GitHub CI 結果以實際 PR Actions 為準。
- 尚未進行受限 runner 的安裝、Codex ChatGPT 登入、Issue 實跑或 EC2 真實發布；沒有任何正式資料寫入。
