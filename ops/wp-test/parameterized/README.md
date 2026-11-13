# Receipt-bound parameterized WP operations

The installed WP document is still version 2 (journal/resume). This candidate is
not installed and its update is not remotely accepted. The latest real journal
read: run43440156112 attempt2, command3fdb45eb-30d6-43d5-9bc1-27fd1948d9b3,
Expert30460, VERIFIED, HTTP201 receipt, GET200, zero WP writes.

The previous candidate incorrectly compiled the target30460 and the single
Bonus推薦投注 field into the engine and worker. It also used a fixed request file.
This revision passes the Expert ID, fields and values as request data. The target
must equal the existing root-private creation journal ID, with original201 receipt,
strict marker/slug, draft, creator and exactly one WP lookup match. A request can
never authorize some other Expert merely by naming its ID.

Editable text fields are derived from the SHA-verified WPF6 source schema:
analyst recommendation / betting recommendation / analysis text groups and static
recommendation text. Identity, photos, statuses, dates and WP core remain protected.
No target ID, requested field or test value is compiled into the operation engine,
worker, entry or client. New targets require their own reviewed original receipt;
create is separate and rejected by this document. No new POST/create is possible.

`client.py read|update_acf|verify --request PATH` reads data JSON containing exactly
operation, expert_id, request_id, fields. The CLI operation overrides only the
operation; read clears fields. The manifest is data, not remote executable code.
Fields map contains one or multiple exact ACF source keys, with nonempty text.
The SSM client checks exact installed document content and pins version/hash before
any upload/SendCommand. It refuses the old journal/resume document.

A durable request INTENT and full43-field baseline precede the one partial-ACF POST.
Repeated or ambiguous requests only GET/reconcile, never resend. The expected
untouched count is43 minus requested fields, not a fixed42. Six real analysts,
six media, core, reference30436, original journal and fingerprint must match.
Same-value updates do zero writes. Unknown INTENTs block later mutations.

Source meaning: 分析師1推薦投注 through 分析師5推薦投注 and Bonus推薦投注
are six betting fields. 分析師N推薦 is a separate field. Never infer Bonus-only
from the user label 推薦投注. The exact current acceptance field selection is
pending user clarification; request.json currently retains the old Bonus-only
request as historical data and ordinary pushes run read only.

`authority.py` probes ssm:UpdateDocument using EXACTLY the already-installed latest
content. DuplicateDocumentContent proves authority without a new version; access
denial proves the boundary. It does not install candidate code, change default,
use the V2 role, send EC2 commands or write WP. No credentials/raw AWS stderr export.
A privileged fixed-executor replacement still requires legitimate WP-specific
installation authority/review; V2 deployment authority is not a substitute.
