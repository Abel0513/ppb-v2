# V2 / WP capability audit — 2026-11-13 Asia/Taipei

This report distinguishes code, live Actions evidence and unverified settings.
WP remains PR #13. No new Laravel page or V2 implementation PR was created.
V2 was inspected in an isolated detached checkout of main
`9564cd0521dd57dbdc249601b9d1a8acafd2103e`, not the WP branch.

## WP parameterized batch

Candidate supports read / update_acf / verify. Grant: Expert 30460,
Bonus推薦投注 only. Desired value: Codex測試123, stable request ID
expert30460-bonus-123-v1. Partial ACF payload, separate durable update ledger,
original 201 receipt/journal/fingerprint immutable, no create routing. Other 42
values, source analysts/media, core and old Expert 30436 are compared before/after.
Creation is kept separate and explicitly denied without its own new-draft grant;
no general create operation is claimed accepted or executed.

88 offline tests passed locally, including actual PHP transport fixture -> Python
ledger; no database or network used by these tests. Original fixed v1 document
and package remain reproducible/unchanged. Candidate must be reviewed and installed
before it can operate; no new IAM permission was granted or bypassed.

Actual [candidate Run 43439175749](https://github.com/Abel0513/ppb-v2/actions/runs/43439175749):
86-test initial CI passed; OIDC succeeded; installed WP document was version 2,
SHA 181510ff4e60c224de988c54368f3f868284f2c0d3a240ef320fd7340db7e7a6.
Candidate read STOP=PARAMETERIZED_INTERFACE_NOT_INSTALLED **before** upload/SendCommand.
No candidate SSM Command ID or new Bonus value GET acceptance exists.

Actual [old-interface read Run 43439175575](https://github.com/Abel0513/ppb-v2/actions/runs/43439175575):
Command 70b5d12e-b295-4698-8de1-74d2c3fb7138, Expert 30460, VERIFIED journal,
201 receipt, GET 200, WP writes=0. This is not a readback of Codex測試123.
Original 43/43 success remains [Run 43433188330](https://github.com/Abel0513/ppb-v2/actions/runs/43433188330),
Command 48c6d311-ffb0-4836-9c8c-db5f728b7388, all six old values Codex測試.

Installation authority still absent from WP runtime role
PPB-V2-GitHub-WP-Draft-Test-Role: ssm:UpdateDocument and (default-based client)
ssm:UpdateDocumentDefaultVersion on
arn:aws:ssm:us-east-1:429724121147:document/PPB-V2-WP-Draft-Test.
Do NOT add these unrestricted privileges to an unreviewed development branch.
An administrator/trusted reviewed promotion identity must install this fixed data
interface once; future allowed text values use data requests, not new code SHA.
No CloudShell script or infrastructure reinitialization is part of this batch.

## V2 development / PR

Direct Codex Cloud can inspect code, edit app/routes/resources/tests, run safe
offline tests, push a new branch and use the connected GitHub PR API. Actual WP
PR updates and successful Actions are evidence for this development channel.
Next page work must branch from then-current main, with its own page specification
and PR. It must not use the WP branch or run deployed migrations, seeds or
PpbBatchVerify. CSS/JS can be under resources; no page was invented here.

main's Issue-label automation is a different path: ppb-codex-automation.yml
requires ppb-codex-subscription self-hosted runner, ChatGPT CLI login readiness,
and CODEX_GITHUB_TOKEN in the publisher job. No new runner was installed here.
Actual current Actions configuration read in Run 43439175749:
- PPB_SUBSCRIPTION_RUNNER_READY=false
- CODEX_GITHUB_TOKEN present=false in this job/repo context
- PPB_AUTO_DEPLOY empty/unconfigured (therefore false)
- Runner inventory GET returned 403; physical runner online/login state unverified.
Historical [Run 43309624208](https://github.com/Abel0513/ppb-v2/actions/runs/43309624208)
failed prepare with readiness=false/token=false; develop/publish skipped.
Therefore Issue label -> autonomous development/repair/PR is NOT accepted.
Direct Cloud task initiation does not require activating that separate runner.

main's trusted exporter allows app/routes/resources/tests/docs/database only;
config/public are rejected by that exporter despite release gate allowing them.
Use resources assets for a normal page or review that allowlist separately.
The workflow invokes the developer but does not itself guarantee PHP/route/Blade
functional tests were run: current CI tests release guards/automation and packaging,
not arbitrary new-page acceptance. Each feature PR needs explicit safe route,
rendering/auth and regression tests, avoiding live DB/external effects.

Local latest-main validation: 33 automation tests and 49 deploy tests passed,
using fixture directories, PHP CLI and no AWS/DB. This is not page acceptance.

## V2 remote channel evidence

Fresh rerun of [preflight Run 43100863327 attempt 2](https://github.com/Abel0513/ppb-v2/actions/runs/43100863327/attempts/2):
existing PPB-V2-GitHub-Deploy-Role -> PPB-V2-Preflight -> EC2 succeeded,
Command **9d797404-b88c-4da1-b726-80faf44026ec**,
RESULT=PPB_V2_GITHUB_SSM_PREFLIGHT_PASS / PPB_V2_PREFLIGHT_READONLY_PASS.
No V2 deployment or WP access was requested. This proves current preflight
connectivity/authority, not permission for an untested new deployment.

Historical actual [deploy Run 43277236350](https://github.com/Abel0513/ppb-v2/actions/runs/43277236350):
commit 2b03fcc6351b26513ccf6ead0b9e448c38a692be,
Command **994f1e04-c444-41be-895f-bea4179d89e9**,
Requested operation=deploy, LIVE_APPLICATION_DEPLOYED=YES,
RESULT=PPB_V2_CONTROLLED_DEPLOY_PASS. This predates the latest automation/baseline.
It does not prove the new accept-v2.py or latest SSM version is installed.

Current main release gate requires human write/maintain/admin merger, main still
at the merged SHA, no database/dependency/control-plane changes, application
paths changed, and two successful GitHub Actions check names:
Offline release guards (no DB, no AWS), automation-tests. PPB_AUTO_DEPLOY=true
is additionally required for automatic post-merge release. No merge occurred here.
Without the flag, an authorized workflow_dispatch audit/deploy remains the
existing mechanism; deploy requires explicit DEPLOY-PPB-V2.

Current main also requires PPB_DEPLOY_DOCUMENT_VERSION and
PPB_DEPLOY_DOCUMENT_SHA256 to pin a reviewed controlled-deploy document containing
functionalAcceptance, plus installed accept-v2.py with fixed hash. Their actual
configuration/install state must be checked; do not infer it from source files.
The WP role's GetDocument(Controlled-Deploy/Preflight) calls returned AccessDenied,
IAM GetRolePolicy also AccessDenied; channels were not mixed or permissions raised.

accept-v2.py only checks four guest baselines: login form 200 and three admin
routes redirecting guests to login, with release lineage. It does not verify an
arbitrary new page, authenticated behavior, JS or all existing business functions.
No new-page remote acceptance is claimed. No real baseline run on the latest
main SSM/accept-v2.py has been verified in this batch.

## Once, within the existing architecture

1. Review/install the fixed WP parameterized document using scoped administration;
   do not grant branch arbitrary root shell or unrestricted document replacement.
   Subsequent allowed Bonus text changes are JSON requests with receipt replay.
2. Use existing Cloud sessions for development if no runner is wanted; do not
   represent the disabled Issue/Runner route as active. No runner is needed for
   a user-started Cloud task. CI and human merge are retained.
3. Before enabling automatic V2 release, align the existing fixed acceptance tool,
   reviewed controlled SSM version/hash and GitHub pin variables, then run one
   approved audit/deploy/baseline. Set PPB_AUTO_DEPLOY only after that evidence.
   No new OIDC provider, S3 bucket, EC2 runner or deploy role is needed.
4. Generalize page acceptance once as a reviewed bounded GET/status/content
   manifest reader in the existing V2 acceptance tool. Future page PRs supply
   data-only page/regression manifests plus offline feature tests, not root test
   scripts. Keep auth requirements explicit; guest redirects alone cannot prove
   logged-in page behavior. This manifest enhancement is a proposal, not installed
   code or an implemented new page in this WP PR.

Ordinary approved pages should then require feature review/merge, not repeated
AWS setup. Changes to privileged executors, authority grants, DB/schema,
dependencies or security policy remain separately reviewed high-risk changes.
No private repository publication or GitHub paid-plan upgrade is a prerequisite
for the current WP acceptance. Stronger branch protection/promotion features are
separate governance work, never grounds to widen root execution privileges.
