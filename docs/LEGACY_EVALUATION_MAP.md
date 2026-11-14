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

Actual DB boundary: run43536817490 command6f505209-079f-474e-8840-996632db4201
STOP READONLY_DBA_PROVISIONING_REQUIRED. New readonly credentials are absent;
no old app/admin password is searched or reused. One-time existing DBA procedure:
ops/legacy-readonly/DBA_ONCE.md. Account creation/provisioning is NOT completed.

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
