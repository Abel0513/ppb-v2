# Phase A：一次固定管理批次（待授權，未執行）

本包不是新部署系統，也不是一般功能的發布入口。PR100已合併的固定工具
仍為361c04364fadace29c3fe06341e46236017d0cf6b10dcc92f9eaed7c9bd4003a。
PR58保持Draft；不含評鑑Migration、正式業務寫入、付款、PDF或LINE發送。

## 已確認的管理通道

- Run43614951108曾用相同PPB-V2-GitHub-Deploy-Role成功安裝，SSM
  20c34a0f-62f8-436e-b289-db54d0a14499。這是歷史事實，不代表今天仍有管理權。
- Run43648785774：目前UpdateDocument拒絕，沒有SSM命令，不重試。
- Run43654539420：只有58(Default)/60(Latest)，候選均不相符；IAM四項
  自查及S3候選GetObject拒絕。不能據此聲稱已讀完有效IAM政策。
- 現有V2工作流程都使用相同Role；WP角色不可借用。舊bootstrap只接受
  舊固定SHA，不能換S3舊物件或跳過worker pin。應用發布不可拿來更新root工具。
- 本Cloud沒有AWS管理connector、可用AWS CLI/profile或綁定管理身分；
  也沒有已登入的AWS Console/CloudShell工作階段。未搜尋密碼、token或SSO cache。

## 唯一需要的決策

授權「現有具SSM文件管理權限的管理者」在自己的既有工作階段，一次執行
本包；或將既有受控管理執行通道接入本批次。**不是授予日常GitHub Role
新權限，不是提供管理員密碼/keys給Codex，也不是請使用者逐項手動配置AWS。**
目前沒有這個可執行管理工作階段，只有聊天同意不能讓AWS拒絕自動消失。
本包不需合併main即可使用，不能把CI成功當已執行。核准前不得加--apply。

## 固定資料與動作範圍

帳號429724121147，區域us-east-1，文件PPB-V2-Controlled-Deploy。

| 候選 | 精確檔案SHA256 |
| --- | --- |
| bootstrap JSON | 567d9ff5798e08e82472c4595892e3d0ad904a9451930bb168d08feb5390d794 |
| isolated JSON | b16109a7334de36ba9d860ee96220fd00bb9b9be2237a1f832c5ccddee6e0768 |

所需是管理者既有的以下能力，不由本程式新增IAM：

- ssm:GetDocument、DescribeDocument、ListDocumentVersions、UpdateDocument、
  UpdateDocumentDefaultVersion、DeleteDocument，僅
  arn:aws:ssm:us-east-1:429724121147:document/PPB-V2-Controlled-Deploy。
  程式限制只能發布上述兩份JSON；預設切換只在R2/隔離收據驗證後；刪除
  只限已封存、非預設、被替代的58/60，不刪整份文件。IAM不能以虛構的
  DocumentVersion條件取代這些程式約束。
- ssm:SendCommand只對上述文件及
  arn:aws:ec2:us-east-1:429724121147:instance/i-0135d6b2fac7a54c2；
  僅呼叫核准bootstrap的精確版本/服務hash，無shell參數。
- ssm:GetCommandInvocation（此API需Resource:*）；程式只讀本批Command ID、
  上述instance及固定plugin，不輸出其他命令。
- s3:GetObject僅既有bucket
  ppb-v2-release-artifacts-429724121147-use1 內下列三個物件，以及本批封存前綴：
  releases/bootstrap/361c04364fadace29c3fe06341e46236017d0cf6b10dcc92f9eaed7c9bd4003a/fixed.tar.gz
  releases/ssm-reviewed/5c5b533e9825d474743e1ed5e590c1410446fe7f/bootstrap-567d9ff5798e08e82472c4595892e3d0ad904a9451930bb168d08feb5390d794.json
  releases/ssm-reviewed/5c5b533e9825d474743e1ed5e590c1410446fe7f/isolated-b16109a7334de36ba9d860ee96220fd00bb9b9be2237a1f832c5ccddee6e0768.json
- s3:PutObject只用於同bucket之
  releases/ssm-history/ui-phase-a-*/version-58.json、version-60.json；
  AES256、IfNoneMatch:*，不覆寫舊封存。無需S3設定、IAM或EC2 API寫權限。

現場未能證明日常Role的UpdateDefault/Delete有效權限；不以寫入探測。
這個完整管理批次包含所有安裝必需動作，避免只預載後才逐項要求補權限。

## 批次執行與收據

先核對管理包外部SHA及SHA256.json，在管理者自有私有目錄解壓。
無需GitHub PAT、專案clone、DB憑證或EC2登入。AWS CLI使用既有合法登入。
審查後的單次操作為 `python3 manage-ui-phase-a.py --apply`；不加--apply
只做固定S3讀回和文件metadata預檢，不更新或呼叫EC2。

會先下載三個S3物件並逐一核對SHA，再使用已審核activate-isolation.py
（原碼hash亦鎖定）完成精確版本建立、單次SendCommand、安裝收據回收、
核對R2 FPM/policy/unit未變與舊系統變更0、切換runtime、封存/移除58/60。
不重新配置OS/FPM、不重跑R1/R2或DBA。成功才輸出
MANAGED_UI_PHASE_A_INSTALL_VERIFIED及真實Command ID/版本。

本機state檔mode600、原子更新。成功重跑只GET對帳；有既知Command ID
的中斷續接同命令，不再Send；Send結果不確定且沒有ID則停止，不盲目重送。
錯誤回報API動作/資源/安全錯誤碼，不能要求貼AWS秘密或完整私有日誌。

## 風險與回復

- 安裝前任何S3/hash/版本檢查失敗：沒有root命令，不需服務回復。
- 發布非預設版本失敗：記錄版本，舊Default不變；放棄候選時管理者只刪
  本次新增且非預設版本。不得刪原58/60或整份文件。
- EC2命令送出後不確定/隔離未通過：停止，不重送/強制繼續；保留Command
  ID和state。安裝器的root私有備份為
  /www/ppb-v2-deploy/backups/fixed-tools-361c04364fadace29c3fe06341e46236017d0cf6b10dcc92f9eaed7c9bd4003a。
  既有R2備份fpm-reader-socket-1794683061172906509不刪。
- 不可在新worker已安裝後僅切回舊58（會造成hash不一致）。需要工具回復時，
  必須按已記錄的固定備份/工具hash做受審查的成套恢復並重驗隔離，不開放
  任意root shell。本批不預先授權未知故障的未審核恢復程式。
- 成功後root工具/文件一致；一般V2應用仍用原通道，不因每次UI改動重裝工具。
  Phase B ui_source仍須先取得真實路徑、逐檔allowlist與解析器審核，不能
  把本次Phase A批准解讀為任意模板原文輸出權限。

所有協定模擬測試僅證明程式約束，不是AWS/EC2或舊UI還原PASS。
