# 分析師主檔候選欄位（2026-11-16）

分支基於 PR105 ea63a5d（含 main5c5b533）；不修改其原分支或驗收結論。
新增欄位 Migration 僅為候選碼。正式 Migration／部署／資料輸入均未執行，
release manifest 維持 migrate=false。原 PR58/102/103/104/105 全部保持 Draft。

## 來源、語意與實作

| 欄位 | 已取得的依據 | 本批 V2 保存與限制 |
| --- | --- | --- |
| color | main config/ppb_legacy_colors.php 的來源調色盤；安全來源 AdminScheduleController 的 analyst_profiles background/color 查詢 | nullable VARCHAR(7)，僅 #RRGGBB 文字色，不是背景資訊或任意 CSS |
| wp_photo | WPF6 已驗證讀取機制：PR13 原碼 ops/wp-test/interface/runtime/legacy.php::profiles（114bb0d），來源 WordPressApiController SHA 5b328e4d112f79bf332542d3c7cb747e41b1199f4eb16c164cbb3e6aedd6b344 | nullable unsigned BIGINT，正的附件 ID，不接受 URL；V2 精確整數上限 PHP_INT_MAX |
| author_id | 同一 WPF6 profiles 讀取／驗證；原來源允許 NULL／空值與明確 0 | nullable unsigned BIGINT，保留 0 與 NULL 的差異，不與照片或分析師 ID 合併 |
| line_id | 已核實舊 LiffController::getAnalystData 的 LINE ID→分析師關聯（docs/LEGACY_EVALUATION_MAP.md）；不等於 account_id | nullable opaque string，V2 儲存界線255字元；禁止控制字元；不推定提供者格式或唯一性，不構成身分驗證 |
| line_name | 已保存安全來源 AnalystRatingController 193／247、AdminScheduleController 3158／3297 等選取 line_name，與 analyst_name 並存 | nullable VARCHAR(255)，與 name/display_name 分離；同為私人欄位 |
| rating_level | 已核實 LegacyRatingCalculator 的 0..3 值域、AnalystRatingController 1167–1439 及 profile rating_level 讀取 | nullable unsigned TINYINT、整數0..3；只保存主檔資料，不覆蓋月份評級、計算公式或快照 |
| account_id | 現有 V2 migration 的 nullable unique FK／原 Controller 的角色檢查 | 維持選用；不以此欄登入或委派分析師身分 |
| name/display_name/user_type/status/internal_notes | 原 V2 Migration、Controller、表單及已驗收身份0/1/2/3規則 | 保留，未改名、未合併，不更動分析師 ID |

以上是 V2 明確的候選型別／驗證界線，不宣稱已取得舊 MySQL 全部欄位 DDL。
255字元是本批 V2 儲存界線，不是 LINE 提供者契約或舊欄位長度證明；過長輸入
拒絕而非截斷。失敗測試也重現Laravel一般integer規則會接受true為1；
本批整數識別／評級明確拒絕布林、小數，仍接受正常HTML整數字串。沒有搬入舊資料，也沒有遠端驗證 WP ID／LINE 關聯是否有效。

### 必須維持未完成的兩個欄位

- background：目前安全來源及調色盤實際將它當作 CSS 背景色，而本次需求描述為
  「分析師背景資訊」。沒有取得背景文字欄位的確切來源，不能把舊顏色改存履歷。
- analyst_level：安全來源有與0／1比較的顯示分支，但確切值域、SQL型別及標籤未
  完整證實（部分字串經遮蔽）。不能與 rating_level、user_type 合併或猜枚舉。

兩者未加 Migration、未宣稱完成；請求若帶這兩欄會422，避免靜默丟棄造成假成功。
下一批可直接接續的主檔工作是依正式來源或使用者明確決策補這兩個欄位，
不重做已完成六欄。只阻擋這兩部分，不阻擋本批其他功能。

## 權限、資料最小化及資料保持

- 原 schedules.manage 仍保護主檔建立／編輯。LINE 欄位另需既有 accounts.manage，
  沒有新增角色、IAM、API、登入服務或委派介面。
- 只有授權管理表單可看 LINE 資料；一般表單剔除其 model attributes/originals；
  列表明確只選公用管理欄位；Model hidden 防止一般 JSON 輸出；全域 dontFlash
  在保留原密碼排除規則下，排除 LINE 欄位的驗證失敗 session 輸入。
- 一般 audit 僅既有事件類型及主檔ID／操作者，不記姓名、LINE值、照片／作者值。
  實際失敗測試證明 SQL QueryException 會附帶私有 bindings；Controller 在交易
  回滾後僅保留安全 DB 錯誤碼，不串接原 QueryException／SQL／bindings。
- 更新省略選用欄位保留既有資料；明確 NULL 才清除。修復舊 account_id 等省略即
  清空的行為；原帳號角色、唯一性與 FK 檢查保留。帳號列表載入 username 修復
  有綁定仍顯示未綁定的問題。
- 主檔評級在 native evaluation subject metadata 讀實際值，NULL 不假填成2。
  原月份評級／薪酬來源與歷史快照算法保持原樣，沒有用主檔評級覆蓋月份歷史。
- 排班色取實際 currentAnalyst／reviewer 的保存資料；只允許安全十六進位 CSS。
  自訂文字色不借用數值ID碰巧相同的舊背景；未設定時保留既有調色盤行為。
  沒有改動原作者、代班歷史、稿件、WP紀錄、編號或任何 LINE 正式流程。
- Migration 僅新增 nullable 欄位，既有值不動。若任一同名欄位已存在先STOP，
  不覆寫未知型別／資料；down 明確拒絕自動丟欄，須經資料回復審查。

## 隔離驗收

沿用已核准 DisposableMysql57：固定5.7.44 digest、network=none、非root、唯讀
rootfs、datadir/tmp/logs tmpfs、無持久volume／正式掛載、guardian清理。
只在驗證的 disposable socket 套用候選 Migration；普通 InnoDB fixture、角色表
與 TEMPORARY 評鑑表都只存在此 RAM 容器；沒有 CREATE DATABASE。

新增 Model 與實際 HTTP/MySQL 回歸：建立／編輯／讀回、NULL/0/空值、驗證拒絕、
私有權限／輸出／session／audit、選用帳號、真實排班與投稿、主檔更新不改歷史
資料與版本憑證／評鑑指紋／薪酬結果、舊來源關聯保持及 Migration保留資料。
完整 PR105 與33組共享InnoDB併發回歸保留，不重新開發其功能。

本機完整62項／1504斷言PASS。Commit、三組 CI 與非機密驗收收據見對應 Draft PR。
沒有正式 WP／LINE／業務呼叫；不以本批候選測試冒稱全欄／正式驗收完成。
