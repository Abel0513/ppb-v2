# 一次性 AWS 管理設定：只接通 WP 測試

此頁是具體待授權項目，不是已套用 AWS 的聲明。執行位置為已登入 AWS 帳號 `429724121147` 的管理工作站或 AWS CloudShell，**不是 EC2 終端**。程式不需要 WordPress 憑證，不要求使用者建立認證檔。

從已審查的 PR #13 commit 取得原始碼，在該 checkout 的根目錄用既有 AWS 管理身分套用一次：

```sh
python3 ops/wp-test/admin-setup.py --apply
```

不帶 `--apply` 則只讀產生計畫；工具會自行查出指定 EC2 的 profile／role。它不下載或執行 EC2 命令、不建立 OIDC provider、不修改正式 Deploy-Role，不建立 Codex CLI Runner。此核准版本往後由 GitHub Actions 全自動完成。管理工具會輸出精確核准的 package SHA；固定文件內的 allowedValues 與 shell 常數皆鎖定該 SHA，不接受開發分支自行授權其他可執行內容。

## 精確變更範圍

| 對象 | 一次性變更／需要的管理 API |
|---|---|
| 既有 `arn:aws:iam::429724121147:oidc-provider/token.actions.githubusercontent.com` | 只讀 `iam:GetOpenIDConnectProvider`，確認既有 sts.amazonaws.com audience；不重建 provider |
| `arn:aws:iam::429724121147:role/PPB-V2-GitHub-WP-Draft-Test-Role` | `iam:GetRole`／`ListRolePolicies`／`ListAttachedRolePolicies`；必要時 `CreateRole`、`UpdateAssumeRolePolicy`；`PutRolePolicy` 僅 `PPB-WP-Draft-Runtime`。若現有 role 另有未知政策，先停止，不默默保留廣權限或刪除政策 |
| `i-0135d6b2fac7a54c2` 已附加的 instance role | `ec2:DescribeInstances`、`iam:GetInstanceProfile` 自動取得真實 role ARN；只對它 `iam:PutRolePolicy` 增加 `PPB-WP-Test-ArtifactRead`，不更換 profile、不修改現有 SSM 政策 |
| `arn:aws:ssm:us-east-1:429724121147:document/PPB-V2-WP-Draft-Test` | `DescribeDocument`／`GetDocument`／`ListDocumentVersions`；必要時 `CreateDocument` 或 `UpdateDocument`，再 `UpdateDocumentDefaultVersion`；`DeleteDocument --document-version <舊版本>` 僅撤除此 WP 文件的舊版本，避免繞回未鎖定 root 執行入口。不修改 Preflight／Controlled-Deploy 文件 |
| AWS account 身分 | `sts:GetCallerIdentity`，強制帳號一致 |

上述管理 API 不授予日常 WP Actions role。EC2 role 的真實名稱不能從 snapshot 猜測；管理工具會從固定 instance 的現有 profile 解析並在計畫／套用結果列出確切 ARN，不讓使用者手動判斷或挑選其他主機。

## 日常 WP role 的最小權限

完整信任與政策：`iam/wp-role-trust.json`、`iam/wp-role-policy.json`。

- OIDC `aud=sts.amazonaws.com`，`sub` 精確限定同 repo 的 `refs/heads/codex/issue-12-wp-draft` 與 `refs/heads/main`。不接受 fork／pull_request／任意分支；workflow 的 repository／owner 數字 ID 亦固定。
- `ssm:SendCommand` 僅固定 WP document ARN + 固定 EC2 instance ARN；沒有 AWS-RunShellScript、Controlled-Deploy、SSM 修改、IAM、EC2 修改權限。
- `ssm:GetDocument`／`DescribeDocument` 僅固定 WP document，讓程式逐次驗證內容與當次 commit 一致，並鎖定 version + AWS SHA256。
- `ssm:ListCommandInvocations` 為結果讀取；此 AWS API 不支援 resource-level 限制，因此 Resource 必須為 `*`。controller 只接受該 Command ID／instance 的輸出，不提供任意遠端命令。
- `s3:PutObject` 僅既有私人 bucket 的 `wp-test/*` 前綴，要求 AES256。沒有 DeleteObject、公開 ACL、bucket-policy 修改，也不能寫 `releases/*`。

EC2 新增政策 `iam/ec2-artifact-read.json` 只有同一 `wp-test/*` 的 `s3:GetObject` 與該 bucket 的 `GetBucketLocation`。不新增 instance role、不變更舊正式發布權限。

## 啟用與實際失敗的處理

套用管理設定後，對授權開發分支的下一次 push 自動跑真實測試；已註冊 workflow 亦可重新執行同一 Actions Run，不需登入 EC2。GitHub 同一個 Run 的重跑會使用新的 run_attempt／S3 key，而 WP 固定 journal 保證不新增第二筆。

Actions 會辨識失敗層級：

- `AWS_OIDC_WP_ROLE_NOT_ASSUMED`：實際 STS 拒絕，先核對上述專用 role 是否存在及精確信任；不能把既有 Deploy-Role 的成功當作本 role 的授權證據。
- `SSM_DOCUMENT` + InvalidDocument／AccessDenied：固定文件尚未建立或 WP role 缺讀取權限。
- `INSTALLED_SSM_DOCUMENT_DIFFERS_FROM_COMMIT`：管理工具更新固定文件後才可執行；workflow 不自行修改文件。
- `S3_UPLOAD` 或 `downloadPinnedWpPackage` 失敗：依固定前綴與上列 runtime／instance role 政策處理，不放寬為整個 S3 或任意 instance。
- 已取得 SSM Command ID 後的未知結果：不自動重送。固定 journal 保留；後續驗證只能接續／核對同一草稿。Actions Artifact 保留已知 Command ID 供追蹤。

所有實際結果以 Actions 的 `remote-result.json`／Job Summary 為準；缺 Command ID 或新 WP ID 時必須標示未完成，不能用離線測試替代。

## 唯一管理操作的安全順序

同一個 `admin-setup.py --apply` 完成以下步驟，不拆成使用者逐項執行：

1. 只讀確認帳號、既有 provider、指定 EC2 的 instance profile、專用 role 既有政策，並核對本地文件與核准套件可重建且 SHA 相符。
2. 先將 WP 專用 role 的 runtime policy 暫設為 Deny SendCommand，再更新其精確 OIDC trust。新 role 若需建立，初始沒有執行權限。
3. 安裝鎖定 package SHA 的固定 SSM 文件，切換 default version；撤除這一個 WP 文件的其他版本，避免分支利用舊版本傳入任意 SHA。沒有刪除其他 SSM 文件、WP 草稿或應用資料。
4. 重新列出版本並 GET 核對內容完全一致後，最後才開放固定 document／instance／S3 前綴的日常政策。中途失敗保持 Deny，不能半套開放。

日常 WP role 沒有以上 IAM／SSM 管理權限。單次管理核准授權的是這一份可審查的固定程式；同一套件之後自動重跑／回收，不需登入 EC2。若將來修改可執行套件或 SSM shell／collector，必須重新審查其固定內容，不能以「開發分支已通過 CI」取代 root 程式授權。純文件更新不會改變 runtime 套件 SHA。
