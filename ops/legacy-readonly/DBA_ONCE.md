# One-time existing DBA operation (not yet executed)

Only if the independent account is not provisioned: an existing authorized DBA
and OS-management administrator executes the installed reviewed utility on the
DB/EC2 host through their EXISTING management channel:

    python3 /usr/local/lib/ppb-legacy-ro/dba-once.py --login-path EXISTING_AUTHORIZED_DBA_PROFILE

The profile is an existing explicitly authorized mysql_config_editor management
connection, supplied LOCALLY by the DBA, not a password supplied to Codex. Do not
invent this profile, search old app credentials, create a new shared admin login,
or use the V2 app account. If that existing management mechanism is unavailable,
report that actual boundary; this utility does not manufacture DBA authority.
A remote DBA whose management client runs elsewhere needs their existing secure
host-management mechanism to retain the NEW readonly secret locally, not email,
chat, Actions, SSM command parameters or GitHub.

Required DB authority: inspect information_schema and existence of the NEW account,
CREATE USER and grant SELECT(column), REFERENCES/SHOW VIEW on the uniquely verified
old schema. OS authority only creates the NEW private reader config. No old table
DDL/DML, new DB/test tables, old credential read/export, plugin install, daemon,
LINE, WP, Cron or old application change. No AWS IAM change.

Validation after provisioning is automatic via existing fixed db_permissions,
schema and bounded select operations: verify actual actor, all grants, transaction
readonly, output policy, row/time limit, and zero old write statements. A successful
provisioning receipt alone is NOT query/parity acceptance.

## Existing administrator: single CloudShell operation

`admin-cloudshell-once.sh` is the single reviewed administrative operation. Use an
existing AWS administrator identity (the ordinary V2/WP roles are rejected). It
pins the installed DBA tool SHA, runs only that fixed program on this EC2 via
AWS-RunShellScript, waits, and returns a filtered receipt. It does not change IAM,
create a new high-privilege management connection or ask for/export a password.

One nonsecret connection handle is supplied locally by the administrator:
`login:EXISTING_AUTHORIZED_PROFILE` or `file:/root/EXISTING_PRIVATE_MYSQL_DEFAULTS`.
The defaults file alternative must already exist, root-owned0600, nonsymlink and
under/root or/etc/mysql; old application config paths are rejected. Do not invent
an available profile/file. If neither existing authorized mechanism is available,
this is still a real DBA management boundary, not permission for Codex to search
old passwords. The helper stops, never falls back to an implicit root login.

The DBA connection is forced to the local MySQL Unix socket. Only a new locked
principal, scoped metadata/column-read grants and NEW private reader files are
created; the new principal is unlocked last. No old business DDL/DML. A collision
or SQL error stops without overwriting/forcing. Subsequent runtime db_permissions,
schema/select evidence is still required before claiming database readiness.

## Fixed management connection probe (read only)

The reviewed installation receipt includes `management_probe`. It runs as root
only within the existing pinned fixed-tool installation, never the daily reader.
It calls mysql_config_editor print --all but returns only validated section names;
password/option text stays in memory and is not logged or saved. It stat's only
five conventional root client configuration paths, without reading their content.
Existing files are candidates, NOT verified DBA credentials. No recursive search,
old application configuration access or credential export.

For exactly one known local socket it tries credentialless root once, then up to
four existing login paths. All connections force Unix socket, fixed SELECT/SHOW
metadata only, no init command/tee/pager/local infile. Successful proof requires
MySQL5.7 and global ALL WITH GRANT OPTION; lesser grants are conservatively
reported unproven. Authentication plugin metadata is returned only when accessible.
No account creation, grants or business query occurs during this probe. Missing
editor/socket, failed authentication and insufficient proof remain distinct from
verified absence/availability. Standard files are not automatically loaded because
arbitrary client init options cannot be assumed safe for a read-only probe.

## Actual connection discovery and no-profile alternative (2026-11-14)

Run43577219294, SSM3a91c40e-0c9b-4699-8110-a6ac150d1d0f:
root mysql_config_editor returned successfully but neither stream contained any
named profile. /root/.mylogin.cnf exists root0600; presence is not authentication.
The five standard management config candidates contained no private root-owned
usable management file. /tmp/mysql.sock exists; explicit passwordless root on it
returns MYSQL_ERROR_1045. Authentication plugin and DBA grants are therefore NOT
verified. No recursive search, credential export, old business query or SQL write.

Do not invent/request a nonexistent login-path. An existing MySQL DBA can use their
already-authorized INTERACTIVE OS/DB management session to run the installed fixed
helper below. This does NOT require creating a persistent DBA login profile:

    python3 /www/ppb-v2-deploy/scripts/legacy-readonly/dba-authenticated-once.py

The DBA supplies their existing username/password directly to this LOCAL tool,
never ChatGPT, GitHub, SSM command parameters or Actions. A root0600 temporary
client file is used only for the fixed dba-once.py, with SQL history disabled by
batch mode; the file is removed on normal/error completion. Only the NEW scoped
readonly credentials remain in /etc/ppb-legacy-ro. No DBA password is returned.
It refuses noninteractive execution and verifies installed helper bytes, never
creates a new DBA authority, resets a password or searches old app credentials.
The defaults-file path now uses the MySQL5.7-compatible fixed login-file override
rather than unsupported --no-login-paths. The CloudShell helper's tool pin changes
for this compatibility fix; do not bypass the earlier pin on an old copied script.

Required person/authority: the existing MySQL DBA plus access to the already
controlled host-management session (they may be the same administrator). AWS IAM
alone does not supply MySQL authentication. If nobody has such an existing DBA
session/credential, stop; authorized MySQL credential recovery is a separate
administrative decision, not something Codex may infer or brute force.
