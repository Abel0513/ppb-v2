# Evaluation UI allowlist proposal (path + kind only)

Status: **candidate proposal only. Do not merge as an enablement.**
`PRODUCTION_CATALOG` stays empty. The worker still denies `ui_source`.
`offline-ui/structure.py` and `offline-ui/php-inline.php` stay out of `ops/deploy/isolation/build-bootstrap.py` `FILES`.
This file is not a production catalog and must not be imported as request input.

Source: completed path-only `ui_inventory` of **1687** unique legacy UI paths (`next_offset` was `null` on the final page). Format `UI_PATH_ONLY_V1` (path, suffix, size). No file contents were read.

Follows `ops/legacy-readonly/UI_REVIEW.md` and `ops/legacy-readonly/offline-ui/README.md`: collect actual paths, then review a **per-file** allowlist and structure-only parsers. No raw text reader.

## What this proposal does and does not do

- Does: list evaluation-related legacy template/CSS/JS **paths** and proposed `Review.kind` values (`html` for `.tpl`, `css`, `js`).
- Does not: fill `labels`, `dom_tokens`, `controls`, `selectors`, or `assets`. Those literals can only come from official per-file source review. Path-only inventory cannot honestly supply them.
- Does not: enable `ui_source`, widen the worker allow-list, write `PRODUCTION_CATALOG`, or promote parser/php-inline into the pinned bootstrap.

## Primary candidates (evaluation admin templates and named CSS)

These are the AdminSchedule and AnalystRating templates plus the two distinct `shift.css` files. Recommended first review set.

### kind = html

| path | size | notes |
| --- | ---: | --- |
| `Application/Admin/View/AdminSchedule/add.tpl` | 17568 | schedule add form |
| `Application/Admin/View/AdminSchedule/analysisScheduleOverview.tpl` | 3689 |  |
| `Application/Admin/View/AdminSchedule/analystRatingChange.tpl` | 0 | zero-byte stub; include but expect empty or trivial structure |
| `Application/Admin/View/AdminSchedule/analystlist.tpl` | 13117 |  |
| `Application/Admin/View/AdminSchedule/analystperformance.tpl` | 21776 |  |
| `Application/Admin/View/AdminSchedule/analyststats.tpl` | 75812 |  |
| `Application/Admin/View/AdminSchedule/attendanceRecord.tpl` | 11379 |  |
| `Application/Admin/View/AdminSchedule/csdaBroadcast.tpl` | 35154 | AdminSchedule page; treat as evaluation-adjacent admin UI |
| `Application/Admin/View/AdminSchedule/csdaUser.tpl` | 36194 | AdminSchedule page; treat as evaluation-adjacent admin UI |
| `Application/Admin/View/AdminSchedule/scheduleOverview.tpl` | 7140 |  |
| `Application/Admin/View/AdminSchedule/shift.tpl` | 38820 |  |
| `Application/Admin/View/AdminSchedule/shift1.tpl` | 34720 |  |
| `Application/Admin/View/AdminSchedule/shift_add_modal.tpl` | 18796 |  |
| `Application/Admin/View/AdminSchedule/shift_edit_modal.tpl` | 27669 |  |
| `Application/Admin/View/AdminSchedule/shift_rating_modal.tpl` | 18556 |  |
| `Application/Admin/View/AdminSchedule/videoschedule.tpl` | 28514 |  |
| `Application/Admin/View/AnalystRating/index.tpl` | 51775 |  |
| `Application/Admin/View/AnalystRating/rating.tpl` | 20575 |  |
| `Application/Admin/View/AnalystRating/salary.tpl` | 33537 |  |

### kind = css

| path | size | notes |
| --- | ---: | --- |
| `Public/Admin/css/shift.css` | 12802 | Admin CSS; size differs from Public/ui/dist/css/shift.css, so treat as a distinct file |
| `Public/ui/dist/css/shift.css` | 11561 | UI dist CSS; size differs from Public/Admin/css/shift.css, so treat as a distinct file |

### kind = js

No evaluation-named standalone `.js` paths appeared in the 1687-file inventory.
Script behavior, if any, is expected to be inline in the templates or referenced via generic AdminLTE / `Public/Admin/js` files.
Those generic libraries are **not** in this allowlist.
Inline events and any `src` references have to be discovered during per-file html review, then added as `selectors` / `assets` or as separate `js` catalog entries if a dedicated file is confirmed.

Primary count: **19 html** (including the 0-byte stub) **+ 2 css** = **21** files.

## Sensitive LIFF paths (deferred)

Do not add these to `PRODUCTION_CATALOG` unless separately authorized. LIFF / LINE client surfaces need a tighter review than ordinary admin templates.

