# 排班／代班／稿件整合安全（Draft）

來源：正式main5c5b533＋已驗收PR103的2f079a7。此分支疊在PR103上，
不覆寫其身分、稿件版本、WP保護及審計修復；不動評鑑PR58與管理包PR102。

## 實作與已知失敗

- 原代班先鎖assignment，再以一般讀取檢查game狀態；稿件與排班則先鎖game。
  現在代班／狀態操作統一game→assignment，使用已鎖定的game狀態。
  原分析師不改，代班歷程追加；既有稿件submitted_analyst_id／本文不改。
- 原代班／指派狀態端點沒有WP保護。實測WP uploading時代班302（應422），
  新版於修改前查目前鎖定publication及wp_locked_at，拒絕待查／已發布／鎖號。
  排班的「已發布賽事取消但保留編號」原規則不變。
- 原排班編輯、代班、指派狀態沒有用戶端版本前提。新增ScheduleVersion純狀態
  雜湊，綁定game與按ID排序的assignment，代班／狀態綁定assignment欄位。
  HTTP更新／刪除／取消及指派操作須revision_token，過期409，缺少422。
  憑證不是登入授權，不取代schedules.manage，不使用LINE ID或account_id。
- Game edit表單、取消／刪除表單有hidden版本；指派列有data-assignment-revision，
  供既有管理端呼叫使用。沒有新增登入API、頁面設計或操作按鈕。
  驗證錯誤後保留原表單版本，不把舊欄位自動配上新版本覆寫他人資料。
- ScheduleManager原scope→game→assignments鎖序及G/E插入／縮排演算法不變。
  現有服務內部呼叫可省略版本，使用傳入model的狀態作前提；所有HTTP入口必填。

## 真正共享 InnoDB 雙 Session 驗證（本批）

使用者已明確核准 PR104 的隔離共享表例外；不是正式資料庫建表授權。
`ConcurrentScheduleTest` 啟動固定 digest 的 MySQL5.7.44 新容器，
network=none、skip-networking、rootfs唯讀、非root OS身分；datadir、tmp、
MySQL logs全部tmpfs，Docker log driver=none，無持久volume、正式掛載或新DB。
唯一host bind是0700隨機目錄內的Unix socket/pid，不存資料列。
在Docker inspect及實際mount驗證後，才於既有mysql schema建立共享InnoDB表。

兩個獨立PHP/PDO worker回傳不同CONNECTION_ID，同時讀取同一隨機fixture標記。
透過stdin/stdout命令確認與SQL完成屏障安排順序；觀測INNODB_LOCK_WAITS
確認真正阻塞後才放行。輪詢僅等待IO/metadata可用，不能以sleep決定交易順序。
所有操作呼叫實際Controller／ScheduleManager；WP狀態只用本機metadata fixture
及既有VerifiedWpRecorder契約，不呼叫WP HTTP，不宣稱正式WP驗收。

### 修復前失敗與根因

| 實際重現 | 原結果 | 修復 |
| --- | --- | --- |
| 外層快照後另一Session新增尾端賽事，再append | 500／1062，重用已占用編號 | scope內以current locking read取得尾端編號 |
| 舊核稿憑證等待另一Session退稿再投稿 | 422，未符合過期409契約 | 鎖定目前稿件／指派後先驗版本，過期409 |
| 外層REPEATABLE READ快照之後新增WP待查，再取消 | 200且錯誤取消／釋出編號 | publication保護改current locking read |
| 外層快照之後新增稿件，再插入其前方 | 200且錯誤順移已投稿G/E | 稿件、代班、歷史保護改current locking read |
| 一個slot代班至另一個已完成slot的分析師，隨後恢復後者 | 302，形成同場同類型重複active | 恢復時查核目前有效指派 |
| 外層快照後其他slot代班至相同目標 | 302，重複active | 指派重複檢查改current locking read |

保留原作者、投稿本文、代班追加歷程、WP不可變鎖號及既有G/E演算法。
版本失效409與業務狀態拒絕422分開；沒有改變管理權限或LINE委派邊界。

### 交錯與回滾矩陣

- 投稿→代班：作者及已投稿本文保留；代班→舊投稿：409，無稿件寫入。
- 核稿→完成：合法順序成功；完成→舊核稿：409，不核准失效指派。
- 核稿→取消保留已核准歷史；取消→核稿422；雙人使用同一核稿憑證只成功一次。
- 實際有效指派憑證先取得，經Controller完成後恢復；同時競爭的舊核稿409。
- 取消→代班／投稿：422；待查／已發布metadata→代班／投稿：422。
- 反向投稿／代班先提交，WP metadata再提交：原作者／代班歷史保留，不假稱正式WP發布。
- 舊快照遇到重新投稿、待查、已投稿及重複目標：讀取目前鎖定狀態並拒絕。
- 同場不同slot競爭同一分析師：只能成功一個；恢復不得借用已被占用分析師。
- 同scope不同game更新序列化；取消順移後等候的舊game編輯409。
- 不同G/E scope在對方持有scope時仍可提交，避免全域不必要阻塞。
- 代班／投稿／指派狀態審計故障及取消縮排審計故障：全部同交易回滾，等待者重新讀取。
- 真實1205逾時：無半套資料／交易殘留；釋鎖後重新讀取的新請求成功。
- 刻意反向鎖序的引擎負向對照：1213、完整回滾；不是應用程式正式鎖序。
  應用路徑遵守scope（需要編號時）→game→assignment及其子資料。

### 清理與證據

獨立guardian擁有容器建立及清理；EOF、失敗、訊號與硬性期限都移除容器。
owner label防止清理其他容器。PHP finally/shutdown再驗證不存在，tmpfs資料釋放，
socket/pid目錄移除。另以實際共享sentinel表測試父程序失敗、SIGTERM、SIGKILL
及已確認啟動的短期限，逐一驗證容器與目錄不存在。

CI產出`shared-innodb-concurrency-receipt`，只有版本、隔離、Session ID、
交錯狀態、固定查詢標籤、回滾與銷毀結果；不含SQL本文、稿件、憑證或正式資料。
既有單Session回歸保留；本批本機完整結果16項／995斷言PASS。
共享表14張、交錯33組、異常清理4種；精確head CI及下載收據見本PR驗收結果。

不Migration、不合併、不部署、不執行正式業務寫入／WP發布／LINE發送；
不重做AWS、DBA或R1/R2。PR58/102/103/104保持Draft。
