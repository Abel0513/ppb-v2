<?php
define('PPB_METADATA_POLICY_TEST',true);require __DIR__.'/../probe.php';
$grant="GRANT REFERENCES ON *.* TO 'ppb_v2_metadata'@'localhost'";
function check($condition){if(!$condition)throw new Exception('Metadata privilege policy failed');}
check(referenceOnly([$grant],'ppb_v2_metadata@localhost','NONE'));
foreach([
    ["GRANT REFERENCES ON `ppbaos`.* TO 'ppb_v2_metadata'@'localhost'"],
    [$grant.' WITH GRANT OPTION'],
    [$grant,"REVOKE REFERENCES ON `old`.* FROM 'ppb_v2_metadata'@'localhost'"],
    [$grant,"GRANT SELECT ON *.* TO 'ppb_v2_metadata'@'localhost'"],
    [$grant,"GRANT 'role'@'%' TO 'ppb_v2_metadata'@'localhost'"],
    []
] as $grants)check(!referenceOnly($grants,'ppb_v2_metadata@localhost','NONE'));
check(!referenceOnly([$grant],'root@localhost','NONE'));
check(!referenceOnly([$grant],'ppb_v2_metadata@localhost','role'));
check(supportedServer('5.7.44-log')&&supportedServer('8.0.44')&&supportedServer('8.4.7'));
check(referenceOnly([$grant,"GRANT EXECUTE ON PROCEDURE `ppbaos`.`ppb_v2_metadata_audit_v1` TO 'ppb_v2_metadata'@'localhost'"],'ppb_v2_metadata@localhost','NONE'));
check(!referenceOnly([$grant,"GRANT EXECUTE ON PROCEDURE `old`.`dangerous` TO 'ppb_v2_metadata'@'localhost'"],'ppb_v2_metadata@localhost','NONE'));
foreach(['5.6.51','10.11.1-MariaDB','9.0.1','unverified'] as $v)check(!supportedServer($v));
echo "METADATA_PRIVILEGE_POLICY_TESTS_PASS\n";
