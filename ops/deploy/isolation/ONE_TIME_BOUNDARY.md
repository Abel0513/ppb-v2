# V2 standing authorization and one-time technical boundary

Owner decision: 2026-11-13 Asia/Taipei. Full V2 source/data/migration/dependency/
queue/service/deployment/merge authority; legacy system remains strictly protected.
This is not proof that AWS/SQL/OS permissions have been provisioned.

## Reused infrastructure

Account 429724121147, us-east-1, instance i-0135d6b2fac7a54c2;
PPB-V2-GitHub-Deploy-Role, existing private release bucket and
PPB-V2-Controlled-Deploy document/resource. No new runner/provider/bucket/instance.
WP stays in PR 13 and its WP role/document; no WP changes in this V2 PR.

## Program changes in this PR

* Direct Codex Cloud -> separate V2 application PR -> 3 trusted CI checks ->
  exact-head auto-merge -> explicit release workflow dispatch. GitHub workflow
  tokens suppress downstream events, so dispatch is explicit and binds source PR,
  merged commit and tested merge parent. No new PAT/subscription Runner required.
* Full V2 app/config/assets/database/dependency paths allowed. Credential/runtime,
  WP, legacy and privileged control-plane files are excluded from this automatic
  app merge path. This setup PR must be reviewed/merged separately under owner grant.
* PPB_AUTO_DEPLOY=false is a kill switch; absent no longer means a missing owner
  authorization after this explicit standing grant. Technical deployment is still
  blocked until the certified isolated executor is installed.
* Release client reads actual fixed document, verifies version/hash and all
  callable versions BEFORE S3 upload/SendCommand. Old root-evaluating provider
  checks are not used by the new isolated document. No generic shell command input.
* Fixed root code copies only V2 paths and private deployment artifacts; app PHP,
  Artisan migrations/cache/queue/schedule run as dedicated ppb-v2 in systemd sandbox.
  No root app autoload. Actual web service isolation is also required, not just CLI.
* PDO probe uses only fixed read-only metadata queries without bootstrapping the
  app. Effective grants reject global privileges, roles/proxy ambiguity, GRANT
  OPTION, schema wildcards and write/execute outside the verified V2 schema.
  Privileged object definers and cross-schema cascade constraints are rejected;
  a DBA must independently certify full metadata visibility/ownership once.
  Optional old schemas may have SELECT/SHOW VIEW only. No high-privilege shared DB.
* A reusable nonroot ppb:runtime-check verifies the actual Laravel DB actor/database
  against the fixed PDO grant probe and begins/rolls back a transaction with no
  data writes or tables. Feature-specific business acceptance remains separate.
* Migration requires a fresh private, checksummed, same-release V2-only backup.
  MySQL DDL is NOT transactionally rolled back. INTENT is durable; unknown outcomes
  block further mutation and must be diagnosed, never blindly replayed or declared
  successful. Program rollback is not presented as DB rollback.
* resources/ppb/release.json is bounded data: migrate boolean, cache list
  config/routes/views, queue_restart boolean. resources/ppb/acceptance.json holds
  <=40 bounded local GET/status/content expectations. Original 4 guest checks
  remain mandatory. Neither file supplies executable root commands or URLs.
* CI runs PHP lint, fixture deploy/isolation tests, route:list and PHPUnit with
  no reachable DB, no SQLite/new test DB/tables and no migrations/seeds. Existing
  root route test is corrected to the actual V2 admin redirect.

## Actual unknowns before management setup

Current exact deployed DB name, effective user/grants, V2-owned schema/tables,
nonroot web UID/sandbox and current installed fixed-tool bytes have NOT been
verified through existing preflight. The existing preflight exposes only its
fixed health checks; it is not an arbitrary filesystem/DB inspector. Do not
infer these facts from config/database.php defaults or ppb_ table prefixes.

If V2 currently shares the legacy schema/account, do not grant schema-wide DDL
there. A separate **production V2** schema/account may be needed, with only
independently confirmed V2-owned data copied; legacy rows/tables never moved,
modified or deleted. This is not a test database. No SQL account/grant changes
have been performed by this PR.

## Exact once-only authority difference

