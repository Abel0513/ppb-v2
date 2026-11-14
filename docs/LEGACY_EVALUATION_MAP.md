# Verified evaluation source map — in progress, not full acceptance

Real OS/source evidence: run43536817490 and43537392013 through the pinned V2
readonly runtime38. UID997/GID987, readonly legacy mount, no capabilities/privilege
groups, credentials hidden, source writes0, business DB writes0. No old PHP executed.

| Legacy source | Observed workflow | V2 status |
| --- | --- | --- |
| Application/Admin/Controller/AdminScheduleController.class.php:2173 | getRating returns9 source score fields; all-zero means unscored | Not integrated |
| same:2226 | saveRating writes analyst_pick then updateMonthlyAverages; analyst_name is numeric analyst ID | New V2 persistence blocked on source/schema verification; old writes forbidden |
| same:2377 | analyststats aggregates monthly outcomes, bonus/recent/streak/records, creates default evaluation if missing | Do NOT execute legacy endpoint: it has DB writes |
| same:2446–2565 | absence penalties,95% compliance bonus, weighted total with default60 | Pure LegacyScheduleCalculator implemented, not a selector/persistence layer |
| same:2682–2774 | plain-list total sums word/format; monthly-record total averages them; positive-total null gate differs | Both explicit arithmetic profiles preserved, never silently merged |
| Application/Admin/Controller/AnalystRatingController.class.php:18/50/82 | salary/rating/index,3-month scores, current/next rating, video/long/short/absence sums | Full1510-line controller read in run43540046639; salary/rating arithmetic added, selection/persistence not accepted |
| Application/Admin/Controller/AnalystPerformanceController.class.php:49 | monthly/recent10/bonus outcomes from analyst_pick, numeric analyst ID | Not implemented |
| same:173 | getMonthRanking still uses mockData/rand | NOT a production-data parity oracle; no fake ranking copied to V2 |
| Application/WordPress/Controller/LaborRewardPdfController.class.php | reward/PDF references found by search | Not modified; full output flow not yet audited |

Verified arithmetic slice: delivery attendance+10 only with zero absences;
absence contributes delivery20 and word/format40 and is in the denominator;
word/format60 ratio>=95% independently adds5, compliance rounds their mean;
professionalism is the mean of six component sums; settled>=12 required for
unrounded winrate thresholds71/70/65/60→4/3/2/1, denominator includes absences;
monthly weights30%/20%/30%/10% with interaction rounded before weighting, plus
initiative/winrate/extra adjustment. Missing required score components preserve
this specific legacy analyststats default60. Other controller defaults must not
be assumed equivalent until read. No fake range clamps or formulas introduced.

The calculator receives already-selected rows/counts. It deliberately does NOT
invent month boundaries, old SQL predicates, old/V2 analyst mapping, salary
constants, rank thresholds, permissions or LINE/LIFF replacement. No migration,
DB/test tables, fixture writes, route/UI activation, WP/LINE/Cron operation.
Production data parity, complete input selection and full feature rollout remain
pending an independently authorized readonly DB connection and source inventory.

Readonly DB provisioning is now completed by the existing DBA locally; no password
was exported. Actual run43585913910 verified ppb_legacy_ro@localhost with no global
or write grants, transaction readonly=true, and numeric SELECT succeeded. Permission
SSM814837cb-0967-475d-b23b-5122187f7446; source OS proof SSM
e40cc75e-b452-4f37-b9d9-fe37c64db1b9 (UID997/readonly mount/no privileged groups).
All eight operations PASS, zero legacy file/database writes. Live metadata matches
the DBA-created snapshot for analyst profiles, picks, monthly evaluation/rating,
and attendance. No account setup should be repeated.

## Additional verified rules — readonly run43540046639

SSM e02fb7f7-20a7-4068-8bc4-5ed48dadc4a1 through
4ba9a698-6299-4236-ba4c-7f5444340c42 read the remaining controller pages.
All eight operations PASS; UID997, readonly mount, file/DB writes0. No old PHP
endpoint executed. Source sanitized digest
`ae3a5f195368994917c9f2d480a52708e92d5153127b4a8d1563025dd7bc38cf`.

`LegacyRatingCalculator` now preserves source592–824/1167–1439:

