# UI readonly review — phase A

Owner authorization: PR58 comment6884632157. Independent fixed-tool change; PR58
application stays Draft. No original source redaction is removed or modified.

ui_inventory is path-only: .tpl under existing template roots and Application
View/Tpl directories, bounded CSS/JS/icon/font path metadata under Public UI
subdirectories. No file contents, secret hash, SQL, old PHP execution or arbitrary
path input. Original source valid()/inventory()/tokenizer retain old behavior.
Symlinks, hardlinks, hidden/config/upload/secret paths, oversize files and traversal
are excluded; permission denial stops the operation. Maximum depth16/path240,
scanned files20000/matches5000/page50/offset5000; batch remains8.

OS UID/mount/capability/network/FD protections and SSM resource/role unchanged.
Only the reviewed worker bytes and pinned bootstrap document change. Existing
isolation hosts skip OS/service provisioning; private FPM config, service unit and
isolation policy bytes must stay identical. Actual generated install is exercised
in an isolated temporary filesystem with all subprocesses mocked: any attempt to
call prepare-os/provision fails the test. No R1/R2, DBA, grants or IAM changes.

Phase B ui_source is intentionally not enabled: first collect actual paths,
then review a per-file allowlist and structure-only parsers. No raw text reader.
Actual installation/path results must be recorded separately from local CI.
