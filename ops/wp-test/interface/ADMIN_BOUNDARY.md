# One-time authority boundary (not installed / not remotely accepted yet)

Keep the existing OIDC provider, private S3 bucket, instance, instance role and
`PPB-V2-WP-Draft-Test` document. No CLI Runner, EC2 login or runtime SHA reapproval.

## Administrator-only setup

1. Protect `main`: PR required, at least one independent approving review,
   dismiss stale reviews, require latest push approval and WP offline CI, block
   force push/deletion and all bypass (including administrators / automation).
   Require CODEOWNERS review for `ops/wp-test/interface/**`,
   `.github/workflows/**`, `tools/wpn2/**` and `ops/wp-test/result-envelope.py`.
   The developer/agent must not be an approving owner or have protection bypass.
   This PR author is Abel0513: GitHub will not allow that same account to approve
   its own PR. The initial CODEOWNERS owner entry must be supplemented/replaced
   with an actual independent reviewer account before enabling promotion; we
   cannot invent an available reviewer identity or claim this is configured.
2. Create GitHub environment `wp-interface-promotion`: only protected `main`,
   independent required reviewer, prevent self-review. Do not run promotion on
   the development branch. A separate protected review branch is possible, but
   must be configured and verified before use; this PR is NOT merged by Codex.
3. Add only a dedicated promotion role `PPB-V2-WP-Interface-Promoter` using
   `promotion-trust.json` and `promotion-policy.json`. Do not grant its document
   update privileges to the existing development OIDC role. The trust assumes
   the currently verified custom GitHub sub includes immutable repo/owner IDs
   and ref; verify signed OIDC claims before enabling it if customization changes.
4. After independent review and authorized merge, run the protected-main
   `Promote reviewed fixed WP interface` workflow once. It replaces only the
   existing WP document's default version, retains old versions, and verifies
   content. No IAM/S3/instance/app/DB changes. Existing developer SendCommand
   permissions for this same document/instance remain sufficient.

These are genuine authorization changes, not actions available to the current
Cloud identity. No claim is made that branch protection, reviewer configuration,
role or fixed interface has already been installed. The current PR remains open.

## Repeatable operations

* Development push: GitHub-hosted isolated PHP/Python regression tests; AWS job
  sends only a JSON request to private S3, calls installed `journal`, authenticated
  WP GET verifies the existing draft, returns bounded receipt + Command ID.
* Explicit acceptance dispatch: `resume` executes only reviewed PHP embedded in
  the installed document. It first requires CREATE_HTTP 201 in the permanent
  journal, strict marker/slug, authenticated creator and exact one existing draft.
  Missing/ambiguous state stops without create. It continues existing ACF tests
  and independent GET using original WPF6 connector, no DB writes.
* General development PHP/fixtures/controller edits cannot alter EC2 code. They
  can be developed/tested repeatedly on Actions without AWS authorization.
* Changing production-executable PHP, HTTP/write whitelist, credentials access,
  journal rules or operation names is HIGH RISK. It requires independent PR/CI
  review and protected promotion, **not** per-version CloudShell administration.
  `runtime/` is the reviewed WPN2 snapshot, not a checkout of development PHP.
  Updating it deliberately changes the rendered document and requires promotion.

Root is retained solely for the already-root-owned connector and journal.
No unreviewed candidate code, shell argument, URL, path, package SHA or package
upload is accepted. Operations are exactly `journal` and `resume`; both refuse
missing journal. Fixed executor changes remain privileged changes, so arbitrary
unreviewed production-code debugging is intentionally not fully automatic.

## Evidence and rollback

`client.py` validates installed document content, pins service version/hash,
never retries SendCommand, polls the fixed instance and emits correlated results.
An unknown command outcome is investigated, not resent. Do not delete journal.
Rollback is administrator/protected-promotion selection of a previously reviewed
SSM version. Never roll back to the old create entrypoint while testing resumes.

The role is limited to one document, with no SendCommand, IAM, S3 or EC2 authority.
Compromising the existing developer role cannot update the fixed code. S3 only
holds equality-checked JSON (operation/commit/run/attempt), never executable code.
AWS administrator policy/ruleset changes remain out of reach of Codex's identity.

### Observed GitHub administration blocker

On this implementation turn GitHub `GET /repos/Abel0513/ppb-v2/rulesets`
returned HTTP 403: **"Upgrade to GitHub Pro or make this repository public to
enable this feature."** Do not make the private repository public. The installed
GitHub app also gets HTTP 403 `Resource not accessible by integration` reading
`branches/main/protection`. We cannot certify or administer the required review
boundary. An administrator must enable the private-repository protection feature
(e.g. an eligible GitHub plan) and verify the settings above BEFORE granting the
promotion role. A role limited to a branch name without protected review is not
an acceptable substitute. Do not grant the role while this prerequisite is unmet.
