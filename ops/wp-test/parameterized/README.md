# Parameterized WP operations — candidate, not installed or live accepted

Current grant: only Expert **30460**, only **Bonus推薦投注**. The request manifest
sets its value to **Codex測試123**. The other 42 ACF values, all six source analysts,
six source images, core metadata, old Expert 30436 and original journal/fingerprint
are compared before and after. The original WPF6 connection/readonly profile
mechanism is reused. No code/URL/path comes from S3 requests.

`read` is GET-only. `update_acf` POSTs a partial ACF `fields` map containing only
the allowed field, never core content. `verify` is GET-only comparison against a
separate private update ledger. Every write request needs a stable request_id;
its durable INTENT and complete private baseline are fsynced before POST. Any
retry, timeout or crash after intent is GET-only reconciliation. Conflicting IDs,
unknown pending operations and changes outside the requested field stop safely.
A successful same-value request performs zero POSTs. State is separate from the
permanent creation journal, which is never rewritten or deleted.

Creation is deliberately a separate authorization/entrypoint: `create` is rejected
with CREATE_REQUIRES_SEPARATE_AUTHORIZATION. Original WPN2 creation code and its
201 receipt are retained as historical creation machinery, not invoked by this
operation document. A future new-draft grant must specify its own identity,
marker/receipt and new-create idempotency policy; this change does not authorize
another draft or pretend to implement unrestricted new-draft creation.

The installed v1 journal/resume document is unchanged. After a user authorizes
a data request, a same-branch push marked [wp-update-acf] or [wp-verify-acf]
invokes that bounded operation; ordinary pushes request read only. This is an
operation selector, not code approval: every call still requires the installed
reviewed document to match exactly, then passes only the manifest data. `document.json` here is a
candidate replacement of the same WP-only SSM document, not a new architecture.
The client pins the installed version/hash and requires exact candidate content
before any S3 upload or SendCommand. It never installs anything or uses the V2
deployment role. Candidate read/update/verify cannot run until this reviewed
executor is installed by an identity authorized for UpdateDocument (and
UpdateDocumentDefaultVersion when using default). That authorization is absent
from the existing WP runtime role; no CloudShell script or IAM expansion is
included here. Installing this stable data interface once allows later allowed
text changes via requests without rebuilding or reauthorizing executable SHA.
Do not grant unrestricted document replacement to development branches.

The shared original journal lock serializes against old WPN2 resume. After
candidate activation do not rerun old fixed-v1 resume, which retains its original
Codex測試 test contract; old clients also reject a changed installed default.

Offline tests exercise real PHP worker -> fixture HTTP -> JSON -> Python ledger,
43-value compare, partial one-field POST, exact old-ID protection, separate create,
request conflicts, crash/lost-response/no-resend, unchanged journal/fingerprint,
source analysts/media and read-only verification. Fixture PASS is not WP PASS.

Rollback: keep prior reviewed document version and private operation receipts.
Do not clear creation/update journals. Reverting a WP value is another explicit
request with a new ID and the same before/after verification, not an automatic
rollback or a repeat of the original create test.