- Short-pick level3: two-market base60/win60, three-market70/50;
  level2:50/50 and70/30; level1:25/25 and30/20; level0:20/15 and25/10.
  Missing/unknown level explicitly defaults2. Bonus picks receive no base pay,
  only win pay when is_win=1; market_type=2 is the three-market branch.
- Long article400 each; selected video schedule400 each. Selectors differ and
  must remain separate. Video source joins by name, which must be resolved locally
  under approved identity rules rather than exporting names.
- Chronological attendance status1 deducts50; every other selected status is
  treated as absence: first100, subsequent200. Lateness does not increment absences.
- Current score1–39 demotes once and flags termination;40–49 demotes once.
  If the prior two rating levels differ, two-month accumulations reset, but
  immediate demotion/termination still applies. Without reset, two scores>=68
  promote; two positive scores<60 demote. Level bounds0–3; zero is missing,
  not an automatic demotion. A missing previous level does not reset.
- Original saveMonthlyRating upserts analyst_monthly_rating and updates profile
  rating only for current month. V2 has NOT executed/recreated this persistence.

Salary filters, SQL joins/date boundaries, exact UI labels and templates remain
partly redacted/unverified. These calculators accept already-selected inputs;
no speculative DB selectors, account mapping, routes or migrations have been added.
Actual legacy-row parity and complete UI/deployment acceptance remain BLOCKED on
independent readonly DB provisioning. The added tests check source-observed
boundaries and branch precedence; they do not claim live business-data parity.

## Actual schema contract discovered after readonly activation

- pay_analyst_pick.analyst_name is VARCHAR, indexed, although original PHP uses
  numeric analyst IDs. Never equate this to the display name or a V2 account ID.
- pay_analyst_monthly_evaluation has numeric analyst_id and VARCHAR eval_month;
  unique(analyst_id,eval_month). Monthly score columns are nullable DECIMAL,
  initiative/winrate/extra are INT, feedback TINYINT. No persisted total_score.
- pay_analyst_monthly_rating has numeric analyst_id, CHAR rating_month, TINYINT
  rating_level and unique(analyst_id,rating_month).
- pay_attendance_record has id,gid,uid,attendance_status; no date column. Actual
  attendance month comes from the original schedule join, not a guessed timestamp.
- The installed DB principal intentionally has numeric/date column SELECT only.
  Thus VARCHAR identity/month keys are not yet granted and cannot be used for
  actual per-analyst/month parity. This is a precise schema/policy boundary,
  not failed DBA setup or permission to open arbitrary text/PII. Full UI, selectors,
  identity mapping and persistence are still pending. Source arithmetic is retained.

## Real record parity and newly verified manual workflow

Run43587125308, SSMd7378781-e4de-42ee-8574-f45fd5ad5f12 and
20749af0-698b-4934-ac8b-1353127dd11f:10 historical numeric score rows, independently
read twice, identical input fingerprint
f190f422431eeea859b40477dcd32b189a44fa5d2179d2eb9d3059179f4dd901.
V2 LegacyScheduleCalculator vs independent observed legacy record formulas:
20/20 comparisons PASS. This is REAL_RECORD_TOTALS_ONLY, not complete monthly
selection/identity/parity. No raw business rows are committed. Reproducible local
receipt-only verifier: tests/Support/verify_legacy_record_parity.py (stdin actual
fixed-reader receipts; report contains only counts/fingerprint/evidence IDs).

New source evidence from the same run:

- AdminScheduleController2927–2963: five nullable weekly scores; zeros count;
  interaction avg=round(mean(non-null weeks)+intval(feedback),1). Blank/null weeks
  are normalized at the form boundary, feedback added after the mean. Implemented
  separately in LegacyInteractionCalculator with five regression tests. No V2
  persistence/route/UI activation or old saveEvaluation call yet.
- updateMonthlyAverages3008–3128 selects ALL source picks with delivery>0 for the
  analyst/month, unlike analyststats' additional outcome/long/substitute predicates.
  It returns without a write if totalCount=0; updates only three automatic score
  columns, preserves existing manual scores. Never silently unify these selectors
  or overwrite existing manual fields with defaults.
