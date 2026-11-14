# Current evaluation status — 2026-11-15 (Asia/Taipei)

Latest handoff: PR58 comment6882913861 and PR95 comment6882914814 were read in
full. Main59bf94f preview/R1-R2/DBA health work is accepted; never reapply it.
Owner reports authenticated health4/4 PASS, writes0. This is not independent list,
detail, desktop/mobile or complete payroll acceptance.

Main preview was merged into the Draft feature branch without force-push. The
owner-only GET preview, health, navigation and direct roster read are retained;
formal evaluation routes and permissions remain separate. Local integration suite
48tests284assertions PASS before salary changes. No migration/application release.

Candidate salary snapshot uses the existing append-only evaluation event JSON,
server-calculated amounts, subject/month/source fingerprint and request/revision.
No additional schema/table, payment, LINE delivery or legacy write. Readback shows
the historical amount and warns when current revision/source differs; replay only
reads the receipt. Real isolated MySQL5.7 tests cover key-order normalization while
strictly checking numeric types, replay0writes and later changes preserving history.

Actual readonly source follow-up Run43635426833: six PDF pages PASS, SSM
47428ae9-d5f4-46cb-9f85-f7993cb3bc5e through9e67e6a0-5b08-4536-bf79-681302e3b3c4.
The seventh request for the parent LineController STOPPED at the existing reader
boundary (de624cb5-bd14-41de-8b37-404186d594a2); no override or grant change. Therefore
the overall run is failure, not PASS. Safe sanitized source retained outside Git.

PDF controller source shows distinct generate-one/generate-all/send-one/send-all
flows; previous-month default, mPDF generation, private analyst identity lookup,
LINE Flex delivery and source salary/grade notice. Generation and sending are
side effects and old endpoints were NOT called. PDF short salary/long/video/
chronological absence rules agree with existing kernels; literal URLs/messages
remain redacted. Full original PDF rendering, private identity resolver and LINE
sending are not implemented or accepted. The reader neither exposes line_id nor
permits generic private-name SELECT. No analyst numeric-ID authentication substitute.

Live browser acceptance is BLOCKED in this task environment: no signed-in browser
session, browser tool failure, native input AT-SPI provider unavailable; configured
network policy denies direct HTTPS to v2.csdatw.xyz (proxy403, HTTP000). Do not turn
local Blade/fixture tests or owner health evidence into independent live UI PASS.
No password, cookie, config or credential was requested or exported.

PR58 stays Draft. migrate:true remains a candidate only; production migration,
business writes, merge and deploy need the original owner/safety gates. PR95 remains
Draft, no R1/R2, root bootstrap/policy overwrite or installed runtime change.

---

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
Python source-formula oracle and V2 PHP selectors/calculators agree in81 comparisons
for analyst2 /2026-04 and2026-05 (scores, explicit selector counts, short/long/video/
attendance pay and next-month grade). Input fingerprint:
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

Native V2 attendance/video inputs are now implemented and isolated-MySQL-tested.
Actual substitution submissions produce one attendance deduction per game/original
analyst, while the original per-pick evaluation absence rule remains separate. V2
waivers preserve one explicitly recorded submission lineage; no old attendance
row is deleted. Video dates use Taipei input/UTC storage; repeated guest slots count
once and cancellation retains history. Both use the same UUID/revision/event journal.

Still pending, explicitly NOT accepted: complete original entry/PDF/self-service
identity mapping;
V2 production migration/persistence; full deployed HTTP/browser acceptance.
Native input UI is implemented; it is not a production acceptance claim. PR58
remains Draft. Owner comment6877548981 authorizes continuation, CI and pushing,
not final merge, production migration/writes or publication.


Current draft tests:42 tests /245 assertions locally; latest GitHub CI
must be checked against the exact head before reporting its status. MySQL5.7.44
runs socket-only in a pinned network-disabled ephemeral container. ONLY session
TEMPORARY tables in its existing system schema are used, with real transactions,
unique constraints, JSON receipts and rollback after an injected event failure.
No test database or persistent test table, no deployed fixtures. Tests include
UTC month edges, imported/draft exclusion, mismatched lineage rejection, bijective
mapping, original-profile grade fallback, zero-write replay, stale revision409,
waiver effects, guest deduplication/cancellation and actual Blade/route validation.

Actual remaining authority boundary: the fixed reader deliberately does not grant
or expose analyst_profiles.line_id. The observed old LIFF flow maps that private
identifier to the numeric analyst ID. No V2 LINE/LIFF authentication provider or
approved private identity-resolution transport exists in this snapshot. Do not
replace it with a public analyst_id parameter, scrape old credentials, call an old
mutating LIFF endpoint or require analyst admin accounts. Its integration remains
a separate explicit, narrowly reviewed identity delegation; administrative
assessment development and readonly arithmetic parity do not prove it.

Migration plan:five new V2 business/audit tables, V2-only FKs to existing accounts,
games/submissions. No game/pick import or old-schema FK; manual source baselines are copied only on an explicit V2 save. The existing reviewed
release gate must prove OS/DB/service isolation, metadata coverage and an exact
private ppbaos backup before migrate. MySQL DDL autocommits: never claim transaction
rollback of a failed migration. Stop after any partial/uncertain DDL; restore the
private V2 backup under reviewed recovery, never auto-drop business history or
retry blindly. Application-file rollback leaves the new additive tables intact.
No migration or production business write has been executed for this Draft.


## Complete readonly cohort revalidation (2026-11-14)

Nullable source flags retain SQL NULL rather than silently becoming zero; nullable
schedule references cannot alias a real primary key0. Existing arithmetic kernels
were retained. Actual eligible roster34 (25 with rows), two-month snapshots,
2754 comparisons PASS across Runs43611427261/43612034105/43612532291/
43613070040/43615571202. Cohort digest:
4e7cd449f85be869350d5b0de5852a66b312f30e0ea5b96c8bbefab485038b0a.
Video-positive source case is present; a separate June9 snapshot proves the real
late-attendance case. Empty analysts are tested, not silently omitted. Snapshots
are individually readonly/RR with7 InnoDB tables, not globally atomic across runs.
Reader installation Run43614951108, SSM20c34a0f-62f8-436e-b289-db54d0a14499,
retains original catalog and grants. Legacy writes0. This source parity does not
prove deployed UI, private self-service identity or production persistence.


Final readonly bookend Run43615768505 PASS: numeric roster unchanged, schedule
primary key0 absent, repeated May source fingerprint unchanged. SSM commands:
7641065d-55cb-4261-b92f-f3e8f0cd4d92 (roster),
1a36fd57-c0f8-42ed-999b-3e08afc678c9 (zero-key),
769799f0-91eb-4996-9384-305e941b9020 (repeat). All legacy writes0.
