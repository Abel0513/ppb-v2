## 2026-11-13 WPN2：沿用 WPF6，一個檔案直接執行

本次現場 WPN1 的 #30436 draft 前置條件與預檢不留 journal 已修正。
新入口為 `ops/wp-test/ppb_wpn2_verified.sh`，由 `tools/wpn2/` 可重建與審查。
它不依賴本頁下方舊 runner 的 evidence.json／credentials.json／approved.json、SSM 或 IAM。
只讀解析現場原始 connector 的現有授權與映射，不執行舊控制器或舊 WPF6 更新入口。

把此單一檔案下載並上傳到既有 `/www/wwwroot/ppbaos.com/` 後，唯一命令：

```sh
bash /www/wwwroot/ppbaos.com/ppb_wpn2_verified.sh
```

固定 journal 與報告在 `/var/tmp/ppb-codex-issue12-new-expert-v1/`。
journal 從 PREFLIGHT 就存在；create intent 寫入後絕不再送第二個 create。
#30436 僅唯讀診斷與可用時前後比對，任何狀態都不構成新稿的 draft 前置條件。
只有新稿自己必須是 expert／draft、43 欄及六推薦通過獨立 GET，才輸出新 ID 與編輯網址。
細節、驗證範圍與未實測限制見 `tools/wpn2/README.md`。下方是保留的先前 Python／SSM 方案，不是此次前置條件。

## 2026-11-13 更新：單次 EC2 終端優先

本次直接沿用既有授權，不以建立 SSM／IAM／新 Runner 為前置。
`runner.direct_main()` 可在記憶體執行同一組 POST／GET 邏輯，不安裝程式、不改 approved.json。
使用已存在的 `/www/ppb-v2-deploy/wp-test/evidence.json` 與 `credentials.json`；
若已存在 approved.json，仍核對 evidence 雜湊。不會自動創造真實 ACF／身份 evidence 或認證。
若現場不是此私有設定格式，必須先唯讀確認既有介面，不能推測其他程式的設定格式。

P2 已修正：`standard` 值作為讀回比對，圖片寫入值獨立轉為 attachment ID；
Image Array 讀取 ID/id，Image URL 另讀相同 reference 的 `acf_format=light` 取得 ID。
每個 ID 必須 GET media 確認 image attachment 與原 source_url 相符，不能由 URL 猜 ID。
六位分析師照片欄位自動處理；其餘圖片欄位須在已核准 evidence 的 `image_fields` 明列。
照片、作者、真實43欄或安全hooks證據缺失，全部在 POST/journal 前停止。

輸出新增 `WP_EDIT_URL`，只有實際新ID獨立讀回通過才產生。
既有 issue-12-state.json／flock 完全沿用，不另建每次執行的去重空間。
逾時／不明 journal 永不清除或自動重送。

# Issue 12：受控 Expert 草稿測試

本批只有離線開發。未安裝至 EC2、未建立 SSM／IAM、未呼叫 WP。
既有部署流程、Laravel、DB、LINE、Cron 均不變。

## 邊界

固定 runner 安裝在 `/www/ppb-v2-deploy/wp-test/runner.py`。SSM 不下載程式、
不接受 Shell／路徑／Post ID。只接受已安裝且人工核准的 Commit、runner SHA256 與固定確認字串。
`approved.json` 的 evidence SHA256 也必須一致。缺資料直接 STOP。

僅允許 collection POST draft；其他網路操作只有 GET。HTTPS 驗證開啟、拒絕重新導向。
30436 只讀，比對全部 ACF／作者／狀態，執行前後對完整回應做雜湊。
比較失敗不刪除、不修正任何 WP 內容，交由人工判斷。

`issue-12-state.json` 是全任務永久 journal，不是每個 GitHub run 一份。
POST 前原子寫入並 fsync，搭配 flock。HTTP 錯誤、逾時、當機後均不再 POST。
重跑已知 ID 只 GET；未知 ID 只按固定 slug 查詢並 STOP，候選不自動認領。
journal 遺失仍有 slug 預檢，但無法保證任意人工搬動／刪除 journal 與文章之後去重；
**不得清除 journal、改 slug、並行使用其他工具建立本測試**。

WP REST 無法證明所有外掛無副作用。啟用前必須人工查閱 Expert draft save hooks、
接收器及既有 ACF 證據，確認不會通知 LINE、寫入 legacy/V2 或觸發公開發布。
輸出 `UNEXPECTED_SIDE_EFFECTS=NOT_OBSERVABLE_FROM_REST`，不虛稱驗證了所有外部副作用。

## 離線測試

```sh
python3 -m unittest discover -s ops/wp-test/tests -v
python3 -m unittest discover -s ops/deploy/tests -v
```
測試的 example.invalid 與 Fixture 身分完全是離線資料，不能拿來當正式 evidence。

## 人工核准後的精確接入步驟（本 PR 不執行）

1. 審查／合併 PR 後選定 main Commit。由可信管理管道取得該 Commit 的 runner 與 SSM JSON，
   本機執行 `sha256sum ops/wp-test/runner.py`，記錄審核 hash。
2. 用既有 EC2 管理管道安裝獨立目錄（不要動 `/www/wwwroot/ppbaos.com`）：

```sh
install -d -m 700 -o root -g root /www/ppb-v2-deploy/wp-test
# 在人工下載並核對 commit 的原始碼目錄執行：
install -m 600 -o root -g root ops/wp-test/runner.py /www/ppb-v2-deploy/wp-test/runner.py
```

