# PPB V2 (皮皮寶／協會評鑑系統) — Codex Working Rules

Effective: 2026-11-12 (Asia/Taipei). This repository is a **source-code snapshot** for review and controlled development, NOT the production deployment directory.

## Read first and resolve conflicts carefully

1. Read this file, then `ppb_codex_handoff_20261112.md`, then the actual repository code and existing test definitions before drawing conclusions.
2. Priority: latest explicit user decision > verified current production evidence (if separately provided) > repository code as a snapshot > handoff with evidence labels > older reports. Do not silently treat a historical report as a fresh production verification.
3. The complete ThinkPHP legacy system, WP receiver / plugins, LINE integration, production database and host logs are **not** included here. Report missing evidence explicitly; do not invent legacy behavior.
4. Do not overwrite prior approved work merely because it lacks an obvious test in this snapshot. Separate confirmed, historically reported, and still-unverified states.

## First Codex task: READ-ONLY audit

Until the user explicitly approves a **specific implementation batch**, your job is to inspect and report only. Do not edit repository files (including this file), install dependencies, generate or modify lock files, make commits, push, merge, create PRs, or deploy. A read-only task does not authorize writing a report into the repository; deliver it in the task response.

Safe actions for this first audit: read and search source, inspect Git history and diffs, inspect configuration keys without secrets, check installed tool versions, use non-mutating static inspection such as `php -l` on identified files. Do not boot Laravel or run framework commands whose effects are uncertain.

**Do not automatically run Laravel Boost setup** (`composer require laravel/boost --dev`, `php artisan boost:install`), curl-to-shell installers, Composer/NPM installs, migrations, seeders, tests that write data, or any scripts that alter application state. If a tool or dependency is missing, report it rather than changing the environment during this audit.

The Cloud environment must not be given production DB, WP, LINE, SSH or API credentials. Do not call external WP/LINE endpoints, update Cron, or attempt production server access.

## Non-negotiable project safeguards

- Preserve all existing **legacy production** entry points and workflows. V2 is an additive/compatible modernization; never change the old system, production database, published WordPress behavior, LINE Bot or Cron without separately approved deployment steps.
- Batch related changes, batch testing and a **single consolidated acceptance**. Do not create a new test database or persistent test tables; never insert fake data into the production database.
- Keep GitHub work in isolated branches for approved development. Produce diff, verification evidence, observed limitations and rollback plan; **no direct main modification, merge, push to production or release** without the user's express approval.
- Read on-site current files and versions before proposing deployment. GitHub code may diverge from deployed PHP files.
- Never request, print or commit `.env`, `db.php`, `line.php`, passwords, credentials, tokens, certificates, private JSON or production DB backups. Review changes for accidental hard-coded credentials.
- `app/Console/Commands/PpbBatchVerify.php` contains account/game creation and save calls despite using a transaction and rollback. Do not run it against production or presume every failure path is safe.

## Fixed business meanings

- Analysts historically authenticate, submit and edit using **LINE / LIFF**, not mandatory V2 admin credentials. Legacy identity uses `analyst_profiles.line_id`. In `pay_analyst_pick`, `analyst_name` is the **numeric analyst ID**, not a name. V2 `auth:ppb` plus `ppb_analysts.account_id` is not an authorized mandatory replacement; `account_id` may not become a required field for existing analysts.
- `pay_schedule.expert_id` points to a WordPress **Experts custom content** record (`post_type=expert`), not an ordinary posts ID or an analyst ID. `pay_schedule.id` is the source match record.
- Once **any** short article, BONUS, or long article for a game has successfully been published to WP, lock that game's entire G/E display-number assignment. Inserting, deleting, cancelling or reordering must not move or release locked numbers.
- Scheduling insert/reorder must respect existing unpublished/unrestricted records and leave the existing scheduling card UI and entry points intact.
- Legacy migration is limited to games whose **game date** is 2026-10-15 or later, plus their directly related analyst assignments, submissions, WP publication/locks, attendance and substitute records. Do not broaden the scope.
- Historical progress reported on 2026-11-10: WP Expert draft `30436` reached **43/43 ACF** matched on a draft update, superseding older 37/43 observations. This is a *historical reported verification*, not proof that the entire publication, LINE, long-post or Cron pipeline is accepted. Do not re-open 37/43 as an active blocker without newer counterevidence.

## Technical baseline and validation

- Repository: `Abel0513/ppb-v2`, base branch `main`; Laravel 13.39.0 and production PHP 8.4.27 were reported at the initial import.
- Production route: `v2.csdatw.xyz`; Nginx root `/www/wwwroot/ppbaos.com/public`; actual Laravel root `/www/wwwroot/ppbaos.com`. These are **context only**; Codex Cloud must not assume it can access or modify them.
- Cloud onboarding historically reported a 200-vs-302 response expectation mismatch and initial environment save failure; do not classify these as proof of a current production bug. The user later published the environment.

## Audit deliverable

For the first task, return in Traditional Chinese:

1. What was **proven from code** (with exact paths/line numbers), what was **user-approved**, what was **historically reported**, and what is **still missing/unverified**.
2. A trace of V2 manuscript -> review -> WP Expert + ACF / long posts -> returned IDs and publication lineage, including the LINE / LIFF identity boundary. Mark external components absent from the repo.
3. Prioritized gaps and the **single proposed implementation batch** to seek approval for, with acceptance criteria and non-writing/static test options first.
4. Explicit confirmation of files changed (expected: NONE), database writes (NONE), network side effects (NONE), Git pushes/merges (NONE).

Do not start implementation until the user approves the proposed batch.
