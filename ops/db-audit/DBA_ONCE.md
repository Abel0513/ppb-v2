# One-time MySQL 5.7 metadata delegation

Verified host: existing EC2 i-0135d6b2fac7a54c2; actual server 5.7.44-log.
No new DB, test table, password file, auth plugin, IAM or EC2 login is required.

## Exactly one DBA action

An existing authorized MySQL DBA uses their own existing management connection to
execute `DBA_ONCE.mysql57.sql` once, with stop-on-error semantics (not --force).
The installer needs CREATE USER, global REFERENCES with GRANT OPTION, CREATE
ROUTINE on ppbaos, and MySQL 5.7 SUPER to set another account as DEFINER. The
routine's exact EXECUTE grant also requires the appropriate grant authority.
These are installer privileges, NEVER privileges granted to the V2 application.
The current ppbaos account has none of the required global account/delegation
rights, so AWS document maintenance does not authorize this SQL setup.

The SQL creates only:

* `ppb_v2_metadata@localhost`, ACCOUNT LOCK: cannot log in; no password exchange.
* REFERENCES on *.* for that locked identity. This permits full table/FK metadata
  visibility but no business-row SELECT, INSERT/UPDATE/DELETE or CREATE/ALTER/DROP.
* `ppbaos.ppb_v2_metadata_audit_v1()`, zero parameters, SQL SECURITY DEFINER,
  READS SQL DATA: fixed information_schema queries and SHOW GRANTS FOR CURRENT_USER.
* EXECUTE for that identity on this exact routine. The existing V2 application's
  ALL on ppbaos already includes invocation, so its grants remain unchanged.

Do not replace an existing account/routine if either name collides. No existing
principal, legacy schema, business row, server plugin, FPM or vhost is changed.
The application cannot recreate a routine with this foreign DEFINER: it has no
SUPER/global user-management privileges. Changing SQL SECURITY to INVOKER makes
our actor/grant check fail; altering the body requires DROP/CREATE and cannot
preserve the delegated DEFINER under the application identity.

## Reusable verification

`V2 fixed metadata audit` invokes the fixed, parameterless procedure using the
existing V2 connection. It checks the actual definer, security mode, effective
reference-only grants, MySQL version, exact server-reported routine body SHA256,
global incoming AND outgoing foreign keys including UPDATE_RULE/DELETE_RULE,
all-schema metadata table coverage, and V2 object metadata privileges.
No zero-row result is accepted without coverage proof. All other V2 SQL objects
currently fail closed pending definition/definer review; a changed procedure body,
new privilege, role, grant option or partial revoke fails the audit.
This receipt must be required again for structural migrations; it is not a
permanent certificate of future DB state. Runtime/service activation and PR22
publication remain blocked until this and the OS/web checks genuinely pass.

Visibility basis for the exact MySQL version:
https://github.com/mysql/mysql-server/blob/5.7/sql/sql_show.cc
`make_table_name_list`/schema scanning check TABLE_ACLS; REFERENCES is a table
privilege and global coverage is independently established by the routine's
SHOW GRANTS. `get_referential_constraints_record` reads engine foreign-key
metadata, including update/delete rules; no business-row query is involved.
The MySQL 8 DD equivalent is CAN_ACCESS_TABLE/TABLE_OP_ACLS. MariaDB and other
unverified versions fail closed.