3. 在 EC2 安全設定目錄產生 `credentials.json`，root:root 600，結構為
   `{"authorization":"<伺服器既有 WP 授權 header>"}`。不得傳至 GitHub、SSM 參數或聊天。
   必須是能讀 reference、建立 Experts draft 的最小 WP 權限；不給公開發布／刪除權限。
4. 在相同目錄產生 `evidence.json`（root:root 600），**沒有可用的真實範例可自動填入**：
   - `origin`: 已核實 HTTPS WP origin，無 path／port／帳密。
   - `route`: 由 WP type metadata 確認的 `/wp-json/wp/v2/<rest_base>`，不可猜 plural。
   - `reference_id`: 30436；`author`: 真實作者整數 ID。
   - `acf`: GET 30436 的完整 43 欄標準格式值。
   - `fields`: 相同 43 個名稱，每項 `{ "key": "field_真實key", "type": "string|integer|boolean|array|object" }`。
     管理者須從已核實 ACF 定義逐一核對 Field Key／型態；REST reference 不一定暴露 field keys。
   - `analysts`: 依 pick1、pick2、pick3、pick4、pick5、bonus 排序六項，分別包含
     `slot, source_id, id_field, photo_field, name_field, recommendation_field, expected_id, expected_photo, expected_name`。
     source_id 是已核實 legacy ID；expected_id 是該 ACF 真實表示值，兩者不可混淆。
     六來源須對帳為 2–7，但不能以順序猜 slot。照片與作者須已對照真實 WP media／user。
   - `evidence_reference`: 原始 43/43、身分與欄位定義核對紀錄識別；
     `draft_hooks_reviewed`: 完成 draft 副作用審查才可設 true。
   runtime 會檢查結構、型態、43 欄數量、六身分及 reference 完全相符；
   **欄位定義與 media／user 真實有效性依賴人工核准證據，不假稱 runtime 能替代這項審查。**
5. 產生 root:root 600 的 `approved.json`：
   `{"commit":"<核准40位SHA>","runner_sha256":"<runner SHA256>","config_sha256":"<evidence.json SHA256>"}`。
   人工確認此版本 runner 只做本任務；不可從未審查分支下載執行。
6. 用管理者 AWS 身分建立獨立文件，**不是修改既有 Controlled-Deploy**：

```sh
aws ssm create-document --region us-east-1 --name PPB-V2-WP-Draft-Test --document-type Command --document-format JSON --content file://ops/wp-test/PPB-V2-WP-Draft-Test.ssm.json
aws ssm describe-document --region us-east-1 --name PPB-V2-WP-Draft-Test --query 'Document.{Version:DocumentVersion,Hash:Hash,HashType:HashType}'
```

7. 由 AWS 管理者審核建立獨立 `PPB-V2-GitHub-WP-Draft-Test-Role`；不修改現有部署 role／S3。
   OIDC trust 如下；沿用帳號既有 GitHub OIDC provider，不重建：

```json
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Federated":"arn:aws:iam::429724121147:oidc-provider/token.actions.githubusercontent.com"},"Action":"sts:AssumeRoleWithWebIdentity","Condition":{"StringEquals":{"token.actions.githubusercontent.com:aud":"sts.amazonaws.com","token.actions.githubusercontent.com:sub":"repo:Abel0513/ppb-v2:environment:ppb-wp-draft-test"}}}]}
```

   權限 policy：

```json
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Action":"ssm:SendCommand","Resource":["arn:aws:ssm:us-east-1:429724121147:document/PPB-V2-WP-Draft-Test","arn:aws:ec2:us-east-1:429724121147:instance/i-0135d6b2fac7a54c2"]},{"Effect":"Allow","Action":"ssm:GetCommandInvocation","Resource":"*"}]}
```

   GetCommandInvocation 不支援 resource-level 限制，故為 `*`；沒有 shell documents、
   UpdateDocument、IAM、S3 或其他 instance 權限。EC2 沿用既有 SSM agent／instance role，
   此 runner 不需 S3。確認 agent 支援 ENV_VAR interpolation。
8. GitHub Settings → Environments → 建立 `ppb-wp-draft-test`：指定 required reviewer、
   禁止 self-review、deployment branches 限 main。**不可省略保護規則**；workflow environment
   名稱本身不等於有人審核。新增環境 variables `SSM_DOCUMENT_VERSION`、`SSM_DOCUMENT_SHA256`，
   使用步驟 6 的固定版本與 SHA256（HashType 必須 Sha256）。
9. Actions → PPB V2 Expert draft test → Run workflow → main →
   confirmation 輸入 `CREATE-ONE-EXPERT-DRAFT`，經 reviewer 核准才執行。
   本批不 dispatch。workflow 只接受已安裝且 approved.json 核准的相同 Commit。
10. 檢查 SSM `expertDraftTest` 結果；只有獨立 GET、43 欄完全一致、六推薦正確、
    draft／作者正確與 30436 完整回應不變才 PASS。SSM 失敗／pending 禁止清 journal 重試。

## 驗收與回復

保留新 draft 與 journal 供人工驗收。未建立完成不可填入新 ID、HTTP 或 43/43 PASS。
不自動刪除文章（刪除需要另行核准），不回寫 V2 發布鎖。
停用 workflow／撤銷新 role trust 可關閉管道；不影響既有部署 role。
程式回退不能抹除 journal；如需清理新 draft，先對照已回傳新 ID（必須非 30436），
取得獨立核准後由管理者處理。
