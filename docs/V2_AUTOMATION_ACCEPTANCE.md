# V2 autonomous release acceptance

Accepted deployed application: `35256c95a8e6b24b40165808e9778c489ac7ae4d`.

| Check | GitHub Actions run | SSM command |
| --- | --- | --- |
| Global MySQL metadata audit | [43483476302](https://github.com/Abel0513/ppb-v2/actions/runs/43483476302) | `4369e071-f112-4af2-aba8-cdd431120710` |
| Latest fixed runtime activation | [43505484293](https://github.com/Abel0513/ppb-v2/actions/runs/43505484293) | `99d1e2a0-0a0c-417b-9b84-11a0dde1ea35` |
| Automatic application release, attempt 1 | [43505632860](https://github.com/Abel0513/ppb-v2/actions/runs/43505632860/attempts/1) | `8ebf8a0f-af76-4b4d-bfbe-37ca260c4387` |
| Same application release, attempt 2 | [43505632860](https://github.com/Abel0513/ppb-v2/actions/runs/43505632860/attempts/2) | `c9b0b518-305a-49d2-ac18-5681d6ca5f57` |

MySQL 5.7.44 metadata audit proves all-schema visibility through the reviewed locked DEFINER metadata procedure, including incoming and outgoing foreign keys and rules. No business data is exported. Application credentials remain scoped to `ppbaos` with no legacy/global write grants.

The dedicated nonroot `ppb-v2-php.service` uses its own socket and verified V2 vhosts. Runtime 35 has SHA256 `c3f2b2c856aa3ec70eef94acc157a96fa546084630b8a06a1bd63fb81015edf5`; only the current reviewed bootstrap/runtime versions remain callable. Application source is root-owned and group-readable; PHP application execution runs as `ppb-v2`, with legacy/panel/database files inaccessible.

PR50 was merged by the existing automatic application controller after three CI gates. Release attempt 1 verified Laravel's real database/user and rolled back its metadata-only transaction (zero committed data writes), rebuilt config/routes/views, and passed five local TLS/SNI HTTP checks. `/v2/automation-check` returned 200 with `PPB_V2_AUTOMATION_PROOF_V1`; the login form and guest boundaries for games/manuscripts/accounts passed. Attempt 2 verified the operation journals and HTTP again, reported `idempotent=true`, zero program file writes and no lifecycle replay.

General V2 application changes can use the same CI → PR → automatic merge → private S3 → pinned SSM → isolated EC2 → HTTP acceptance flow. `PPB_AUTO_DEPLOY=false` remains an explicit kill switch. Control-plane, workflow, credential, WP and legacy changes are excluded from ordinary application auto-merge. Root fixed-tool changes require review and use the existing authorized activation channel; application changes do not require per-release SSM updates.

## Explicit limits

- No production DDL migration was executed in this stateless page test. Migration remains a separate guarded operation requiring fresh V2 private backup, global metadata isolation and durable execution receipts; uncertain migration outcomes stop later writes.
- Queue/scheduler service templates are installed but disabled; no business jobs or legacy Cron were started or changed.
- HTTP acceptance runs against EC2 localhost using the real hostname, trusted TLS and SNI. The Codex Cloud public-network proxy returned 403 for this host, so independent Cloud-to-public-origin reachability was not validated.
- Authenticated business workflows, WP and LINE/LIFF acceptance were not run or implied.
