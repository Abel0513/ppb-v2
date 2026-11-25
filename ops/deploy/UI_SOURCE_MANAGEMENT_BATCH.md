# 皮皮寶 V2｜ui_source 固定管理批次

本包給既有合法 SSM 文件管理者，在其既有管理工作階段使用。不是新的部署系統，不擴大日常 GitHub 部署 Role，不要提供 AWS Key 或把憑證交給其他人。

## 目的

把已審核的 Phase B `ui_source` 能力安裝到既有固定工具執行器：

- 21 個評鑑相關主路徑（#115 主清單，不含 LIFF）寫入受信任的 `PRODUCTION_CATALOG`，只標 `kind`，不偽造 labels / tokens。
- 第一次 `ui_source` 依既有解析器做 `REVIEW_REQUIRED` 結構擷取，不回傳原文或中間 tokenizer 輸出。
- `ui_source` 只在該 catalog 存在時加入 worker 允許清單。
- `structure.py` 與 `php-inline.php` 進入 pinned bootstrap／runtime（`build-bootstrap.py` FILES、prepare／dispatch 核對、isolated 文件雜湊釘扎）。
- 既有遠端邊界不變：唯讀 OS 身分、無 sudo、固定根目錄、symlink 檢查、大小與輸出上限、沒有 raw text reader。

## 包內容

六個已審核檔案加 `SHA256.json`（format `PPB_UI_SOURCE_MANAGEMENT_V1`，source_main `7b4a7e45585f98b5c6bf04ff44c328130ba3998a`）。`SHA256.json` 本身不在 files 清單內。

固定 root bundle SHA256：`babf57824db0046930ada81db9174be3c9340bba3c15c866d59913e0f5a7f0b8`，S3 鍵 `releases/bootstrap/babf57824db0046930ada81db9174be3c9340bba3c15c866d59913e0f5a7f0b8/fixed.tar.gz`。此物件不在本 ZIP 內，請使用同一 isolation CI 產出的 `reviewed-v2-isolated-tools` artifact。

首次安裝與每次資料發布都要求 SSM Default 為 **v62**，且 v61、v62 皆存在。兩版的 `describe-document` SHA-256 必須分別符合 v61／`05d73f4e07f94748f11bcd291114cd3c296e7fde2f0ad04c90508bf456a48a3a` 和 v62／`92db95e386608ba55a1f4ba231df94c6af09b1eaaaf965886a4382c62f6fde24`。任一條件不符，預檢及 `--apply` 都會在寫入前回傳 `result: STOP`。有效的安裝 journal 可在 Default 切換後繼續執行或重跑驗證。

## 因為這是新工具位元組

#102 安裝時三個 S3 物件已存在。本批是新 bundle 與新審核 JSON，dry-run 的 get-object 在物件尚未上傳前會失敗。請先用本工具的資料發布步驟（只 Put 上述三個精確鍵，IfNoneMatch、AES256，無 SSM／EC2 寫入），再做安裝預檢與 `--apply`。不要改 IAM、不要用日常 GitHub 部署 Role 重試。若 PutObject 得到 AccessDenied，停止並回傳 JSON，不要自行放寬權限。

本批由既有管理者在 CloudShell 依工具安全檢查完成。合併會觸發 `ppb-v2-activate-isolation.yml`，其中工具包上傳是獨立的前置步驟。啟用步驟若確認 Default 與受審查的 isolated JSON 相符，且已有相符的 bootstrap 版本，就以唯讀驗證完成。若 Default 已相符但缺少受審查的 bootstrap 版本，啟用步驟會停止。若 Default 內容不同，則沿用既有啟用流程。

## 執行

在解壓後的私有目錄（建議權限 700），先核對六檔 SHA，再：

```bash
# 1) 資料發布預檢（不寫入）
python3 manage-ui-source.py --publish-data --bundle /path/to/fixed.tar.gz

# 2) 資料發布（只放行三個已釘扎物件）
python3 manage-ui-source.py --publish-data --bundle /path/to/fixed.tar.gz --apply

# 3) 安裝預檢（不要加 --apply）
python3 manage-ui-source.py

# 4) 預檢通過後的一次 apply
python3 manage-ui-source.py --apply
```

首次安裝預檢成功時，`result` 為 `UI_SOURCE_MANAGEMENT_BATCH_DRY_RUN`，`default_version` 為 `"62"`，`ec2_commands`／`document_writes`／`iam_writes` 皆為 0，`s3_verified` 三筆 SHA 相符。

Apply 成功：`result` 為 `MANAGED_UI_SOURCE_INSTALL_VERIFIED`。工具會建立／切換文件版本，在隔離與 R2 收據通過後才把 Default 指到 isolated runtime，並只在封存、非 Default、安全檢查通過後才刪 61／62。不要在 Console 自行改 Default 或刪文件版本。

成功重跑只 GET 對帳；有既知 Command ID 的中斷續接同命令，不再 Send。Send 結果不確定且沒有 ID 則停止，不盲目重送。本機 state 檔 `ui-source.state.json`，mode 600、原子更新。

## 風險與回復

- 資料發布或安裝前任何 S3／hash／版本檢查失敗：沒有 root 命令，不需服務回復。
- 發布非預設版本失敗：記錄版本，舊 Default 不變；放棄候選時只刪本次新增且非預設版本。不得刪原 61／62 或整份文件。
- EC2 命令送出後不確定／隔離未通過：停止，不重送。安裝器的 root 私有備份為 `/www/ppb-v2-deploy/backups/fixed-tools-babf57824db0046930ada81db9174be3c9340bba3c15c866d59913e0f5a7f0b8`。既有 R2 備份不刪。
- 不可在新 worker 已安裝後僅切回舊 62。需要回復時必須按已記錄的固定備份／工具 hash 做受審查的成套恢復。
- 本批不重跑 R1／R2 或 DBA，不改 Role／變數，不做舊系統寫入，不合併 #106。
- 第一次 `ui_source` 在 catalog 的 21 個路徑上預期為 `REVIEW_REQUIRED`（空結構、固定錯誤碼），不是完整 UI 還原。不得把本次批准解讀為任意模板原文輸出權限。

所有協定模擬測試僅證明程式約束，不是 AWS／EC2 現場 PASS。
