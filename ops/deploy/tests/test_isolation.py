import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]

def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
b=load('boundary_test',ROOT/'isolation/boundary.py')
l=load('lifecycle_test',ROOT/'isolation/lifecycle.py')
r=load('release_client_test',ROOT/'release-client.py')

class Boundary(unittest.TestCase):
    def policy(self):return dict(version=1,live=b.LIVE,private=b.PRIVATE,unix_user='ppb-v2',database='v2prod',read_only_schemas=['legacy'],env_sha256='a'*64,legacy_roots=[b.LEGACY],vhost_sha256='a'*64,php_config_sha256='b'*64,php_unit_sha256='c'*64)
    def test_no_unverified_or_shared_database_policy(self):
        p=self.policy();self.assertEqual(b.validate_policy(p)['database'],'v2prod')
        for changes in [dict(database=None),dict(database='legacy'),dict(unix_user='root'),dict(live=b.LEGACY),dict(env_sha256=None)]:
            with self.assertRaises(RuntimeError):b.validate_policy(dict(p,**changes))
    def test_scoped_database_and_readonly_legacy(self):
        result=b.validate_grants('v2prod',{'legacy'},['GRANT USAGE ON *.* TO `worker`@`localhost`','GRANT ALL PRIVILEGES ON `v2prod`.* TO `worker`@`localhost`','GRANT SELECT ON `legacy`.* TO `worker`@`localhost`'])
        self.assertFalse(result['legacy_database_write'])
    def test_global_admin_and_legacy_writes_roles_and_proxies_denied(self):
        own='GRANT ALL PRIVILEGES ON `v2prod`.* TO `worker`@`localhost`'
        bad=['GRANT ALL PRIVILEGES ON *.* TO `worker`@`localhost`','GRANT FILE ON *.* TO `worker`@`localhost`','GRANT UPDATE ON `legacy`.* TO `worker`@`localhost`','GRANT EXECUTE ON `legacy`.* TO `worker`@`localhost`',own+' WITH GRANT OPTION','GRANT `adminrole`@`%` TO `worker`@`localhost`','GRANT PROXY ON root TO worker']
        for grant in bad:
            with self.assertRaises(RuntimeError):b.validate_grants('v2prod',{'legacy'},[own,grant])
    def test_hidden_trigger_or_definer_metadata_cannot_be_treated_as_safe(self):
        with self.assertRaisesRegex(RuntimeError,'METADATA_PRIVILEGES'):b.validate_grants('v2prod',set(),['GRANT INSERT ON `v2prod`.* TO x'])
    def test_schema_patterns_fail_closed(self):
        with self.assertRaisesRegex(RuntimeError,'WILDCARD'):b.validate_grants('v2_prod',set(),['GRANT ALL PRIVILEGES ON `v2_prod`.* TO x'])
        self.assertTrue(b.validate_grants('v2_prod',set(),['GRANT ALL PRIVILEGES ON `v2\\_prod`.* TO x'])['v2_database_write'])
    def test_actual_connection_and_transactional_engines(self):
        p=self.policy();env=dict(DB_DATABASE='v2prod',DB_USERNAME='worker')
        probe=dict(database='v2prod',user='worker',roles='NONE',current_user='worker@localhost',definers=[],cross_schema_foreign_keys=0,grants=['GRANT ALL PRIVILEGES ON `v2prod`.* TO x'],table_engines=['InnoDB'])
        self.assertEqual(b.validate_probe(p,env,probe)['tables'],1)
        for change in [dict(database='legacy'),dict(user='root'),dict(roles='admin'),dict(table_engines=['MyISAM']),dict(definers=['root@localhost']),dict(cross_schema_foreign_keys=1)]:
            with self.assertRaises(RuntimeError):b.validate_probe(p,env,dict(probe,**change))
    def test_data_only_env_parser_and_no_password_output(self):
        import hashlib
        text='DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_DATABASE=v2prod\nDB_USERNAME=worker\nDB_PASSWORD="fixture-password"\n'
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/'.env';p.write_text(text)
            data=b.read_env(p,hashlib.sha256(p.read_bytes()).hexdigest(),owner=os.geteuid());self.assertEqual(data['DB_USERNAME'],'worker')
            with self.assertRaisesRegex(RuntimeError,'ENV_CHANGED'):b.read_env(p,'0'*64,owner=os.geteuid())
            p.write_text(text.replace('DB_USERNAME=worker','DB_USERNAME=root'))
            with self.assertRaisesRegex(RuntimeError,'HIGH_PRIVILEGE'):b.read_env(p,hashlib.sha256(p.read_bytes()).hexdigest(),owner=os.geteuid())
    def test_web_service_identity_and_write_scope(self):
        good=dict(ActiveState='active',User='ppb-v2',ProtectSystem='strict',NoNewPrivileges='yes',CapabilityBoundingSet='',ReadWritePaths=b.LIVE,InaccessiblePaths=b.LEGACY+' /www/ppb-v2-deploy /www/server/panel',PrivateTmp='yes',PrivateDevices='yes',IPAddressDeny='169.254.0.0/16 fd00:ec2::254/128',BindReadOnlyPaths=b.LIVE+'/.env')
        self.assertTrue(b.validate_service(good))
        for change in [dict(User='www'),dict(User='root'),dict(ProtectSystem='no'),dict(ReadWritePaths='/www/wwwroot'),dict(InaccessiblePaths='')]:
            with self.assertRaises(RuntimeError):b.validate_service(dict(good,**change))
    def test_nginx_must_really_route_to_the_isolated_pool(self):
        vhost='server_name v2.csdatw.xyz;\nroot /www/wwwroot/ppbaos.com/public;\nfastcgi_pass unix:/www/wwwroot/ppbaos.com/storage/run/php-fpm.sock;'
        fpm='clear_env = yes\nlisten = /www/wwwroot/ppbaos.com/storage/run/php-fpm.sock'
        self.assertTrue(b.validate_web_config(vhost,fpm))
        for bad in [vhost.replace('/www/wwwroot/ppbaos.com/storage/run/php-fpm.sock','/tmp/php-cgi-84.sock'),vhost+'\ninclude enable-php-84.conf;',vhost.replace('/ppbaos.com/public','/3.231.229.26')]:
            with self.assertRaises(RuntimeError):b.validate_web_config(bad,fpm)
        with self.assertRaises(RuntimeError):b.validate_web_config(vhost,fpm+'\nenv[DB_USERNAME] = root')
    def test_no_general_shell_and_no_old_root_write_mount(self):
        cmd=b.sandbox_command(['/www/server/php/84/bin/php',b.LIVE+'/artisan','migrate','--force'])
        self.assertIn('--uid=ppb-v2',cmd);self.assertIn('--property=ProtectSystem=strict',cmd)
        self.assertEqual([x for x in cmd if x.startswith('--property=ReadWritePaths=')],['--property=ReadWritePaths='+b.LIVE])
        self.assertIn('--property=IPAddressDeny=169.254.0.0/16 fd00:ec2::254/128',cmd)
        self.assertFalse(any(x in {'sh','bash','-c'} for x in cmd))
    def test_lifecycle_has_no_raw_sql_shell_fresh_or_seed(self):
        self.assertNotIn('migrate:fresh',sum(l.COMMANDS.values(),[]))
        self.assertNotIn('db:seed',sum(l.COMMANDS.values(),[]))
        for op in ['shell','sql','artisan','delete_journal']:
            with self.assertRaisesRegex(RuntimeError,'OPERATION_NOT_ALLOWED'):l.run(op,'a'*40,'request-0001')

