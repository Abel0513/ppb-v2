# Latest result: PASS — six-field update and GET-only replays

The installation blockers recorded below were resolved by the administrator
without IAM changes. Actual update43519237581/329ce1bd-bb71-487b-b6ab-3bf4aee6215c
returned POST200 and independentGET200; six fields exact,37 unchanged. Verify
43519593734 attempts1/2 bothPASS, zeroPOSTs. See LIVE_ACCEPTANCE.md/live-evidence.json.
The following sections preserve historical BLOCKED evidence, not current status.

# Real status — 2026-11-14 Asia/Taipei

**WP update acceptance: BLOCKED / NOT PASS. No new Expert was created.**

## Actual remote journal read

- Actions: https://github.com/Abel0513/ppb-v2/actions/runs/43440156112/attempts/2
- SSM command: `3fdb45eb-30d6-43d5-9bc1-27fd1948d9b3`
- Existing Expert30460: expert/draft, journalVERIFIED, original HTTP201 receipt,
  authenticated GET200, zero WP write requests.
- Installed document version2, SHA256
  `181510ff4e60c224de988c54368f3f868284f2c0d3a240ef320fd7340db7e7a6`.
- This inspector does not return all43 current ACF values. No fresh assertion of
  their contents, update invariance or mutation idempotency is made from this read.

## Actual installation authority check

- Actions: https://github.com/Abel0513/ppb-v2/actions/runs/43514229614/attempts/2
- Existing role: `arn:aws:iam::429724121147:role/PPB-V2-GitHub-WP-Draft-Test-Role`
- Denied action: `ssm:UpdateDocument` → `AccessDeniedException`
- Resource: `arn:aws:ssm:us-east-1:429724121147:document/PPB-V2-WP-Draft-Test`
- Probe content was exactly the existing latest document; before/after content
  and version matched. No candidate was installed, no default version changed,
  no EC2 command sent by the candidate, zero WP writes.
- Candidate client stopped `PARAMETERIZED_INTERFACE_NOT_INSTALLED`, commandIDnull.
- Default-version update authority was not tested and is not claimed as proven.

## Corrected program and remaining boundary

1. Expert ID, requested exact source field(s), text and request ID are parameters.
   The only allowed target is independently bound to the original private201
   receipt/journal; other Experts and legacy30436 are rejected.
2. Recommendation/bet/analysis text fields derive from verified WPF6 schema;
   identities, media and core are protected. Baseline comparison expects43-N
   untouched fields, preserving exact JSON types and durable no-resend semantics.
3. Existing promotion workflow incorrectly rendered/promoted only the old
   journal/resume interface. It now tests/renders the parameterized candidate,
   explicitly selects it, requires the WP-specific promoter identity and verifies
   the new default after update. Main/environment review requirements remain.
   This is source preparation, NOT proof the promoter exists or can be assumed.
4. Installing the reviewed fixed WP document through a legitimately authorized
   WP-specific management/promotion identity is the remaining AWS boundary.
   Do not grant unrestricted document replacement to the developer role, assume
   the V2 role for WP, change V2 SSM, or use old resume to overwrite all43 fields.
   After that one reviewed interface installation, later allowed text requests
   need only JSON data, without per-value executable/SHA changes.
5. Scope is now explicitly confirmed: 分析師1推薦投注 through 分析師5推薦投注
   plus Bonus推薦投注, all Codex測試123. The manifest uses stable request ID
   expert30460-six-bets-123-20261114-v1. No further field clarification is needed.

PR13 remains open/unmerged. Legacy code/DB, V2, WP contents and permanent journals
were not modified by these diagnostics. Offline/CI results are separate evidence.

Latest local consolidated verification:95 WP tests (including real PHP fixtures),
79 deployment regression tests,46 automation tests passed after synchronizing main.
These220 local tests do not establish remote ACF update acceptance. The PR merge
conflict was limited to identical Python cache ignore rules and has been resolved;
no main merge/deploy or V2 program change is part of this WP PR.

## Minimal existing-administrator operation

The reviewed fixed document is unchanged from700e175, SHA256
`a9fc80aa20b413233fd15ad5b7df8617d56984ed44d3c05c079ec71a09d353ee`.
`install-reviewed-once.sh` embeds those exact bytes and checks their SHA. It needs
no repository checkout, GitHub PAT, EC2 login or credential export. An existing
AWS administrator can run this one file in CloudShell. It only installs/selects
the reviewed version of PPB-V2-WP-Draft-Test, verifies account429724121147, old
default2/hash and final contents/version/hash, and stops on unexpected drift.
A repeated successful installation is a no-op. No EC2 command is sent.

Required existing management actions on the one WP document: ssm:GetDocument,
ssm:DescribeDocument, ssm:UpdateDocument, ssm:UpdateDocumentDefaultVersion, plus
sts:GetCallerIdentity. No IAM mutation or daily-role expansion is requested.
V2 and daily WP role identities are explicitly rejected by the installer.
The main workflow inventory currently has no WP promotion workflow; the PR-only
promotion definition is not evidence of an installed/usable promotion identity.

The original95 tests remain passing; three additional tests cover the six-field
37-untouched/idempotency fixture and the bounded administrator installer. Fixture
PASS does not establish a real WP UPDATE_HTTP/READBACK_HTTP or live field values.
