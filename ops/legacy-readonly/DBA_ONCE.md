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
