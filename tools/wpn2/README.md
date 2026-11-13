> 最新執行方式：GitHub Actions 自動透過既有私人 S3／AWS SSM 傳遞與執行此套件，結果自動回收到 Actions。使用者不需登入 EC2，也不建立 Codex CLI Runner。接入與一次性 AWS 權限見 ops/wp-test/README.md、REMOTE_SETUP.md。下列單檔說明描述套件本身；正式入口為 Actions。

# WPN2：獨立建立一筆 Codex測試 Expert 草稿

這是使用者明確授權的新草稿測試工具，不會執行附件 WPF6 的更新入口。WPF6 的 README／run.sh 要求更新 #30436，是歷史附件指令，已由此次使用者要求取代。

## 原始機制與修正

- parser.php 沿用附件原始檔；legacy.php 從 wpf6.php 直接保留 sourceDef、legacyPDO、profiles、payload、媒體、日期及欄位比對函式。原始附件 SHA 與各檔 SHA 見 ORIGIN.json。
- 同一舊 WordPressApiController.class.php、同一 SHA 驗證、同一 WP_SITE / WP_USER / WP_APP_PWD token 解析、同一 Basic Auth、同一 /wp-json/wp/v2/expert 與 /wp-json/acf/v3/expert/{id}、同一 {"fields":{...}}。不執行或 include 舊控制器。
- 同一舊 DB 設定 token 解析、唯讀 transaction、pay_analyst_profiles IDs 2–7；不啟動 Laravel，不連 V2 DB，不寫任何 DB。
- 43 個欄位從當前舊碼解析；五位分析師及 BONUS 使用真實來源姓名與附件 ID。所有包含「推薦」的欄位一律為「Codex測試」，包含五個推薦、投注、短文、BONUS 投注與短文及合盤推薦。
- 六個來源 author_id 都須通過 WP user GET；Expert 是六人的合稿，核心 author 明確設為 /users/me 回傳的實際發布帳號，符合原本 authenticated Expert create 的預設作者語意。不是將六人都假定成同一個 author_id，也不建立長文 posts。
- 六張照片先 GET media 驗證實際 image ID；讀回 ACF 支援 ID、image object 或 WP 自己回傳的圖片 URL／檔案路徑比對。日期保留 WPF6 中文上午／下午解析；若 WP 格式不顯示年份，報告明確標示 year_not_independently_exposed，不能視為獨立驗證了年份。
- 對 #30436 僅做唯讀 GET 診斷與可用時的前後比對，不要求它是 draft、不做任何寫入；不要求 WPF5/WPF6/WPN1 的舊報告與授權檔。寫入防護只允許固定標記的新建請求與 journal 所屬新 ID 的 ACF。

## 防重複與恢復

固定 slug：ppb-codex-issue12-new-expert-v1。固定 content marker：PPB-CODEX-ISSUE12-NEW-EXPERT-V1。標題為 Codex測試 2026-11-13。賽事靜態欄位優先採用可完整確認年份的目前 reference；否則重用附件原始 WPF6 測試資料（不是虛構正式賽事），報告記錄來源。

使用 /var/tmp/ppb-codex-issue12-new-expert-v1 的私有目錄與 flock。journal.json 從 PREFLIGHT 就落盤保存，失敗記錄 last_stop_code；在第一次 create POST 前 fsync 並 rename 落盤。建立請求一生只送一次；回應遺失或程序中止後，重跑只查固定 slug，不再 create。找到一筆且 ID、type、draft、slug、title、content marker、author 全相符才接續 ACF。無 journal 但已存在標記則停止；已寫 create intent 卻找不到唯一結果亦停止，絕不猜測或重建。不要刪除 journal 以強制重試。

ACF 讀回不一致時可以重送同一新稿的完全相同 fields；不會增加文章。最後重新 GET core、43 ACF、唯一 slug 三者全部符合，才輸出真實新 ID 與後台網址。再次執行已通過版本只驗證，不新建第二筆。

## 單一執行檔

交付的 ppb_wpn2_verified.sh 內嵌本套件及雜湊。由 Actions 上傳既有私人 S3，固定 SSM 文件下載、驗證完整 SHA 後執行。它在 /var/tmp 建立暫存套件，驗證 SHA，使用現有 /www/server/php/84/bin/php，跑離線回歸後執行真實 WP 流程。依賴現場既有 python3、sha256sum、PHP curl/pdo_mysql/posix/ctype/tokenizer；不安裝或修改伺服器設定，不修改正式應用程式。程式會自行讀取現有來源與授權，不輸出授權值。

永久結果位置：
- /var/tmp/ppb-codex-issue12-new-expert-v1/journal.json
- /var/tmp/ppb-codex-issue12-new-expert-v1/report.json

預檢就建立 journal，即使 WP 尚未寫入也會留下明確的 PREFLIGHT 狀態。停止時 report.json 包含階段相關 HTTP 狀態、curl errno、WP REST code、媒體證據與欄位差異，不包含密碼或 Basic Auth。新稿建立後若後續驗證失敗，保留該稿與 journal 供安全接續，不刪稿、不建立另一筆。

## 已完成及未完成的驗證

Cloud PHP 8.4.24：所有 PHP 語法通過、原 WPF6 日期／媒體 selftest 通過、WPN2 完整離線 orchestration 回歸通過、bash 語法通過。

回歸涵蓋：43 欄、六推薦、重跑無新增／無多餘 ACF 寫入、create 回應遺失、durable intent 重啟、來源變更阻擋、無 journal 的同標記草稿阻擋、不確定 create 結果不得重建、錯誤 ACF 讀回不得成功、#30436 與 posts 寫入防護。測試中的 WP 回應皆為 fixture，不能當作 WP 實測證據。

本次 Cloud 沒有 EC2 SSH／AWS／WP 憑證；對 https://csdatw.com 的實際 curl 遭 Cloud proxy CONNECT 403（不是 WP 回應）。gh API 查詢受阻，但已透過 GitHub 連接器讀取 Issue #12、全部留言與 PR #13 diff／討論；保留 P2 的 attachment ID 寫入原則。當前 checkout 未包含 WPN1；其 PRECHECK 與 journal 缺陷依最新現場回報核對。沒有取得目前 WP API 內容。

尚無實際新 Expert ID；尚未完成真實 WP 測試。只有 EC2 執行後 report.json 的 wp_test_success=true 及真實 new_expert_id 才構成該測試證據。

## 回復範圍

不修改既有應用、DB、LINE、Cron 或舊 WP 文章。交付執行會留下恰一筆新的測試草稿；不提供自動刪除或回復寫入。若測試中斷，不要移除 journal；重跑同一執行檔即可查驗／接續。
