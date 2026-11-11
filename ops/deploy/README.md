# 皮皮寶 V2 — 受控部署安全檢查（第 42 步）

**階段：唯讀／離線安全檢查，尚未提供部署功能。**

## 已驗收的環境基準（2026-11-12）

- EC2: us-east-1 / i-0135d6b2fac7a54c2
- GitHub: Abel0513/ppb-v2，main；GitHub OIDC → SSM 唯讀測試通過
- 正式 Laravel 根目錄：/www/wwwroot/ppbaos.com（Nginx 指向其 public）
- 私有部署工作區：/www/ppb-v2-deploy（root:root 700）
- 已建立快照：/www/ppb-v2-deploy/backups/baseline-20261111T214207Z（由使用者回報驗收成功）
- 正式站 PHP 8.4.27，Composer 已安裝，Node/npm 未安裝，vendor 現存；不得使用 composer setup（含 migrate）
- 現有網站 /public/.well-known 與 /public/assets、正式 .env、storage 必須保護
- 不增加測試資料庫、不建立持久測試表；舊系統所有資料禁止寫入

## 本階段程式

- \`check-release.py ARCHIVE.tar.gz\`：對 **tar.gz 檔案本身唯讀**驗證必要檔案、最大尺寸、路徑穿越、符號連結／特殊檔案／敏感路徑。**不解壓、不修改檔案、不讀資料庫**。
- \`check-live.sh\`：檢查正式目錄、私有部署工作區、基準快照是否存在且符合必要限制。僅讀取，不建立、不更新任何正式檔案。
- \`tests/test_release_guard.py\`：使用 Python 暫存資料夾進行離線測試，測試完成自動移除；沒有 DB、AWS 或 WP／LINE 呼叫。
- GitHub workflow：PR 及手動觸發的離線測試，不要求 OIDC，不觸發 SSM。

## 本階段測試

在具有 Python 3.10+ 的開發機／GitHub runner 執行：

\`\`\`bash
bash -n ops/deploy/check-live.sh
python3 -m unittest discover -s ops/deploy/tests -p 'test_*.py' -v
\`\`\`

最終部署流程**尚未**實作。本階段測試通過不代表可直接進行正式部署。沒有自動同步或回復動作，不能把只讀 guard 當成完整安全部署工具。

## 進入下一批次前必須完成

1. 設計並驗證內容可重現、已鎖定 SHA256 的版本包（包含所需 PHP vendor、必要前端產物），避免未經核准的遠端腳本。
2. S3 私有儲存桶及區分 GitHub 上傳／EC2 下載的最小 IAM 權限；不能因使用 SSM 而賦予任意 shell 權限。
3. 正式部署 SSM 文件須固定腳本、拒絕任意命令參數；腳本保護 live .env、storage、公用資料，並用 flock 防止併發；版本切換前後有健康檢查。
4. 真正啟用覆蓋或刪除前，需處理新增／刪除程式檔同步語意、部署中斷狀態、檔案權限與原子性、操作日誌、回滾演練；程式回退不能自動回滾 DB。
5. 避免將 .env、DB 備份、Token、憑證上傳 GitHub／S3。正式部署需與人工核准程序分離。