- Video salary689–749 matches ONLY guest_1_name/guest_2_name/guest_3_name against
  the analyst profile name. Host/editor do not count.400 per matching schedule,
  even if the same analyst occupies multiple guest slots, because each row counts
  once. Names must remain server-side; no generic name export is permitted.

Live schema also shows schedule.market_type VARCHAR; the source uses intval with
2 as the three-market branch. It is outside current numeric/date SELECT grants.
A narrow typed-business-key extension and private aggregate-only name join are
required before full monthly/salary parity; no broad free-text grant is justified.

## Current checkpoint — supersedes earlier provisioning blockers (2026-11-14)

The eight reviewed column grants are installed and live. Do not repeat DBA setup.
Run43591147112 proved four typed business keys, four private aggregate-only join
columns, actual ppb_legacy_ro@localhost, no global/write/GRANT OPTION authority.
Private names cannot be selected or filtered by a general query. No credentials
or old business data rows are stored in this repository.

Reviewed fixed reader PR77 is installed through the existing control channel:
run43595013120, SSM95719919-049e-47e3-a894-838d46fa9f09, runtime58. V2 UID999 reaches
the same nonroot SQL compiler via a root-owned group-restricted Unix socket;
root and unrelated peers are denied. No new root-shell API or DB credential share.
The permanent original catalog hash remains
22fec733d8c5f3f9b2d862fc5248cc7c4378b76c4ee11adafd6f2bdf8cfc9e3f.

Actual month evidence: run43605575359:

- grants: SSM301e7d68-0c77-428d-b2b6-03f2e513644e;
- May snapshot: SSMc7e96041-aa44-4ac6-b0a6-2ce8337403eb;
- independent repeat: SSMc8ac29bc-abdb-470e-a8b2-6a4f9cb2f8ed;
- April overlap: SSM367b225e-866c-416c-98d7-bb9271f8c3fa.

All PASS. Seven underlying tables are visibly InnoDB, queries run in one
REPEATABLE READ / READ ONLY transaction. Complete bounded two-month coverage,
repeat fingerprint equality and overlapping-month selection agree. The independent
Python source-formula oracle and V2 PHP selectors/calculators agree in78 comparisons
for analyst2 /2026-04 and2026-05 (scores, explicit selector counts, short/long/video/
attendance pay). Input fingerprint:
fb50a2940589d62c6321b018040227e170207caf1b22af2106e25595bf166292.
Verifier: tests/Support/verify_legacy_month_parity.py. This is real source-data
calculation parity, NOT full production persistence or deployed-UI acceptance.

Exact source filters from runs43593168357/43594145748:

- source months use naive local YYYY-MM-01 00:00:00 through lastday23:59:59;
  native V2 UTC dates are converted explicitly, never shift legacy timestamps;
- rated/view profile: own delivery>0, short, resolved, non-substitute, bonus included;
- cached-update profile: ALL own delivery>0; no rows preserves existing cached3;
- absence: substitute points to subject with legacy reserved-ID>1, bonus0;
- view settled denominator includes substitutions; rating denominator excludes them;
- short salary: resolved short non-substitute, schedule join required, bonus may pay
  only the source win component; long salary: own long, schedule join required,
  no outcome/bonus/substitute predicate;
- recent10 and streak use global resolved regular non-substitute short rows;
  the original streak considers at most20 rows;
- attendance dates come from schedule join, status1 late; every other status absent;
- private video guest aggregate counts schedules once, never host/editor names.

Draft implementation now includes independent source identity domains, proven
archive-bijection mappings only, native imported-pick deduplication, admin routes/
Blade/forms/CSV/print, V2-only manual and record-rating receipts with unique request
IDs, source fingerprints and optimistic revision checks. GET never creates rows.
The same request only reads its receipt. Native V2 author IDs may equal legacy IDs
without becoming the same person. Analyst account_id remains optional; no LINE,
LIFF, legacy auth/config/entrypoint was changed or replaced.

Still pending, explicitly NOT accepted: native attendance/video entry integration;
complete original entry/PDF/self-service identity mapping; all-analyst coverage;
V2 production migration/persistence; full deployed HTTP/browser acceptance.
The page warns that native salary is incomplete until those inputs exist. PR58
remains Draft. Owner comment6877548981 authorizes continuation, CI and pushing,
not final merge, production migration/writes or publication.
