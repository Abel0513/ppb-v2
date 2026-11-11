# PPB V2 (皮皮寶／協會評鑑系統) — Codex Working Rules

Effective: 2026-11-12 (Asia/Taipei). This repository is a **source-code snapshot** for review and controlled development, NOT the production deployment directory.

## Read first and resolve conflicts carefully

1. Read this file, then `ppb_codex_handoff_20261112.md`, then the actual repository code and existing test definitions before drawing conclusions.
2. Priority: latest explicit user decision > verified current production evidence (if separately provided) > repository code as a snapshot > handoff with evidence labels > older reports. Do not silently treat a historical report as a fresh production verification.
3. The complete ThinkPHP legacy system, WP receiver / plugins, LINE integration, production database and host logs are **not** included here. Report missing evidence explicitly; do not invent legacy behavior.
4. Do not overwrite prior approved work merely because it lacks an obvious test in this snapshot. Separate confirmed, historically reported, and still-unverified states.

## First Codex task: READ-ONLY audit

**Only the first onboarding/audit task is read-only.** Before the user approves a specific implementation batch, inspect and report only: no edits, dependency installation, lockfile generation, commits, pushes, merges, PRs or deployment. Deliver the first audit in the task response, not as a new repository file. **This is NOT a permanent ban on coding or V2 database work.** After the user approves an implementation batch, Codex may independently modify V2 code, run appropriate tests, adjust V2-owned schema/data, and prepare a PR within that approved scope, without asking for permission for every file or row. Production application rollout and destructive or irreversible changes still require a separate acceptance gate.

Safe actions for this first audit: read and search source, inspect Git history and diffs, inspect configuration keys without secrets, check installed tool versions, use non-mutating static inspection such as `php -l` on identified files. Do not boot Laravel or run framework commands whose effects are uncertain.

**Do not automatically run Laravel Boost setup** (`composer require laravel/boost --dev`, `php artisan boost:install`), curl-to-shell installers, Composer/NPM installs, migrations, seeders, tests that write data, or any scripts that alter application state. If a tool or dependency is missing, report it rather than changing the environment during this audit.

For the **first read-only audit**, do not connect any production DB or external WP/LINE endpoint, provide credentials to Cloud, update Cron or access the production host. For approved future implementation batches, use only explicitly provisioned and scoped access to V2-owned resources; never paste secrets into tasks or GitHub. Codex Cloud does not automatically have access to the V2 production database.

## Non-negotiable project safeguards

- **Legacy data is protected:** do not INSERT/UPDATE/DELETE or migrate data in any legacy-system table or legacy production database, even when the legacy and V2 tables share one MySQL server/schema. Legacy code and data may be inspected read-only with authorized access; preserve legacy entry points and workflows. Do not change the old production code, WordPress/LINE production publishing or Cron as part of V2 changes.
- **V2-owned data is writable in an approved implementation batch:** reads, CRUD operations and schema migrations affecting **confirmed V2-owned** tables are allowed. Before executing writes against deployed V2, verify exact target database and table ownership, affected records and dependencies, backup/recovery plan, and scope of authorization; do not assume a database name or `ppb_` prefix proves ownership. For data copied from the legacy system, protect the source legacy records and assess shared-table/linked-record effects. Never write legacy tables by accident.
- Batch related changes, batch testing and a **single consolidated acceptance**. Do not create a new test database or persistent test tables. Do not seed fake or test records in the deployed V2 database; use non-destructive checks or specifically approved isolated test data with cleanup and evidence.
- Keep GitHub development work in isolated branches. Within an **approved batch**, Codex may edit code, execute permitted V2 tests, create commits, push its development branch and open a PR. Produce a diff, verification evidence, observed limitations and a rollback plan. Do not directly modify `main`, merge, or deploy to production without the user's final acceptance. Destructive V2 production data changes need explicit approval after impact review.
- Read on-site current files and versions before proposing deployment. GitHub code may diverge from deployed PHP files.
- Never request, print or commit `.env`, `db.php`, `line.php`, passwords, credentials, tokens, certificates, private JSON or production DB backups. Review changes for accidental hard-coded credentials.
- `app/Console/Commands/PpbBatchVerify.php` contains account/game creation and save calls despite using a transaction and rollback. Do not run it against either legacy or deployed V2 production data unless fully redesigned/audited and explicitly approved; do not presume every failure path is safe.

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