| path | size | notes |
| --- | ---: | --- |
| `Application/Admin/View/AdminSchedule/analyststats_liff.tpl` | 25983 | evaluation stats LIFF template; handle as sensitive, defer unless separately authorized |
| `Application/User/View/Liff/broadcast.tpl` | 59768 | User LIFF; keyword-adjacent to AdminSchedule csdaBroadcast, not confirmed as evaluation admin UI; defer |
| `Application/User/View/Liff/dashboard.tpl` | 57813 | User LIFF dashboard; defer unless separately authorized |

## Keyword-only, not proposed

| path | size | notes |
| --- | ---: | --- |
| `Application/Admin/View/Tiger/tigerbroadcast.tpl` | 8693 | Tiger module; path matched broadcast only; not proposed for the evaluation allowlist |

## Related assets (not catalog kinds)

Phase B returns only reviewed relative asset references; it does not fetch images or fonts. These paths are recorded for later `Review.assets` work, not as `html`/`css`/`js` entries.

- `Public/ui/images/analyst_performance.jpg` (size 201971). ASCII path; can be listed later if a reviewed template cites it.
- Many `Public/ui/images/*.png` files on the final inventory page use Chinese filenames that look like analyst portraits. Current `Review.assets` validation requires `^[A-Za-z0-9_./-]{1,200}$`, so those names **cannot** be expressed under the existing token rules. Do not widen the regex in this proposal.
- Shared theme CSS/JS (`Public/ui/dist/css/AdminLTE*.css`, `Public/ui/dist/css/global.css`, `Public/ui/dist/js/*`, vendor `bower_components`, ueditor, generic `Public/Admin/js`) is out of scope until a reviewed template actually cites a specific file.

## Per-file review still required before any catalog entry

Each `PRODUCTION_CATALOG` entry must be trusted reviewed code/data, keyed by exact path, with a `Review` of:

- `kind`: `html` | `css` | `js` (proposed above for the candidate paths)
- `labels`: allowed static text
- `dom_tokens`: allowed class/id/token names (`^[A-Za-z_][A-Za-z0-9_-]{0,63}$`, no password/secret/credential/token/session/line_id)
- `controls`: allowed control `name` values (same token rule)
- `selectors`: allowed CSS/JS selectors (bounded charset, no long hex or secret-like tokens)
- `assets`: allowed relative references (ASCII-only under current regex; no leading `/`, no `..`)

Unknown labels, selectors, or assets must fail closed as `REVIEW_REQUIRED` without echoing source or secrets. ThinkPHP dynamic values become placeholders; default input values and condition text are not returned. Catalog entries cannot be supplied through `request.json` or Actions inputs.

## What is still needed for `ui_source` under the existing tooling

Inventory collection is complete. Enabling `ui_source` still requires official review and the existing installation path, in this order:

1. **Per-file source review** of the candidate paths (primary set first; LIFF only if separately authorized) into real `Review` literals. This proposal does not have source contents, so those literals are not filled here.
2. **Install reviewed catalog entries in `PRODUCTION_CATALOG`** inside trusted `ops/legacy-readonly/offline-ui/structure.py` (or an equally trusted reviewed data module imported by it). Not via request JSON.
3. **Add `ui_source` to the reviewed worker allow-list** in `operate()` only after that catalog exists. Today `worker.py` allows `ui_inventory` but not `ui_source`; `operate({'operation':'ui_source',...})` raises `OPERATION_DENIED`. Tests lock this in.
4. **Promote the parser and php-inline helper** (`offline-ui/structure.py`, `offline-ui/php-inline.php`) into the pinned bootstrap/runtime through the existing reviewed installation path (`ops/deploy/isolation/build-bootstrap.py` `FILES`, then the reviewed SSM/bootstrap document promotion). They are deliberately excluded today; tests assert they are not in `FILES`. Do not add them by an ad-hoc live document edit.
5. **Keep the existing remote boundaries**: OS readonly identity, no sudo, fixed roots, symlink/realpath checks, sensitive-path exclusion, size and output caps. `php-inline.php` stays stdin/`token_get_all` only (retain `T_INLINE_HTML`, no eval/include). Intermediate tokenizer stdout must not be returned, logged, or exposed as a remote API. Parser exceptions and source text must not be piped to SSM stdout.
6. **No raw text reader.** Coverage remains `reviewed_structure_subset_only`. After a reviewed enablement, any later `ui_source` batch would still go through the existing `request.json` push-triggered workflow, max 8 operations, ops only, no application deploy.

Machine-readable companion: `evaluation-ui-allowlist-proposal.json` (same candidate list; wrapper format is not `PRODUCTION_CATALOG`).

