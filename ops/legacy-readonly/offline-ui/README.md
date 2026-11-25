# Phase B：離線、逐檔審核的 UI 結構解析候選

狀態：僅本機設計與合成輸入測試。沒有正式來源 catalog，沒有遠端操作，
沒有加入 build-bootstrap.py 固定工具包。不得將解析 PASS 當作現場 UI 還原或安裝成功。

## 授權邊界

`parse_reviewed(path, source)` 只處理呼叫者提供的字串，不開檔、不連網、
不查 DB、不執行模板。正式 `PRODUCTION_CATALOG` 刻意為空，未知路徑直接拒絕。
未來 catalog 必須是經審核固定資料，不能由 Actions／請求參數帶入。
每檔指定 kind、允許的靜態文案、DOM token／control、CSS selector及資產相對引用。
實體路徑與文案要等真實 ui_inventory 及來源審核，不以合成 fixture 補成正式允許清單。

未來遠端整合仍須沿用既有 OS 唯讀身分、無 sudo、固定根目錄、敏感檔排除、
symlink／realpath檢查、檔案大小與輸出上限。此模組不取代這些邊界。
目前 worker 對 ui_source 仍拒絕；本批不變更 IAM／SSM／既有 source tokenizer。

## 內部處理流程

1. 固定清單精確比對路徑及輸入大小（500000 bytes）。
2. 混合 PHP 的輸入必須先交給可信 tokenizer callback。`php-inline.php` 僅從stdin
   token_get_all，保留 T_INLINE_HTML；不 eval／include、沒有來源執行。
   其 stdout 是內部中間值，可能仍有私密靜態內容，**不得直接回傳、記錄或做遠端API**。
   原有 source-tokens.php 過濾不變。
3. ThinkPHP動態值只變成佔位節點；不回傳條件、變數、預設 input value。
4. HTML輸出允許的有序DOM；CSS輸出有限屬性／配色／media／宣告順序；JS只輸出
   已審核selector上的click等事件與Modal／collapse／tab或class操作。
5. 未知文案、屬性、語法或引用回傳 REVIEW_REQUIRED、固定錯誤代碼、空結構。
   不回傳原始片段；hash只計算安全結構，不計算私密來源，避免成為資料猜測oracle。

DOM深度32、節點2000、輸出65536 bytes、CSS深度4及JS動作數都有上限。
CSS url/import、任意JS、fetch/ajax/eval、動態模板字串不執行也不輸出。
資產只回傳審核過的相對引用，不抓取URL、讀圖或字型。

## 覆蓋限制與後續審核

這是可檢查的小型結構語法，不是PHP／ThinkPHP執行器、完整CSS或JavaScript解析器。
條件分支與動態渲染可能改變實際布局；保留DOM不等於保留全部執行語意。
未知樣式／字型／路由／互動不得靜默省略或假稱完整，必須依實際來源整批審核擴充，
再做PC／手機瀏覽器驗收。不要直接傳出原始HTML以求相容。

未來正式入口須攔截非預期例外，僅回傳固定非機密錯誤，不記錄source或例外原文；
不得直接將本機parser的例外／中間值接到SSM stdout。
正式整合、manifest／路徑及固定工具promotion仍需原有安全審查；本候選未授權安裝。

## 離線驗證

`python3 -m unittest discover -s ops/legacy-readonly/tests -p test_offline_ui_structure.py -v`

測試僅用合成內容，涵蓋DOM順序、動態值／私密輸入省略、CSS fallback及手機media、
Modal與class事件、PHP不執行、未知來源拒絕、敏感內容不回傳、hash非私密oracle、
大小／深度邊界，以及既有worker／工具包未啟用此能力。沒有使用舊業務資料或測試DB。