Runtime role: PPB-V2-GitHub-Deploy-Role.
Target: arn:aws:ssm:us-east-1:429724121147:document/PPB-V2-Controlled-Deploy.
Read-only delta (only if actual audit shows missing): GetDocument,
DescribeDocument, ListDocumentVersions. See iam/runtime-read-delta.json.
Existing SendCommand document+instance, GetCommandInvocation, release-prefix
S3 PutObject and instance GetObject are reused; do not widen them to wildcard shell.
No IAM write privileges are required for daily release.

A trusted management identity (NOT the unrestricted development/runtime job)
needs UpdateDocument, UpdateDocumentDefaultVersion and DeleteDocument scoped only
to this same V2 document, plus its read/version inspection. See
iam/management-delta.json.
DeleteDocument is only for retiring explicit old non-default versions after
archiving their JSON/hashes. Do NOT delete the document as a whole or touch WP.
Reason: SendCommand resource scope alone does not stop callers selecting old
versions that evaluate candidate PHP as root. Do not invent an unsupported IAM
DocumentVersion condition; remove unsafe callable versions and retain audit copies.

The management session also needs the already-existing fixed SendCommand target
(document+instance) to execute the one-time reviewed bootstrap. No blanket
AWS-RunShellScript authority or EC2 login is needed. Current Cloud has no such
management identity; owner consent alone cannot add IAM actions.

## Concrete management artifacts (not installed by CI)

1. Reviewed, deterministic private S3 tools archive, produced by build-bootstrap.py
   and uploaded by the main authority audit under releases/bootstrap/<sha>/fixed.tar.gz.
   No credentials, SQL dumps, .env or app payloads in this archive.
2. PPB-V2-Controlled-Deploy.bootstrap.ssm.json: next version of the SAME document,
   fixed object key+SHA; installs only the fixed tool allowlist into private scripts,
   backs up prior fixed bytes, and returns bounded V2 inventory. No runtime shell
   input, SQL grants, legacy paths, web config or app-source mutation.
3. Review actual inventory. Provision a dedicated ppb-v2 OS identity, a dedicated
   V2 SQL principal with no legacy write/global/role authority, and root-private
   policy/isolation.json with independently verified exact database ownership.
   policy.example.json deliberately uses nulls; it cannot silently activate writes.
   Do not read/reuse old private DB/root credentials to bypass this requirement.
4. Configure dedicated ppb-v2-php.service (V2 only): User ppb-v2,
   ProtectSystem=strict, NoNewPrivileges=yes, empty capabilities, writable only
   /www/wwwroot/ppbaos.com, read-only .env, PrivateTmp/Devices, inaccessible legacy
   root/panel/private deployment area, IMDS IPv4/IPv6 denied. Verify kernel/service
   enforcement, no www/shared UID/group access and only V2 vhost upstream changes.
   Preserve old Nginx configs/entrypoints/cron. Back up/clear inherited V2 config
   cache and rebuild under the isolated UID after safe credentials are installed.
5. Install the runtime isolated document version as default; archive/delete old
   unsafe versions. Leave only reviewed bootstrap/runtime versions callable.
6. Run runtime inventory/audit then one authorized V2 release. Verify actual grants,
   paths, nonroot code execution, backup/migration/cache, fixed local HTTP checks,
   legacy boundary and correlated command/commit results. Do not mark ready sooner.

These are one infrastructure isolation activation, not per-feature setup.
Normal later features use PR code + lifecycle/acceptance DATA; no IAM/SSM SHA
reauthorization is needed unless the privileged executor/security boundary changes.

## Limits honestly retained

A user-started Cloud task is supported. Automatic Issue-label -> background Codex
execution is still the separate inactive subscription-runner workflow; no runner
was added or claimed online. Auto-merge's workflow-token authority itself must be
confirmed by a real ordinary V2 PR; this control-plane PR cannot certify its own
app auto-merge route. Guest acceptance does not prove logged-in business behavior;
feature-specific tests/authorized read-only auth evidence are needed for those.
No live DB/schema changes, migration or new isolated runtime success is claimed
without actual EC2 evidence. Failures can require privileged recovery when DDL
outcome/ownership is unprovable; they must never trigger automatic destructive SQL.
