# V2 統一驗收候選版（Draft）

## 來源與邊界

以實際 main `5c5b533e9825d474743e1ed5e590c1410446fe7f` 建立獨立分支
`codex/v2-integrated-acceptance`，接入 PR104 `1812c3a` 與 PR58 `ca75545`。
PR104 已包含 PR103 `2f079a7`，未重複套用。PR102 `119ed9b` 不在候選版
來源 ancestry，未加入其管理包差異；main 既有受控工具保持原狀。
四個來源分支保留，未 force push、合併 main 或部署。

AGENTS 自動合併後逐項保留自主開發、舊版設計、安全邊界及隔離測試例外。
稿件與評鑑路由均在 secure host / auth:ppb / active 邊界內；稿件仍須
schedules.manage，評鑑分 evaluations.view/manage。舊入口 redirect 也須管理權限。
沒有文字衝突；上述語意及 release manifest 才是本批實際整合檢查重點。

PR58 的 release.json 原為 migrate=true。本候選版維持 migrate=false；Migration
原碼保留供安全閘門審查，沒有在正式環境執行。Draft 綠燈不是發布／付款授權。

## 實際整合缺陷：正文變更未使評鑑來源失效

修復前真實 HTTP/MySQL 測試在 61 個斷言後失敗：分析師稿件退回修改，
更換正文再送審，EvaluationService 的 source_sha256 卻仍完全相同。
原 nativeRows 僅選作者、賽事、分類與日期；正文及 W2 狀態沒有參與來源。

現在以 MySQL5.7 SHA2(JSON_ARRAY(...),256) 綁定 status、submitted_at、
recommend_pick、analysis_text、content_body，只將雜湊加入 V2 來源指紋，
不把稿件正文匯出到評鑑報告或審計收據。內容雜湊僅綁實際投稿作者；
缺勤來源仍使用原本關聯，避免代班者改正文誤使原分析師的薪酬請求失效。
此相依邊界也先重現失敗，再限定雜湊範圍。既有最小 SQL fixture 補齊三個
正式 W2 既有 nullable 文字欄位，保留所有原斷言。

修復後：同一流程的新舊來源不同；使用舊來源保存薪酬回 409；歷史已保存
薪酬快照不變。沒有重寫評分、升降級、薪酬、出勤或代班的既有計算公式。

## 完整流程與分立版本契約

IntegratedWorkflowTest 使用實際 Laravel HTTP kernel 與隔離 MySQL5.7.44：

1. HTTP 新增排班 → HTTP 投稿 → HTTP 核稿 → HTTP 評鑑逐稿評分 → 薪酬計算
   → HTTP 保存快照 → 同 UUID 重跑只讀回原收據。
2. 只具排班權限者不得評鑑；只具評鑑權限者不得投稿；未登入不能進入。
   分析師仍不是透過管理帳號自助投稿；測試用的是合法後台管理身分。
3. 已投稿後代班，submitted_analyst_id 與原稿保持不變；新負責人不能取得
   舊稿的評鑑歸屬。投稿前代班，實際投稿人取得稿件，原分析師得到正確缺勤
   關聯；未免除的代班稿維持既有薪酬語意，不能由原作者借用評分。
4. 評鑑評分不使無相依的 W2 revision／排班版本過期。代班後舊指派版本 409；
   W2 重送內容變動則使真正相依的評鑑 source hash 過期。
5. WP 待查 metadata 同時阻擋指派與稿件修改；不呼叫 WP。完整 PR104 的
   33 組雙 Session 回歸繼續涵蓋已發布／鎖號、取消、編號及競爭。
6. 評鑑審計故障回 500，evaluation/event 同交易回滾，交易層級歸零；修復後
   過期保存 409。取消不刪除已投稿評鑑來源或已保存薪酬歷史。
7. HTML 詳情、JSON summary 與舊薪酬入口 redirect 都經實際 HTTP kernel 驗證。
   不以這些測試冒稱正式瀏覽器、舊版 UI 或正式服務驗收。

## 隔離與回歸證據

沿用 PR104 的 DisposableMysql57、固定 image digest、network=none、非 root
OS 身分、唯讀 rootfs、datadir/tmp/logs tmpfs、Docker logs=none、無持久 volume。
14 張共享 InnoDB fixture 與 session TEMPORARY 評鑑表只存在該容器；不新增
DATABASE。finally 與獨立 guardian 強制銷毀，驗證 container/data/IPC 不存在。
所有資料是合成資料，LegacyReader 只提供空的合成 profiles；任何其他私有讀取
呼叫都會使測試失敗。Http::preventStrayRequests，沒有正式 WP/LINE/業務呼叫。

完整回歸包含 PR58 評鑑／薪酬、PR103 稿件、PR104 排班／稿件併發，
以及本批 IntegratedWorkflowTest。本機完整回歸59項／1369斷言PASS；
精確 Commit、三組 CI 與下載收據放在候選 PR。
CI artifact 同時收錄 ppb-integrated-receipt.json 與 ppb-concurrency-receipt.json，
只含非機密驗收 metadata；不能把未取得的遠端收據標為成功。

## 下一階段條件

可進入候選版程式審查與隔離功能驗收：月評鑑、跨月份、薪酬快照、後台稿件、
排班交易與上述完整管理流程。正式 Migration、資料對帳、合併／發布與真實
HTTP 驗收仍須各自既有安全閘門。

尚未完成的既定流程：
- 舊版 UI PC／手機／Modal 還原：等待既有 Phase A 真實成功收據及安全來源。
  不重包管理工具、不重測 AccessDenied、不猜 UI。
- 分析師 LINE/LIFF：仍缺私有已驗證身分委派，不建立假登入或裸 ID API。
- 原始 PDF 產製／下載／傳送：仍需原模板與私有來源／發送契約；CSV／列印
  不等於原 PDF，未執行任何產製或發送。
- WP 私有發布整合及正式部署：沿用原安全閘門，本批沒有啟用或呼叫。

以上外部依賴只阻擋對應項目；本批已完成可由現行原碼確認的整合缺陷修復，
不為等待而重複開發工具，也沒有宣稱建立背景自主執行器。
