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
