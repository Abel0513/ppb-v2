# 候選版本核准範圍（尚未執行）

目前 AWS 已核准的套件仍為 `ef38273697396d944f9beade9db8c80d42f8b463363669e9e59185b1586e7909`，SSM version 1 原內容 SHA 為 `5f18a364a54037be40e0bb4bfece6f326e3c30562423b5fd6da848d0b61588e1`。本批沒有變更 AWS 文件或權限，也沒有把候選套件送 EC2。

候選執行套件 SHA：`922ced95a03cce72def8cb0d57e671a08986ad2f0ee5dd83c1e72cfec7ed5b4d`。

候選 SSM 文件 SHA：`d0cdd583e512b9fd078d9a1e473133e359249e05a9b505024c7605b40d4f34c1`。

## 已證實的程式問題

Run 43349393440 的公開只讀證據：Expert GET 回傳 type=expert、status=publish、title/content，但 author 不存在；文章 POST args 亦不含 author。同名 taxonomy 使用同一 REST base，故不能只用 OPTIONS 最後一份 schema 判定文章端點。原程式的 ownDraft 無條件要求 author，導致建立／GET 後必然 STOP。

## 候選行為與保留的限制

- 同一 WPF6 Basic Auth／原來源 SHA／唯讀 profiles／ACF v3 fields，不重建接入。
- 先 authenticated GET 真實 post type capabilities；不宣告 author 支援時才使用 authenticated default-author contract，並要求本次 201、已驗證登入者、journal 作者及 receipt（若存在）一致。若 API 支援作者，缺欄位／錯誤作者一律 STOP。
- 所有情況都維持新 ID、不等於 30436、expert、draft、固定 slug、title、完整 content marker；六位真實來源作者逐一 GET。沒有跳過身份／防重複保護。
- 明確標示 core author 是否獨立暴露；unsupported fallback 是平台預設作者契約，不能冒稱 REST author 獨立讀回。此限制須納入審查，不以 mock 證明遠端作者值。
- 原 journal／fingerprint／固定 marker 不改；接續同一已開始的草稿，未知結果不再 create。新回應 receipt 在完整驗證前落盤；STOP 只回傳未驗收 candidate ID 與布林診斷，PASS ID 必須完成全部讀回。
- collector 僅增加安全的作者證據、candidate ID 與身份判斷；不輸出原始文章或憑證。

## 核准邊界

候選 commit 使用 `[wp-schema-only]`，只執行 CI／公開 schema 讀取，execute job 停用。即使其他 push 觸發執行，controller 比對已安裝 SSM 文件時仍會因候選內容與 AWS version 1 不同而停止；不能以新 SHA 自行覆寫 AWS 核准。

若採用候選，需要在既有 `PPB-V2-WP-Draft-Test` 核准新文件版本及設為 default，使用本 PR 的 `PPB-V2-WP-Draft-Test.ssm.json`。這是新執行內容的核准，不是重建 IAM／S3／Runner，也不需要重新跑初始化；原 version 1 已鎖定原 SHA，可保留審計。日常 role 沒有 UpdateDocument／UpdateDocumentDefaultVersion，Cloud 目前亦沒有管理連線，故尚未代為套用。未核准不得執行或宣稱 WP PASS。
