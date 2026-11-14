# 評鑑舊版 UI 一致性｜2026-11-15

設計依據：PR #58 comment6883893790，整個評鑑模組及未另指定新設計的
V2 功能均沿用舊系統版面與操作，不以現有 V2 卡片當舊版依據。

**RESULT=BLOCKED_LEGACY_UI_SOURCE_NOT_VISIBLE。尚未改寫 UI，也未完成 UI 還原。**
此狀態與既有計算／薪酬快照／權限／來源指紋／冪等測試 PASS 並不矛盾。

## 現場來源證據

- Run43639195703（PR98）：8項 inventory PASS，取得前400個路徑。
  SSM df292103-fbf8-4468-9211-00e6963d3ea7 至
  ef9b8438-5c7d-4dbe-9da1-73b1319a0d0a。
- Run43639516343（PR99）：6項剩餘 inventory、2項搜尋 PASS，完整涵蓋
  工具宣告的684個可見來源；檔案及舊 DB 寫入0。
  SSM029e9a29-4cfc-4537-a03e-965270069acb（起始）、
  c877a7f7-0e6c-48fc-b896-85e3e758d601（尾頁）、
  894a6428-8a63-4b6b-a251-16ff352e88e4（analyststats）、
  922eb99c-f6e6-46ea-bbb0-86f7fb814d75（stylesheet）。
- 完整可見清單沒有評鑑模板或CSS/JS。19個HTML路徑均為各模組的
  index.html；不能把這些檔案直接認定為評鑑View。未呼叫舊PHP頁面。
- 工具inventory只遍歷既有允許根目錄，沒有Public靜態資產根目錄。
  清單沒有項目不代表EC2其他路徑沒有模板／資產，不可猜出實際根目錄。
- source-tokens.php明確刪除T_INLINE_HTML。以含表格／CSS／按鈕／JS的
  靜態來源重現時輸出0bytes；Controller模板名稱／UI字串亦可能被遮蔽。
  既有source模式不具備完整UI查核能力，不能以此證明配色／Modal／欄序。

## 逐頁對照（只列有Controller證據者）

舊路徑皆相對 `/www/wwwroot/3.231.229.26`。
V2路徑皆相對本Repository。View與asset欄的「未取得」不是預設命名。

| 舊Action／可信位置 | 舊View／CSS／JS | V2目前Blade／路由 | 差異及狀態 |
| --- | --- | --- | --- |
| Application/Admin/Controller/AnalystRatingController.class.php:82 index | 未取得，display參數被遮蔽 | evaluations/index.blade.php；/admin/evaluations | 自訂列表尚未還原；欄序／配色／月份列未驗 |
| 同上:18 salary | 未取得，display參數被遮蔽 | evaluations/show.blade.php薪酬區；舊salary別名導向統一index | 舊版獨立頁被合併；不能視為操作等效 |
| 同上:50 rating | 未取得，display參數被遮蔽 | evaluations/show.blade.php等級與歷史區；舊rating別名導向統一index | 三月／升降級呈現與舊版未比對 |
| Application/Admin/Controller/AdminScheduleController.class.php:2173 getRating／2226 saveRating | 未取得評分Modal／JS | evaluations/show.blade.php逐稿評分表單 | 已有V2安全保存，Modal及互動不一致待還原 |
| 同上:2377 analyststats | 未取得實際View；不得執行該舊GET，可能寫資料 | evaluations/show.blade.php評鑑與人工互動 | 週互動、主動性、調整與提示布局未驗 |
| 同上:3618 display('AdminSchedule:analyststats_liff') | 僅證實View識別字，未證實實體檔路徑 | 尚無已驗收LINE／LIFF評鑑入口 | 不以後台帳號或裸ID代替身分 |
| Application/Admin/Controller/AnalystPerformanceController.class.php:9 index | 未取得；display()依模板設定解析 | evaluations/show.blade.php賽果區；舊performance別名導向index | 月表現／近十場／BONUS原頁面布局未驗 |
| Application/WordPress/Controller/LaborRewardPdfController.class.php:730 savePdf／757 saveAllPdf／36 sendSalaryPdfCard／67 sendAllSalaryPdfCards | 已讀Controller產製結構，按鈕所在View／JS未取得 | 目前CSV／列印；完整PDF入口未完成 | 不以列印冒稱原PDF，不執行舊產製或發送 |
| 出勤／影片／歷史附屬互動 | 舊View／CSS／JS未取得 | evaluations/show.blade.php輸入區 | 保留現有資料及稽核，不能杜撰為舊版布局 |
| 唯讀預覽（V2新增安全範圍） | 不宣稱舊版有此頁 | evaluations/preview/index.blade.php、show.blade.php | 保留已部署入口／health，尚未套用未經來源證實的樣式 |

## 取得來源前不應進行的修改

不猜模板檔名，不把自訂卡片換另一種自訂表格，不為手機另創布局，
不移除CSRF／escaping／permission／來源與保存收據，不執行舊SQL／PHP／發送。
不取消固定讀取器敏感資料過濾，也不以其他查詢通道轉存被遮蔽內容。

需要的是在既有隔離身分下、經審核的UI來源可見範圍：先發現真實模板／
資產目錄，再安全取得View/CSS/JS及引用關係。這不是DBA、IAM或R2重裝；
也不能直接重跑可能覆蓋現場FPM/policy的bootstrap。現有介面尚不能完成。

正式PC／手機逐頁外觀與互動驗收仍未執行。PR58保持Draft；不Migration、
正式寫入、付款、LINE／PDF發送、合併或部署。
