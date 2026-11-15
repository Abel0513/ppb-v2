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

## 實際可執行的驗證

`ScheduleConsistencyTest` 是實際Laravel route／Blade／Controller及MySQL5.7測試，
使用network=none、tmpfs、session TEMPORARY tables，不新增DB或持久表。

| 項目 | 已取得證據 | 尚缺證據 |
| --- | --- | --- |
| 稿件保存／代班 | 鎖序trace是game先於assignment；代班後稿件及原作者逐欄不變 | 雙Session對相同業務列交錯 |
| 狀態／取消與新請求 | 取消後代班／恢復active被拒；已發布取消保留G/E | 雙Session交錯提交 |
| 已提交指派 | syncSlots拒絕替換，連先寫入的game metadata也回滾 | 雙Session競爭同列 |
| WP保護 | uploading／published／wp_locked_at均拒絕指派變更，零DML | 真實交錯，不呼叫正式WP |
| 兩位管理員過期操作 | 連續兩個HTTP請求／服務呼叫，舊代班、狀態及edit版本409；刷新後正常 | 同時阻塞與喚醒順序 |
| 原子回滾 | 代班／狀態審計失敗回滾；取消後順移審計失敗回滾所有game與event | 併發失敗情境 |
| 不必要拒絕 | 其他game變動不改本game版本；正常送審／退稿仍走PR103回歸 | 雙Session等待時間／死鎖測量 |

保留正式限流；大量測試中的獨立刪除／回滾情境用測試時鐘分隔，沒有降低正式限制。
既有ManuscriptAuthorizationTest只更新正常代班請求的必填版本欄位，其所有
已驗收授權、送審、核稿、歷史及回滾斷言保留。

## 雙Session驗收尚未完成：不可把CI綠燈當作全部併發PASS

MySQL5.7 session TEMPORARY TABLE僅建立者可見。測試實際用第二個PDO查詢
第一個Session的ppb_games，取得42S02／1146，證明兩者不是共享業務列。
這是可見範圍證據，**不是雙Session交易競爭驗收**。

目前仍遵守使用者「不新增測試DB或持久測試表」的嚴格解讀。已提出一次釐清：
是否允許僅在network=none、datadir=tmpfs、測後強制銷毀容器內建立可共享InnoDB表，
不新增DB、不連正式DB、不留下表／資料。未取得答案前不建立或執行此類測試。

若嚴格只允許session TEMPORARY，無法完成兩個Session存取相同業務列的驗收；
不得用兩份不同資料、重用同一MySQL連線或一般鎖範例冒充。
既有單Session測試仍持續執行，但共享列交錯、死鎖及等待時間的結論維持待驗。

不Migration、不合併、不部署、不執行正式業務寫入／WP發布／LINE發送；
不重做AWS、DBA或R1/R2。稿件管理仍為明確後台權限，LINE委派未啟用。
