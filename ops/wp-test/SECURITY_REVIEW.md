# PR #13：開發分支與 EC2 root 執行邊界

## 確認的原風險

前版同一開發分支可上傳 shell，並自行計算 PackageSha256 傳入固定 SSM 文件。這只能驗證下載的 bytes 沒有損壞，不能證明 AWS 管理者已授權那些 bytes。只限制 document／instance ARN、OIDC 分支及 client-supplied SHA，仍可能讓有該分支寫入權的人把任意程式交給 SSM Agent 以 root 執行。

## 此版的服務端限制

| 嘗試 | 強制限制 |
|---|---|
| 改寫 package，提交它自己的新 SHA | 已安裝 SSM 文件的 PackageSha256 allowedValues 只接受一個管理者核准值；prepare 與 execute shell 亦各自檢查固定常數 |
| 保留核准 SHA，替換 S3 bytes | 執行前 sha256sum 對照文件內常數，失敗即停止，不執行 package |
| 修改開發分支的 document／renderer／controller | WP role 沒有 SSM 更新或 IAM 權限，不能把新文件裝上 AWS；客戶端修改無法繞過服務端固定檢查 |
| 指定以前允許任意 SHA 的 document version | 管理操作先 deny SendCommand，再更新 default、刪除該 WP 文件其他版本並 GET 核對，最後才 grant；因 IAM SendCommand 本身不能代替內容／版本核准，不能只更換 default 而保留不安全舊版本 |
| 用通用 shell document 或其他 EC2 | IAM 只准固定 WP document ARN 與固定 instance ARN，沒有 AWS-RunShellScript／Controlled-Deploy／其他 instance 的 SendCommand |
| 利用 SSM 參數做 shell 注入 | 只接受固定 approval、40 位 commit、數字 Run ID／attempt、單一固定 SHA；沒有 command／URL／路徑／文章 ID 參數 |
| fork／其他分支取得執行權 | OIDC subject 精確限定兩個 repo ref；PR 事件不啟動 AWS job；workflow 另鎖定 repo／owner 數字 ID |

套件 SHA 是管理者在一次性 `admin-setup.py --apply` 以已審查 commit 寫入 AWS 的授權；不是由每次 Actions 呼叫動態更新。PackageSha256 雖仍作為傳輸參數，不能改成任意 64 字元值。日常角色沒有文件更新／刪除／IAM 管理權限。套件僅打包列出的 runtime 檔案，文件變更不改變可執行 SHA。

## root 與既有系統的實際範圍

本版沒有宣稱把 SSM 改成非 root，也不是新的 OS sandbox。SSM Agent 仍依既有配置以 root 啟動；允許的是已審查且 SHA 鎖定的 WPN2。該程式只讀原 connector／DB 設定／profiles，WP 寫入限定任務 journal 所屬新 Expert draft 與 ACF；禁止既有 #30436、其他文章、posts、publish、delete。固定私有 journal、來源 SHA、flock、防重建、原子報告及 credential-free collector 持續生效。未知 WP 外掛 hooks 的副作用仍不能由 REST 單獨證明。

AWS 管理者、現有 root 管理員及已核准程式本身仍是信任邊界。這次只限制新命令的授權，不能撤回在權限收斂前已開始執行的舊程序。沒有更換正式應用、舊資料、LINE、Cron 或 instance profile；管理更新／舊版本移除僅限 WP 測試文件。

## 驗證

- 執行實際 SSM shell verifier 的離線前綴：核准 bytes 通過；替換 bytes／自選 SHA／假冒核准 SHA 拒絕；不執行 package。
- 管理工具測試：既有 role 先 Deny 再改 trust；刪除舊版本在 grant 前；撤除失敗不恢復執行權限。
- 真正 PHP → JSON → Python 整合測試與完整 CI，另於 VALIDATION.md 與 Actions 記錄。離線驗證不是已套用 AWS 的證明。
