# Existing V2 channel: permanent legacy readonly operations

This extends PPB-V2-Controlled-Deploy with data-only Mode=legacy_readonly. Release
is the default mode, keeping the existing V2 release path. No new OIDC provider,
IAM role, S3 bucket, Runner, FPM or public service. Tool code is reviewed/pinned in
the same fixed bootstrap/runtime, never taken from a source request or app archive.

## OS enforcement

Dedicated ppb-legacy-ro system UID, private primary group, no supplementary groups,
nologin home, no sudo. A fixed root dispatcher starts only the root-owned worker
using systemd-run: ProtectSystem=strict, BindReadOnlyPaths=legacy root,
NoNewPrivileges, empty capabilities, PrivateNetwork, AF_UNIX-only, private tmp,
256MB/12tasks/60sec limits, credential-directory/file exclusion mounts. V2 code,
private deployment area, DB data, panel and homes are inaccessible. The child checks
actual UID, mount readonly, effective capabilities, NoNewPrivs and groups BEFORE
returning evidence. No old chmod/chown/ACL/write is performed. Unreadable old source
stops rather than changing legacy permissions. Python -I and PHP tokenizer only;
old PHP is never included or executed. FD traversal rejects symlinks/hardlinks.

inventory/source/search return only code paths and conservative token views.
Conf, .env, db.php, line.php, backups, uploads, runtime, credentials and private
files are excluded both by path policy and mount boundary. Comments, inline HTML,
interpolated strings, unknown assignment values and personal literals are removed.
Only provable program/schema identifiers and masked SQL structure are retained.
Unknown business strings must be resolved from approved evidence, never guessed.
The source digest covers SANITIZED text, not a hash oracle for secret values.

## Independent MySQL enforcement

No old application/admin credentials are searched or reused. DB operations stop
until a DBA provisions the dedicated ppb_legacy_ro@localhost account. Only an
explicitly named existing authorized MySQL management login-path can run the
one-time utility; Codex does not invent or access one. Native-password material is
newly generated locally and remains in root:ppb-legacy-ro0640 configuration, never
in argv/logs/GitHub/artifacts. No test DB or persistent table.

The DBA utility uniquely identifies the legacy schema using three known source
anchor tables, rejects ppbaos/system schemas, account/config collisions and views
for data access. Grants: SELECT at column level for non-PII numeric/date business
fields, plus scoped REFERENCES/SHOW VIEW for live metadata visibility. These two
metadata privileges provide no CREATE/ALTER/DROP capability by themselves. No
INSERT/UPDATE/DELETE/DDL/FILE/EXECUTE/global privileges/proxies/roles/grant option.
Account is created LOCKED and unlocked only after grants and private files exist.
No existing principal or legacy business schema/data is modified.

Every query verifies CURRENT_USER, MySQL5.7 version, ALL effective grants, exact
catalog/schema/columns and a READ ONLY transaction. Request data is compiled to
SELECT with bound values; no raw SQL, arbitrary expression/join/routine/path/URL.
Maximum50 rows/12 columns/8 filters, offset5000, MAX_EXECUTION_TIME1000ms,
connect/process limits. Returned data is checked numeric/date again. Metadata
snapshots are labelled as snapshots; live columns/indexes/keys are separate.

## Operations / real evidence

Edit request.json through reviewed PR or dispatch the existing main workflow.
Examples: inventory(offset,limit), source(path,offset,limit), search(query,limit),
permissions, db_permissions, schema(table), select(table,columns,filters,limit).
Main-only Actions assumes the EXISTING V2 role and pins exact installed document
content/version/hash, sends only a base64 JSON parameter, and collects bounded
stdout. No document update or arbitrary shell is available in the read client.
DB readiness is NOT implied by source-tool installation. Each operation records
its actual Actions/SSM result; do not substitute offline tests for host evidence.

## V2 application transport (same fixed reader, no DB credential copy)

The reviewed installer can install a V2-only Unix socket and a dedicated NONROOT
ppb-legacy-ro service. Only the actual ppb-v2 OS UID is accepted via SO_PEERCRED;
root/other UIDs are rejected. No TCP endpoint, sudo, source-file operation, shell,
request SQL/DSN or access to either application tree exists in this transport.
The PHP query compiler, catalog, column-grant checks, readonly transaction, private
name denial and limits are the SAME ones used by SSM. V2 receives no credentials.
Only seven evaluation tables are exposed to the application, not unrelated legacy
business tables. Peer and privacy-denial probes execute during installation and
must pass before the installer can report DB readiness. Existing IAM/OIDC/S3/SSM,
V2 FPM, vhosts and old services are untouched. New own units are hash-pinned and
backed up; a foreign unit/socket collision stops without overwriting it.
