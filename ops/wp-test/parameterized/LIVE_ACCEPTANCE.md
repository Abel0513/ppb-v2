# Expert30460 six betting field acceptance — 2026-11-14 Asia/Taipei

Actual update passed: run43519237581, command329ce1bd-bb71-487b-b6ab-3bf4aee6215c,
commit547c5810ff46955be555b4763dba44154b47c47d, request ID
expert30460-six-bets-123-20261114-v1. SSM default3 and SHA256
a9fc80aa20b413233fd15ad5b7df8617d56984ed44d3c05c079ec71a09d353ee verified
before S3 upload/SendCommand. No document reinstall or IAM operation.

POST to the existing Expert's partial ACF endpoint returned HTTP200. An independent
GET returned200 and exact43-field comparison passed: six requested betting fields
all Codex測試123,37 other values and JSON types unchanged. Six actual source
analysts and images verified, reference30436 unchanged, original journal and
fingerprint unchanged. One WP write request, no create/core/publish request.
This uses the existing root-private baseline and durable request ledger.

Follow-up uses the same request ID with the fixed verify operation: GET only,
compares with the saved original43-field baseline and cannot dispatch POST.
The first update is complete; do not create a new request ID or replay old resume.

Core author remains under the previously accepted authenticated WP default-author
contract: the Expert CPT does not independently expose author in REST. All core
fields actually exposed by REST and source analyst identities are compared;
no independent REST readback of the unexposed author property is claimed.

## GET-only replay evidence

| Operation | Actions run | Attempt | SSM command | HTTP | POST count |
| --- | --- | --- | --- | --- | --- |
| update_acf + independent GET | 43519237581 | 1 | 329ce1bd-bb71-487b-b6ab-3bf4aee6215c | POST200 / GET200 | 1 |
| verify | 43519593734 | 1 | b500f68f-6a33-4b45-b5f9-79ca0eb1c17d | GET200 | 0 |
| verify repeat | 43519593734 | 2 | 65717cd8-e55e-4d3f-9c8b-91002ccced39 | GET200 | 0 |

All requests use the same fixed request ID. Every verification independently
compares current43 ACF fields against the saved original baseline: six exact
requested strings,37 untouched values AND exact JSON types, six analysts/media,
reference30436, original creation journal and fingerprint. Durable INTENT and
baseline remain in the private update ledger; original journal is never changed.
Raw baseline/business data and credentials are not exported. Machine-readable
actual sanitized receipts: live-evidence.json. RESULT=PASS.

The old v2 journal workflow correctly rejected the new default content before any
SSM call. Its redundant push trigger is retired; historical source/receipts and
offline/manual safeguards remain. Only the parameterized client runs automatic
remote operations. No root executor/package/document change is required.