class ReleaseClient(unittest.TestCase):
    def setUp(self):
        self.t=tempfile.TemporaryDirectory();self.addCleanup(self.t.cleanup);self.old=os.getcwd();os.chdir(self.t.name);self.addCleanup(os.chdir,self.old)
        self.archive=Path('release.tar.gz');self.archive.write_bytes(b'fixture archive')
        self.calls=[];self.mode='pass'
    def aws(self,*args):
        self.calls.append(args)
        if args[:2]==('sts','get-caller-identity'):return {'Account':r.ACCOUNT}
        if args[:2]==('ssm','describe-document'):return {'Document':{'DocumentVersion':'10','Hash':'a'*64,'HashType':'Sha256'}}
        if args[:2]==('ssm','list-document-versions'):return {'DocumentVersions':[{'DocumentVersion':'10'}]+([{'DocumentVersion':'1'}] if self.mode=='unsafe_version' else [])}
        if args[:2]==('ssm','get-document'):
            return {'Content':'{}' if self.mode=='old' or (self.mode=='unsafe_version' and args[args.index('--document-version')+1]=='1') else (ROOT/'PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text()}
        if args[:2]==('s3api','put-object'):return {}
        if args[:2]==('ssm','send-command'):return {'Command':{'CommandId':'fixture-command'}}
        data=dict(result='PPB_V2_ISOLATED_RELEASE_PASS',commit='a'*40,isolation=dict(legacy_database_write=False,v2_database_write=True),acceptance=dict(COMMIT='a'*40,RESULT='PPB_V2_FUNCTIONAL_BASELINE_PASS',CHECKS=4))
        if self.mode=='wrong_commit':data['commit']='b'*40
        if self.mode=='unsafe_db':data['isolation']['legacy_database_write']=True
        if self.mode=='no_acceptance':data['acceptance']={}
        return dict(CommandId='fixture-command',InstanceId=r.INSTANCE,Status='Failed' if self.mode=='failed' else 'Success',ResponseCode=1 if self.mode=='failed' else 0,StandardOutputContent='' if self.mode=='truncated' else 'PPB_V2_RELEASE_V1='+json.dumps(data))
    def execute(self):return r.execute('deploy','a'*40,'1','1',self.archive,self.aws,lambda _:None)
    def test_uninstalled_document_stops_before_upload_send(self):
        self.mode='old';result=self.execute()
        self.assertEqual(result['reason'],'ISOLATED_V2_EXECUTOR_NOT_INSTALLED');self.assertIsNone(result['ssm_command_id'])
        self.assertEqual(len(self.calls),3)
    def test_old_unsafe_document_version_cannot_remain_callable(self):
        self.mode='unsafe_version';result=self.execute()
        self.assertEqual(result['reason'],'UNSAFE_OR_UNREVIEWED_OLD_SSM_VERSION_STILL_CALLABLE')
        self.assertFalse(any(x[:2] in [('s3api','put-object'),('ssm','send-command')] for x in self.calls))
    def test_actual_command_result_and_private_metadata(self):
        from contextlib import redirect_stdout
        import io
        with redirect_stdout(io.StringIO()):result=self.execute()
        self.assertEqual(result['result'],'PASS')
        self.assertEqual(result['ssm_command_id'],'fixture-command')
        send=[x for x in self.calls if x[:2]==('ssm','send-command')]
        self.assertEqual(len(send),1);self.assertIn('--document-hash',send[0])
        self.assertEqual(json.loads(Path('v2-result/result.json').read_text())['result'],'PASS')
    def test_no_false_pass_on_failed_foreign_unsafe_or_missing_result(self):
        from contextlib import redirect_stdout
        import io
        for mode in ['wrong_commit','unsafe_db','no_acceptance','failed','truncated']:
            self.mode=mode;self.calls=[]
            with redirect_stdout(io.StringIO()):result=self.execute()
            self.assertEqual(result['result'],'STOP',mode)
            self.assertEqual(sum(x[:2]==('ssm','send-command') for x in self.calls),1)

class IsolatedDocument(unittest.TestCase):
    def test_no_root_provider_autoload_eval_and_fixed_hashes(self):
        d=json.loads((ROOT/'PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text())
        script='\n'.join('\n'.join(x['inputs']['runCommand']) for x in d['mainSteps'] if 'runCommand' in x['inputs'])
        self.assertNotIn('require $autoload',script);self.assertNotIn('php -r',script)
        self.assertIn('/scripts/isolation/release.py',script)
        self.assertEqual(subprocess.run(['sh','-n'],input=script,text=True).returncode,0)
    def test_bad_release_plan_rejects_commands(self):
        m=load('isolated_release_plan',ROOT/'isolation/release.py')
        self.assertEqual(m.validate_plan(dict(migrate=True,cache=['config'],queue_restart=True))['migrate'],True)
        for data in [dict(migrate='yes',cache=[],queue_restart=False),dict(migrate=True,cache=['shell'],queue_restart=False),dict(migrate=True,cache=[],queue_restart=False,sql='DROP')]:
            with self.assertRaises(RuntimeError):m.validate_plan(data)

class BootstrapArtifact(unittest.TestCase):
    def test_reproducible_allowlisted_tool_archive_contains_no_credentials(self):
        import hashlib,tarfile
        m=load('bootstrap_build_test',ROOT/'isolation/build-bootstrap.py')
        from contextlib import redirect_stdout
        import io
        with tempfile.TemporaryDirectory() as tmp,redirect_stdout(io.StringIO()):
            first=Path(tmp)/'first.tar.gz';second=Path(tmp)/'second.tar.gz';m.build(first);m.build(second)
            self.assertEqual(first.read_bytes(),second.read_bytes())
            with tarfile.open(first,'r:gz') as archive:
                names=archive.getnames();self.assertEqual(set(names),set(m.FILES)|{'SHA256.json'})
                manifest=json.load(archive.extractfile('SHA256.json'))
                for name,digest in manifest.items():self.assertEqual(hashlib.sha256(archive.extractfile(name).read()).hexdigest(),digest)
                self.assertFalse(any(name.endswith(('.env','.sql','.pem','.key')) and name!='units/metadata-delegation.sql' for name in names))
                self.assertEqual(archive.extractfile('units/metadata-delegation.sql').read(),(ROOT.parent/'db-audit/DBA_ONCE.mysql57.sql').read_bytes())

class ActivationAuthority(unittest.TestCase):
    def setUp(self):
        self.t=tempfile.TemporaryDirectory();self.addCleanup(self.t.cleanup)
        self.old=os.getcwd();os.chdir(self.t.name);self.addCleanup(os.chdir,self.old)
        self.m=load('activate_scope_test',ROOT/'activate-isolation.py');self.calls=[]

    def active_document_client(self, bootstrap_exists, hash_type='Sha256', digest='a'*64):
        documents={
            '7': '{"schemaVersion":"2.2","old":true}',
            '9': (ROOT/'PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text(),
        }
        if bootstrap_exists:
            documents['8']=(ROOT/'PPB-V2-Controlled-Deploy.bootstrap.ssm.json').read_text()

        def aws(*args):
            self.calls.append(args)
            op=args[:2]
            if op==('sts','get-caller-identity'):return {'Account':r.ACCOUNT,'Arn':'fixture'}
            if op==('ssm','get-document'):
                version=args[args.index('--document-version')+1]
                if version=='$DEFAULT':version='9'
                return {'Content':json.dumps(json.loads(documents[version])), 'DocumentVersion':version}
            if op==('ssm','list-document-versions'):
                if '--next-token' not in args:
                    return {'DocumentVersions':[{'DocumentVersion':'9'},{'DocumentVersion':'7'}],'NextToken':'next'}
                return {'DocumentVersions':[{'DocumentVersion':'8'}] if bootstrap_exists else []}
            if op==('ssm','describe-document'):
                return {'Document':{'Hash':digest,'HashType':hash_type}}
            self.fail(f'Unexpected call during verification: {op}')
        return aws

    def assert_verify_only_calls(self):
        allowed={
            ('sts','get-caller-identity'),
            ('ssm','get-document'),
            ('ssm','list-document-versions'),
            ('ssm','describe-document'),
        }
        self.assertLessEqual({args[:2] for args in self.calls},allowed)

    def test_already_active_verifies_reviewed_versions_without_writes(self):
        result=self.m.activate(self.active_document_client(True),lambda _:None)
        self.assertEqual(result['result'],'ISOLATION_ALREADY_ACTIVE_VERIFIED')
        self.assertEqual(result['runtime_version'],'9')
        self.assertEqual(result['bootstrap_document_version'],'8')
        self.assertEqual(result['runtime_document_hash'],'a'*64)
        self.assertIsNone(result['ssm_command_id'])
        self.assert_verify_only_calls()
        from contextlib import redirect_stdout
        import io
        with patch.object(self.m,'activate',return_value=result),redirect_stdout(io.StringIO()):
            self.assertEqual(self.m.main(),0)

    def test_already_active_without_reviewed_bootstrap_stops_without_writes(self):
        result=self.m.activate(self.active_document_client(False),lambda _:None)
        self.assertEqual(result['result'],'STOP')
        self.assertEqual(result['reason'],'REVIEWED_RUNTIME_ACTIVE_BOOTSTRAP_MISSING')
        self.assert_verify_only_calls()

    def test_already_active_rejects_invalid_hash_metadata_without_writes(self):
        for hash_type,digest,reason in [
            ('Sha1','a'*64,'DOCUMENT_HASH_TYPE_INVALID'),
            ('Sha256','z'*64,'DOCUMENT_HASH_INVALID'),
        ]:
            with self.subTest(reason=reason):
                result=self.m.activate(self.active_document_client(True,hash_type,digest),lambda _:None)
                self.assertEqual(result['result'],'STOP')
                self.assertEqual(result['reason'],reason)
                self.assert_verify_only_calls()

    def test_default_read_denial_stops_before_writes(self):
        def aws(*args):
            self.calls.append(args)
            if args[:2]==('sts','get-caller-identity'):return {'Account':r.ACCOUNT,'Arn':'fixture'}
            raise RuntimeError('AWS_AccessDeniedException')
        result=self.m.activate(aws,lambda _:None)
        self.assertEqual(result['result'],'STOP')
        self.assertEqual(result['reason'],'AWS_AccessDeniedException')
        self.assertEqual(result['failed_action'],'ssm:GetDocument')
        self.assert_verify_only_calls()

    def test_actual_update_denial_is_precise_and_no_root_command_is_sent(self):
        def aws(*args):
            self.calls.append(args)
            if args[:2]==('sts','get-caller-identity'):return {'Account':r.ACCOUNT,'Arn':'arn:aws:sts::429724121147:assumed-role/PPB-V2-GitHub-Deploy-Role/test'}
            if args[:2]==('ssm','get-document') and '$DEFAULT' in args:
                return {'Content':'{"schemaVersion":"2.2","old":true}','DocumentVersion':'1'}
            raise RuntimeError('AWS_AccessDeniedException')
        result=self.m.activate(aws,lambda _:None)
        self.assertEqual(result['result'],'STOP');self.assertEqual(result['reason'],'AWS_AccessDeniedException')
        self.assertEqual(result['failed_action'],'ssm:UpdateDocument')
        self.assertEqual(result['resource'],'arn:aws:ssm:us-east-1:429724121147:document/PPB-V2-Controlled-Deploy')
        self.assertIsNone(result['ssm_command_id'])
        self.assertEqual(sum(x[:2]==('ssm','update-document') for x in self.calls),1)
        self.assertFalse(any(x[:2] in [('ssm','send-command'),('ssm','update-document-default-version')] for x in self.calls))
        self.assertFalse(any(x[0]=='iam' for x in self.calls))
    def test_install_success_does_not_activate_unverified_database_or_services(self):
        bootstrap=(ROOT/'PPB-V2-Controlled-Deploy.bootstrap.ssm.json').read_text()
        def aws(*args):
            self.calls.append(args)
            if args[:2]==('sts','get-caller-identity'):return {'Account':r.ACCOUNT,'Arn':'fixture'}
            if args[:2]==('ssm','get-document'):return {'Content':bootstrap,'DocumentVersion':'9'}
            if args[:2]==('ssm','describe-document'):return {'Document':{'Hash':'a'*64,'HashType':'Sha256'}}
            if args[:2]==('ssm','list-document-versions'):return {'DocumentVersions':[{'DocumentVersion':'9'}]}
            if args[:2]==('ssm','send-command'):return {'Command':{'CommandId':'fixture-install-command'}}
            if args[:2]==('ssm','get-command-invocation'):return dict(Status='Success',ResponseCode=0,StandardOutputContent='V2_FIXED_TOOLS_INSTALLED=YES\nV2_INVENTORY={"result":"INVENTORY_ONLY_ISOLATION_UNVERIFIED"}')
            self.fail('Unexpected authority expansion')
        from contextlib import redirect_stdout
        import io
        with patch.dict(os.environ,GITHUB_RUN_ID='1'),redirect_stdout(io.StringIO()):result=self.m.activate(aws,lambda _:None)
        self.assertEqual(result['result'],'TOOLS_INSTALLED_ISOLATION_PENDING')
        self.assertEqual(sum(x[:2]==('ssm','send-command') for x in self.calls),1)
        self.assertFalse(any(x[:2] in [('ssm','update-document-default-version'),('ssm','delete-document')] for x in self.calls))

    def test_quarantine_stops_if_reviewed_runtime_becomes_default(self):
        from contextlib import redirect_stdout
        import io

        bootstrap=(ROOT/'PPB-V2-Controlled-Deploy.bootstrap.ssm.json').read_text()
        runtime=(ROOT/'PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text()
        default='1'

        def aws(*args):
            nonlocal default
            self.calls.append(args)
            op=args[:2]
            if op==('sts','get-caller-identity'):return {'Account':r.ACCOUNT,'Arn':'fixture'}
            if op==('ssm','get-document'):
                version=args[args.index('--document-version')+1]
                if version=='$DEFAULT':version=default
                if version=='$LATEST':version='8'
                return {'Content':{'1':'{}','8':bootstrap,'9':runtime}[version],'DocumentVersion':version}
            if op==('ssm','describe-document'):return {'Document':{'Hash':'a'*64,'HashType':'Sha256'}}
            if op==('ssm','get-command-invocation'):
                default='9'
                return dict(Status='Success',ResponseCode=0,StandardOutputContent='V2_FIXED_TOOLS_INSTALLED=YES\nV2_INVENTORY={"result":"INVENTORY_ONLY_ISOLATION_UNVERIFIED"}')
            self.fail(f'Unexpected call during quarantine: {op}')

        with patch.dict(os.environ,PPB_BOOTSTRAP_RECOVERY_COMMAND_ID='ff2db294-03d8-4f4a-9bb3-09ccbd93c5fe'),redirect_stdout(io.StringIO()):
            result=self.m.activate(aws,lambda _:None)
        self.assertEqual(result['result'],'STOP')
        self.assertEqual(result['reason'],'QUARANTINE_REVIEWED_RUNTIME_ACTIVE')
        self.assertEqual(default,'9')
        self.assertNotIn(('ssm','update-document-default-version'),[args[:2] for args in self.calls])
        self.assertNotIn(('ssm','delete-document'),[args[:2] for args in self.calls])

    def test_recovery_reads_prior_receipt_without_resending(self):
        bootstrap=(ROOT/'PPB-V2-Controlled-Deploy.bootstrap.ssm.json').read_text()
        def aws(*args):
            self.calls.append(args)
            if args[:2]==('sts','get-caller-identity'):return {'Account':r.ACCOUNT,'Arn':'fixture'}
            if args[:2]==('ssm','get-document'):return {'Content':bootstrap,'DocumentVersion':'5'}
            if args[:2]==('ssm','describe-document'):return {'Document':{'Hash':'a'*64,'HashType':'Sha256'}}
            if args[:2]==('ssm','list-document-versions'):return {'DocumentVersions':[{'DocumentVersion':'9'}]}
            if args[:2]==('ssm','get-command-invocation'):return dict(Status='Success',ResponseCode=0,StandardOutputContent='V2_FIXED_TOOLS_INSTALLED=YES\nV2_INVENTORY={"result":"INVENTORY_ONLY_ISOLATION_UNVERIFIED"}')
            self.fail('Recovery must not send a new command')
        from contextlib import redirect_stdout
        import io
        with patch.dict(os.environ,GITHUB_RUN_ID='1',PPB_BOOTSTRAP_RECOVERY_COMMAND_ID='ff2db294-03d8-4f4a-9bb3-09ccbd93c5fe'),redirect_stdout(io.StringIO()):result=self.m.activate(aws,lambda _:None)
        self.assertEqual(result['result'],'TOOLS_INSTALLED_ISOLATION_PENDING')
        self.assertFalse(any(x[:2]==('ssm','send-command') for x in self.calls))

    def test_old_versions_archived_before_quarantine_and_deletion(self):
        bootstrap=(ROOT/'PPB-V2-Controlled-Deploy.bootstrap.ssm.json').read_text()
        state={'default':'1','old':True}
        def aws(*args):
            self.calls.append(args)
            op=args[:2]
            if op==('sts','get-caller-identity'):return {'Account':r.ACCOUNT,'Arn':'fixture'}
            if op==('ssm','get-document'):
                version=args[args.index('--document-version')+1]
                if version=='$DEFAULT':version=state['default']
                return {'Content':'{"schemaVersion":"2.2","unsafe":true}' if version=='1' else bootstrap,'DocumentVersion':version}
            if op==('ssm','describe-document'):return {'Document':{'Hash':'a'*64,'HashType':'Sha256'}}
            if op==('ssm','list-document-versions'):return {'DocumentVersions':([{'DocumentVersion':'1'}] if state['old'] else [])+[{'DocumentVersion':'9'}]}
            if op==('ssm','get-command-invocation'):return dict(Status='Success',ResponseCode=0,StandardOutputContent='V2_FIXED_TOOLS_INSTALLED=YES\nV2_INVENTORY={"result":"INVENTORY_ONLY_ISOLATION_UNVERIFIED"}')
            if op==('s3api','put-object'):return {}
            if op==('ssm','update-document-default-version'):state['default']='9';return {}
            if op==('ssm','delete-document'):self.assertEqual(args[-1],'1');state['old']=False;return {}
            self.fail(str(op))
        from contextlib import redirect_stdout
        import io
        with patch.dict(os.environ,GITHUB_RUN_ID='1',PPB_BOOTSTRAP_RECOVERY_COMMAND_ID='f246d516-1598-4f0a-9abf-dfc53edca689'),redirect_stdout(io.StringIO()):result=self.m.activate(aws,lambda _:None)
        self.assertEqual(result['retired_versions'],['1'])
        self.assertFalse(result['unsafe_prior_versions_callable'])
        ops=[x[:2] for x in self.calls]
        self.assertLess(ops.index(('s3api','put-object')),ops.index(('ssm','update-document-default-version')))
        self.assertLess(ops.index(('ssm','update-document-default-version')),ops.index(('ssm','delete-document')))
        self.assertNotIn(('ssm','send-command'),ops)

class OsPreparation(unittest.TestCase):
    def setUp(self):
        from types import SimpleNamespace
        self.m=load('prepare_os_test',ROOT/'isolation/prepare-os.py')
        self.account=SimpleNamespace(pw_uid=998,pw_gid=998,pw_shell='/usr/sbin/nologin')
    def test_only_dedicated_nonlogin_account_created_and_no_services_started(self):
        from types import SimpleNamespace
        from contextlib import redirect_stdout
        import io
        with patch.object(self.m.os,'geteuid',return_value=0),patch.object(self.m.pwd,'getpwnam',side_effect=[KeyError(),self.account]),patch.object(self.m.grp,'getgrgid',return_value=SimpleNamespace(gr_name='ppb-v2')),patch.object(self.m.os,'getgrouplist',return_value=[998]),patch.object(self.m.subprocess,'run') as call,redirect_stdout(io.StringIO()):
            self.m.prepare()
            self.assertEqual(call.call_count,1)
            self.assertEqual(call.call_args.args[0],['/usr/sbin/useradd','--system','--no-create-home','--home-dir','/nonexistent','--shell','/usr/sbin/nologin','--user-group','ppb-v2'])
    def test_shared_legacy_group_is_rejected(self):
        from types import SimpleNamespace
        with patch.object(self.m.os,'geteuid',return_value=0),patch.object(self.m.pwd,'getpwnam',return_value=self.account),patch.object(self.m.grp,'getgrgid',return_value=SimpleNamespace(gr_name='www')),patch.object(self.m.subprocess,'run') as call:
            with self.assertRaisesRegex(RuntimeError,'GROUP_SCOPE'):self.m.prepare()
            call.assert_not_called()

class ReadonlyMountFormat(unittest.TestCase):
    def service(self):
        return dict(ActiveState='active',User='ppb-v2',ProtectSystem='strict',NoNewPrivileges='yes',CapabilityBoundingSet='',ReadWritePaths=b.LIVE,InaccessiblePaths=b.LEGACY+' /www/ppb-v2-deploy /www/server/panel',PrivateTmp='yes',PrivateDevices='yes',IPAddressDeny='169.254.0.0/16 fd00:ec2::254/128')
    def test_systemd_expanded_identity_bind_is_readonly(self):
        for value in [b.LIVE+'/.env',b.LIVE+'/.env:'+b.LIVE+'/.env:rbind',b.LIVE+'/.env:'+b.LIVE+'/.env:norbind']:
            self.assertTrue(b.validate_service(dict(self.service(),BindReadOnlyPaths=value)))
    def test_cross_path_and_additional_binds_are_not_accepted(self):
        for value in [b.LIVE+'/.env:/etc/passwd:rbind',b.LIVE+'/.env '+b.LEGACY+'/db.php',b.LIVE+'/.env:'+b.LIVE+'/.env:rw']:
            with self.assertRaisesRegex(RuntimeError,'ENV_NOT_READONLY'):b.validate_service(dict(self.service(),BindReadOnlyPaths=value))

class FpmCutover(unittest.TestCase):
    def setUp(self):self.m=load('fpm_provision_test',ROOT/'isolation/provision.py')
    def test_shared_php_include_replaced_with_only_dedicated_socket(self):
        source='server {\nserver_name v2.csdatw.xyz;\nroot /www/wwwroot/ppbaos.com/public;\ninclude enable-php-84.conf;\n}'
        rendered=self.m.flatten(source)
        self.assertNotIn('enable-php-84',rendered)
        self.assertIn('location / { try_files $uri $uri/ /index.php?$query_string; }',rendered)
        existing=source.replace('include enable-php-84.conf;', 'location / { try_files $uri /index.php?$query_string; }\ninclude enable-php-84.conf;')
        self.assertEqual(self.m.flatten(existing).count('location / {'),1)
        b.validate_web_config(rendered,(ROOT/'isolation/units/php-fpm.conf').read_text())
    def test_unknown_include_cannot_modify_old_vhost(self):
        for value in ['include /etc/nginx/old.conf;','include /www/server/panel/vhost/nginx/legacy.conf;']:
            with self.assertRaisesRegex(RuntimeError,'UNREVIEWED_V2_INCLUDE'):self.m.flatten(value)
    def test_both_actual_v2_hosts_allowed_but_legacy_alias_rejected(self):
        source='server {\nserver_name v2.ppbaos.com 3-231-229-26.sslip.io v2.csdatw.xyz;\nroot /www/wwwroot/ppbaos.com/public;\ninclude enable-php-84.conf;\n}'
        text=self.m.flatten(source);pool=(ROOT/'isolation/units/php-fpm.conf').read_text()
        self.assertTrue(b.validate_web_config(text,pool))
        with self.assertRaisesRegex(RuntimeError,'VHOST_NOT_EXCLUSIVE'):b.validate_web_config(text.replace('v2.ppbaos.com','old.example'),pool)

    def test_runtime_permission_walk_rejects_symlink_and_hardlink_escape(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/'v2';root.mkdir();outside=Path(tmp)/'old';outside.mkdir()
            target=outside/'old.php';target.write_text('legacy');target.chmod(0o644)
            (root/'storage').symlink_to(outside,target_is_directory=True)
            with self.assertRaises(OSError):self.m.runtime_permissions(root/'storage/old.php',os.getuid(),os.getgid(),0o600,root=root)
            (root/'storage').unlink();(root/'storage').mkdir()
            os.link(target,root/'storage/old.php')
            with self.assertRaisesRegex(RuntimeError,'HARDLINK'):self.m.runtime_permissions(root/'storage/old.php',os.getuid(),os.getgid(),0o600,root=root)
            self.assertEqual(target.stat().st_mode&0o777,0o644)

    def test_readonly_code_permissions_exclude_credentials_and_runtime(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/'v2';root.mkdir()
            for rel in ['app','public','bootstrap/cache','resources/views','database/migrations','lang/zh']:(root/rel).mkdir(parents=True,exist_ok=True)
            for rel in ['app/New.php','public/index.php','bootstrap/cache/config.php','.env','composer.json','composer.lock','database/migrations/v2.php','lang/zh/messages.php']:(root/rel).write_text('fixture')
            outside=Path(tmp)/'old.php';outside.write_text('legacy');outside.chmod(0o600)
            (root/'app/old.php').symlink_to(outside)
            snapshot=self.m.code_snapshot(root)
            self.assertNotIn(str(root/'.env'),snapshot)
            self.assertNotIn(str(root/'bootstrap/cache/config.php'),snapshot)
            self.assertNotIn(str(root/'app/old.php'),snapshot)
            self.m.normalize_code(snapshot,os.getgid(),root=root,owner=os.getuid())
            self.assertEqual((root/'app/New.php').stat().st_mode&0o777,0o640)
            self.assertEqual((root/'public/index.php').stat().st_mode&0o777,0o644)
            for rel in ['composer.json','composer.lock','database/migrations/v2.php','lang/zh/messages.php']:
                self.assertEqual((root/rel).stat().st_mode&0o777,0o640)
            self.assertEqual(outside.stat().st_mode&0o777,0o600)

class SocketAcl(unittest.TestCase):
    def setUp(self):self.m=load('fixed_socket_acl_test',ROOT/'isolation/socket-acl.py')
    def test_named_nginx_acl_round_trip_without_world_access(self):
        import socket
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'php-fpm.sock'
            with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as sock:
                sock.bind(str(path));path.chmod(0o600)
                self.m.apply(path,os.getuid(),os.getuid()) # Cloud sandbox maps only its own UID.
                self.assertEqual(path.stat().st_mode&0o007,0)
                self.assertTrue(os.getxattr(path,'system.posix_acl_access'))
    def test_socket_helper_refuses_symlink_and_wrong_owner(self):
        import socket
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);old=root/'old.php';old.write_text('protected');old.chmod(0o644)
            target=root/'socket';target.symlink_to(old)
            with self.assertRaisesRegex(RuntimeError,'IDENTITY'):self.m.apply(target,os.getuid(),65534)
            self.assertEqual(old.stat().st_mode&0o777,0o644)
            target.unlink()
            with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as sock:
                sock.bind(str(target))
                with self.assertRaisesRegex(RuntimeError,'IDENTITY'):self.m.apply(target,os.getuid()+1,65534)

class ExistingReleaseRecovery(unittest.TestCase):
    def test_plugin_delay_recovers_original_command_without_any_write_api(self):
        m=load('recovery_receiver_test',ROOT/'recover-result.py')
        request=json.loads((ROOT/'recovery-request.json').read_text());calls=[];polls=[]
        payload=dict(result='PPB_V2_ISOLATED_RELEASE_PASS',commit=request['commit'],isolation={'legacy_database_write':False,'v2_database_write':True},acceptance={'COMMIT':request['commit'],'RESULT':'PPB_V2_FUNCTIONAL_BASELINE_PASS','CHECKS':5})
        def aws(*args):
            calls.append(args)
            if args[:2]==('sts','get-caller-identity'):return {'Account':r.ACCOUNT}
            if args[:2]==('ssm','describe-document'):return {'Document':{'Hash':request['document_hash'],'HashType':'Sha256'}}
            if args[:2]==('ssm','get-document'):return {'Content':(ROOT/'PPB-V2-Controlled-Deploy.isolated.ssm.json').read_text()}
            if args[:2]==('ssm','get-command-invocation'):
                polls.append(1)
                if len(polls)==1:raise RuntimeError('AWS_InvalidPluginName')
                return dict(CommandId=request['command_id'],InstanceId=r.INSTANCE,DocumentName=r.DOC,DocumentVersion=request['document_version'],Status='Success',ResponseCode=0,StandardOutputContent='PPB_V2_RELEASE_V1='+json.dumps(payload))
            self.fail('Recovery attempted an unapproved mutation '+str(args[:2]))
        with tempfile.TemporaryDirectory() as tmp:
            old=os.getcwd();os.chdir(tmp)
            try:result=m.recover(request,aws,lambda _:None)
            finally:os.chdir(old)
        self.assertEqual(result['result'],'PASS');self.assertEqual(len(polls),2)
        self.assertFalse(result['send_command_requested'])
        self.assertTrue(all(x[:2] in [('sts','get-caller-identity'),('ssm','describe-document'),('ssm','get-document'),('ssm','get-command-invocation')] for x in calls))

class ReviewedExtensionBoundary(unittest.TestCase):
    def test_expanded_directives_and_raw_include_files_are_both_pinned(self):
        base='server { root /www/wwwroot/ppbaos.com/public; }'
        ref='\ninclude /www/server/panel/vhost/nginx/extension/v2.ppbaos.com/*.conf;'
        with patch.object(l.b,'v2_extension_files',return_value={}):
            expected=l.vhost_digest(canonical=True,texts={'v2':base})
            self.assertEqual(expected,l.vhost_digest(canonical=True,texts={'v2':base+ref}))
        with patch.object(l.b,'v2_extension_files',return_value={'site_total.conf':'access_log /v2.log main;'}):
            expanded=l.vhost_digest(canonical=True,texts={'v2':base+'\naccess_log /v2.log main;'})
            self.assertEqual(expanded,l.vhost_digest(canonical=True,texts={'v2':base+ref}))
            self.assertNotEqual(expanded,l.vhost_digest(canonical=True,texts={'v2':base.replace('/ppbaos.com','/old')+ref}))
        with patch.object(l.b,'v2_extension_files',return_value={'site_total.conf':'access_log /other.log main;'}):
            self.assertNotEqual(expanded,l.vhost_digest(canonical=True,texts={'v2':base+ref}))
