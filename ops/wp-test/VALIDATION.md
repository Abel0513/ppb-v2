## 2026-11-13 OIDC 已通過後的真實 WP 失敗與候選修正

真實遠端 Run 43347305073 attempt 2 已通過 OIDC、S3、SSM；Command ID 4939edd6-c116-47b4-afb4-d91f69914995。WPN2 STOP=NEW_EXPERT_OWNERSHIP_NOT_VERIFIED。attempt 3 用同一 journal 續跑，Command ID 29d38152-76c1-4187-8d4c-84387603d1e3，同一 STOP。後續診斷 Run 43348944311 的 Command ID b7e8486b-8099-433a-95c0-bb59ab75abd6，亦為同一錯誤，未取得已驗證 Expert ID。

只讀 Run 43349393440 沒有執行 SSM，證明實際 GET 是 expert／publish 文章形狀，含 title／content／status，不含 author；同名 taxonomy 也共用 rest_base=expert，OPTIONS 最後顯示 term schema，但第一個 POST 是文章 endpoint（不含 author arg）。因此原驗證器無條件要求 author 必然失敗，不能由此宣稱真實 WP PASS。

候選修正增加 authenticated type-capabilities GET、嚴格作者支援判斷與標示、201／journal／receipt 綁定、失敗安全診斷。65 項本機回歸通過，含缺作者的支援／不支援兩種狀態、錯誤作者拒絕、不明建立回應拒絕。它改變執行 SHA，尚未獲 AWS 固定文件核准，沒有在 EC2 執行；目前仍無 43/43／六推薦真實結果。

## 2026-11-13 PHP → Python 契約與 root 執行限制

run.php 明確計算／持久化 media_verified_image_count，初始化為 0；Python 缺欄位或型態不符會回報明確 STOP，不能默認為六張。新增實際 PHP writer → JSON → Python collector → controller 整合測試，包含六張成功、五張照片失敗、欄位缺漏、推薦／43 欄／HTTP 不符與舊 attempt 拒絕。

SSM 以管理者核准之套件 SHA 作為 allowedValues 與 shell 內固定常數，不再接受分支自選 SHA 即以 root 執行新程式。測試實際執行固定 shell 的驗證段，證明「替換內容且提交自己的 SHA」及「假冒核准 SHA」都失敗；不執行 root／WP 程式。一次性管理工具先撤 SendCommand、更新／鎖定文件、撤除其他歷史版本、重新 GET 後才授權；失敗保留 Deny。政策只針對本 WP 文件，沒有更換 OIDC／S3／SSM 架構。

本機 WP suite 61 項通過（含真正跨語言測試）。完整 GitHub CI 與本次真實遠端 Run 尚待此修正推送後回收；測試 fixture 不構成 WP 實測證據。

## 2026-11-13 自動遠端接入批次

改用現有 GitHub OIDC provider、私人 S3 bucket 與固定 SSM 文件傳遞 WPN2；不依賴 EC2 安裝 runner.py／evidence.json／credentials.json，不要求使用者登入 EC2。一次性管理工具只設定 WP 專用 role、固定 document 與既有 instance role 的 S3 讀取前綴。

本機：Python 49 項通過（含原 P2 26 項、遠端 controller／collector／SSM／IAM 設定保護）；PHP WPN2 43 欄全流程與原日期媒體 selftest 通過；套件可重建、SSM JSON 可重建、shell 與 embedded Python 語法通過。測試 fixture 的 ID 不是真實 AWS 或 WP 證據。

本批尚待真實 Actions 接入回收。沒有取得的 Command ID／Expert ID 不得填值；後續結果記錄實際 Run 與失敗層級。

# 2026-11-13 離線驗證

基準 main：2b03fcc6351b26513ccf6ead0b9e448c38a692be。

- 新增 runner／SSM／workflow 測試：19/19 PASS。
- 既有 ops/deploy 測試：49 項，42 PASS、4 SKIP、3 ERROR。
  三項 ERROR 均為 subprocess 找不到 php：test_audit_does_not_write_live、
  test_deploy_and_manual_rollback_preserve_runtime、test_failed_post_health_auto_rolls_back。
  不以 mock php 假裝正式 PHP lint 成功；既有部署程式未更動。
- SSM shell 語法、JSON、workflow YAML parse 與 git diff whitespace 通過。
- 新增程式只使用 Python standard library，不啟動 Laravel／不讀 .env／無 DB 介面。
- 未執行 AWS、SSM、EC2 安裝、WordPress GET／POST、LINE 或 Cron。
- 沒有真實 43 欄及六身分 evidence 時，正式流程必須 STOP。
- 尚未驗證：EC2 Python／SSM agent、IAM 新 role、environment reviewer 設定、
  真實 ACF Field Keys／型態、WP media／作者對帳、draft hooks 副作用、WP 真實新增與讀回。
- runtime 可驗證 reference 與配置一致、43 欄完整讀回，但真實來源與 hooks 審查
  依賴人工核准的私有 evidence；本 PR 不虛構此證據。

正式 EC2 修改、WP 新建、V2／legacy DB 寫入、LINE、Cron、公開發布均為 0。

## 2026-11-13 P2 修正

- 既有 PR13 分支續修，未建立新 PR。
- 新增 Image Array、URL、media不存在、URL不符、缺 ID、額外image欄位與編輯URL測試。
- WP 離線測試目前 26/26 PASS；mock 不代表 WP 實際建立。
- Cloud provider=cloud，AWS身分/環境認證為空；無AWS CLI與可呼叫SSM工具，無EC2正式路徑。
- 直接終端模式只使用現場已存在的私有 evidence/auth，無自動安裝、無IAM/SSM變更。
- 真實 NEW_WP_EXPERT_ID、HTTP、43/43 本批尚未取得；不得宣稱完成實測。
