# Resume checkpoint — 2026-11-14

Do not restart previously accepted AWS/FPM/metadata/WP work. Main PR60 merged
2f0a968fd9e969391d44555229a3265f1c26da8c. Permanent source reader is operational.

## Actual remote evidence

- Run43541423551: fixed tools updated through existing V2 reviewed installation;
  SSM d6b095b5-1c9b-4dc7-b75e-cacf744e0808. Bootstrap41/runtime42, old40/39 retired.
  Reader UID997, new private tool files only, legacy file/database changes0.
  V2 service already_configured true; V2 scoped ppbaos actor verified; HTTP
  /admin/login200 and guest protected routes302. No assessment app deployment.
- Run43536817490: OS proof has readonly mount, no privilege groups/capabilities;
  permissions SSM f266ee2c-9845-4f9e-80cd-3c6450ada728. DB operation stopped
  READONLY_DBA_PROVISIONING_REQUIRED, SSM6f505209-079f-474e-8840-996632db4201.
- Run43537392013: eight source reads PASS, first evaluation/schedule controllers.
- Run43540046639: eight remaining AnalystRatingController pages PASS. First SSM
  e02fb7f7-20a7-4068-8bc4-5ed48dadc4a1; last4ba9a698-6299-4236-ba4c-7f5444340c42.
  All prove UID997 readonly source mount, source/database writes0. No legacy PHP
  execution or legacy business rows exported.

## Implementation, not full business acceptance

Draft PR58, branch codex/v2-evaluation-calculator: LegacyScheduleCalculator and
LegacyRatingCalculator plus13 regression methods. Source map in
`docs/LEGACY_EVALUATION_MAP.md`. Monthly scoring, separate record totals, rating
threshold/reset/termination, short/long/video payments and attendance deductions.
Original calculation-only commit d293835 had three successful CI suites; follow-up
3de6c11 adds verified salary/rating rules, await its final CI. Keep PR draft until
identity/schema/input selectors and actual data parity are verified. No evaluation
routes, persistence, migration, application deployment or full UI acceptance yet.

## Genuine one-time remaining boundary

Independent legacy readonly DB credentials do not exist. Do not search/reuse old
application/admin passwords or assume an implicit root DB connection. Existing DBA
must supply only an explicit existing local login-path or root-owned private MySQL
management defaults path to reviewed `ops/legacy-readonly/admin-cloudshell-once.sh`.
It uses existing administrator SSM rights, verifies installed dba-once.py SHA and
creates ONLY the new scoped readonly principal/local private reader config.
No IAM extension, shared DBA credentials export, old business writes or new DB.
If no existing authorized management connection exists, report this actual gap.
After its receipt, resume existing db_permissions/schema/bounded SELECT operations;
then verify exact SQL selection, analyst identity, templates and actual parity before
formal V2 assessment persistence/UI deployment. Source-only arithmetic is not parity.
