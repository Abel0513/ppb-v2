# W2 稿件授權與保存驗收（Draft，不是正式部署）

基準 main `5c5b533e9825d474743e1ed5e590c1410446fe7f`。
此批獨立稿件分支，不合併／改寫評鑑 PR58 或管理包 PR102。

## 身分邊界

| 主體 | 現有管理路由 | 投稿／修改／核稿權限來源 |
| --- | --- | --- |
| 未登入、停用、非 HTTPS／指定 host | 拒絕 | 不接受前端 analyst_id／LINE ID 或帳號關聯代替驗證 |
| 有 account_id 關聯、被指派或被列為核稿分析師的一般後台帳號 | 沒有 schedules.manage 時拒絕全部 W2 讀寫 | 關聯及數值 ID 不授予分析師或核稿人授權 |
| 具 schedules.manage 的啟用管理員 | 保留既有查看、管理編輯／送審、核准與退回 | 明確的後台管理權限，不是 LINE 身分；actor_id 記錄管理帳號、事件標示 backend_management |
| 未來 LINE／LIFF 分析師 | 此批不新增 API／登入服務，不透過管理帳號替代 | 私有、經驗證的委派尚未接入；目前沒有可用的分析師自助操作入口 |

所有原有 W2 管理路由仍在 HTTPS＋auth:ppb＋active 邊界內，另明列
schedules.manage；Controller 同時防守。不改 analyst.account_id 的可空性，
不增加 Model／Migration、不擷取或輸出 LINE 私有識別、Token 或舊設定。
管理員操作的 submitted_analyst_id 是經鎖定指派的稿件歸屬，不是聲稱該分析師
已登入；前端送來的 author／actor ID 不採用。保留原本管理能力，未新增代登入。

## 版本及歸屬

- 空白稿件也使用綁定指派的版本雜湊，廢止可跨代班使用的固定 new 憑證。
- 雜湊包含 assignment/game/kind/原與現任分析師/狀態／更新時間，以及稿件、
  送審、審閱及來源欄位；核稿表單也須帶 revision_token。
- 鎖定順序 game→assignment→submission；重新核對版本、當前指派、稿件歸屬。
  過期、重複或來源不一致的操作回422，不寫稿件或審計事件。
  WP保護查核使用目前鎖定讀取，避免沿用交易前段的一致性快照。
- 送審→退回→改稿→再送審後，舊核稿頁不能批准新的內容。
- status=legacy_imported、任何非NULL legacy_source_pick_id（含0）、WP已驗證／
  待對帳、取消及不一致稿件維持不可覆寫；即使知道當前版本雜湊也不能取得寫入權。
- 重複指派稿件不自動挑一筆核稿。稿件及審計同一交易，審計失敗整筆回滾。
- 字串0是既有驗證規則允許的內容，不能以 ?: 當成空字串清除。

## 測試證據及限制

`tests/Feature/ManuscriptAuthorizationTest.php` 經過實際路由、Controller及Blade，
使用既有 pinned MySQL5.7.44、network=none／tmpfs及session TEMPORARY tables。
不新增測試DB或持久表，不連正式DB、WP或LINE；憑證不輸出。

原始程式實測：account_id連結但無管理權限的帳號取得列表200（應403）；
字串0存成NULL（應保留）。另以舊版未檢查核稿版本／來源標記、空稿new憑證、
未檢查重複稿件的行為做回歸敏感度驗證，確認新案例會拒絕這些舊路徑。

涵蓋：管理權限不借用、偽造ID不生效、未登入／停用／host邊界、正常管理員
送審／退回／改稿／核准、版本過期、歷史／WP鎖、來源歸屬、正式代班入口與
舊表單失效、重複稿件、HTML escaping、0值保存、審計資料最小化與失敗回滾。
這些是隔離應用驗收，不宣稱完成 LINE 身分委派或正式 HTTP／WP 發布驗收。

PR保持Draft；不Migration、不合併、不部署，不變更原WP發布或舊業務流程。
