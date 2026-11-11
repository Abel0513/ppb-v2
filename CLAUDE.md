# PPB V2 Agent Entry Point

This repository is the 皮皮寶／協會評鑑系統 V2 project.

**First read [AGENTS.md](AGENTS.md) and [ppb_codex_handoff_20261112.md](ppb_codex_handoff_20261112.md).** Their safety constraints, business definitions and evidence hierarchy apply to all assistants using this repository.

**Only the first onboarding audit is read-only:** no file edits, dependency installation, Laravel Boost setup, migrations, seeders, writable tests, external service calls, Git push or deployment. After the user approves an implementation batch, assistants may modify V2 code and confirmed V2-owned data/schema, run appropriate batch tests, and create a branch/PR. Legacy-system data remains strictly non-writable. Do not create a separate test DB. Production rollout and destructive V2 changes require a separate approval gate. Do not run automatic bootstrap scripts merely because tooling is absent.

Previous generic Laravel Boost onboarding commands were intentionally replaced because they would alter this snapshot before an approved implementation batch. Preserve all legacy behavior and distinguish legacy source tables from V2-owned target tables, even when sharing one DB instance.
