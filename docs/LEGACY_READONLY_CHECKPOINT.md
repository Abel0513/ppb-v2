# Resume checkpoint — 2026-11-14 Asia/Taipei

Task: permanent OS/MySQL-enforced legacy readonly access, then implement V2
assessment with actual legacy evidence. Do not redo WP30460, metadata, FPM or
release architecture. WP PR13 remains separate and must not be merged here.

Branch codex/v2-legacy-readonly, based main6552705. Current batch adds
ops/legacy-readonly and extends the existing V2 document with a separate,
data-only legacy_readonly mode. No IAM/OIDC/bucket/FPM recreation.

Local only so far: fixed nonroot source worker, conservative PHP token view,
credential exclusion mounts, path/link guards, numeric/date-only column SELECT
compiler, a one-time DBA utility requiring an explicitly named existing admin
login-path. Original app files are never executed. No old config/password search.
The DBA utility creates only a new, initially locked readonly principal and local
root-protected credentials/catalog; no old business DDL/DML. It grants scoped
metadata REFERENCES/SHOW VIEW (insufficient for DDL without CREATE/ALTER), plus
SELECT only on reviewed non-PII numeric/date columns of base tables. No EXECUTE,
FILE, global SELECT, DML/DDL or roles. Runtime validates actor/grants/readonly tx.

Not yet installed or remotely accepted; no new PR or actual legacy source read at
this checkpoint. Original79 deployment tests and11 new readonly tests passed
locally. Full CI/install/inventory still needed. No MySQL credential has been
provided/provisioned. Do not invent a DBA login-path, reuse old app credentials,
or claim DB readiness merely from source-tool installation.

V2 assessment currently absent: only permissions evaluations.view/manage and a
"未建置" menu. No formulas/financial logic have been invented or deployed.
After source access: locate actual controllers/models/views and data sources;
request paginated token views/searches through fixed SSM; map logic to V2; require
real readonly DB identity before business-data parity tests. Unknown literals are
redacted, not guessed. Preserve LINE/LIFF identity rules.
