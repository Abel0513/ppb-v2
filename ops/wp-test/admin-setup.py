#!/usr/bin/env python3
"""One-time AWS management setup. Reuses OIDC/provider/bucket/instance/profile.
Run on an already-authorized AWS management workstation or CloudShell, never EC2.
Default is a read-only plan; --apply changes only the exact WP role/document
and a GetObject-only policy on the role already attached to the fixed instance.
"""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
from remote import ACCOUNT, REGION, INSTANCE, BUCKET, DOCUMENT, ROLE, ROOT, Stop, aws, require

ROLE_NAME = ROLE.rsplit('/', 1)[1]
PROVIDER = f'arn:aws:iam::{ACCOUNT}:oidc-provider/token.actions.githubusercontent.com'
DOC_ARN = f'arn:aws:ssm:{REGION}:{ACCOUNT}:document/{DOCUMENT}'

def policies():
    # Observed signed GitHub OIDC sub (Run 43346941338): immutable owner/repo IDs.
    # A legacy name-only sub does not match this repository's issued identity.
    trust = {'Version': '2012-10-17', 'Statement': [{
        'Effect': 'Allow', 'Principal': {'Federated': PROVIDER}, 'Action': 'sts:AssumeRoleWithWebIdentity',
        'Condition': {'StringEquals': {'token.actions.githubusercontent.com:aud': 'sts.amazonaws.com',
            'token.actions.githubusercontent.com:sub': [
                'repo:Abel0513@55256582/ppb-v2@1454285732:ref:refs/heads/codex/issue-12-wp-draft',
                'repo:Abel0513@55256582/ppb-v2@1454285732:ref:refs/heads/main']}}}]}
    runtime = {'Version': '2012-10-17', 'Statement': [
        {'Sid': 'OnlyFixedWpDocumentAndInstance', 'Effect': 'Allow', 'Action': ['ssm:SendCommand'],
         'Resource': [DOC_ARN, f'arn:aws:ec2:{REGION}:{ACCOUNT}:instance/{INSTANCE}']},
        {'Sid': 'ReadPinnedWpDocument', 'Effect': 'Allow', 'Action': ['ssm:DescribeDocument', 'ssm:GetDocument'],
         'Resource': DOC_ARN},
        # This SSM read API does not support resource-level scoping. No generic shell permission.
        {'Sid': 'CollectCommandStatus', 'Effect': 'Allow', 'Action': ['ssm:ListCommandInvocations'], 'Resource': '*'},
        {'Sid': 'UploadWpPackagesOnly', 'Effect': 'Allow', 'Action': ['s3:PutObject'],
         'Resource': f'arn:aws:s3:::{BUCKET}/wp-test/*',
         'Condition': {'StringEquals': {'s3:x-amz-server-side-encryption': 'AES256'}}}]}
    ec2_read = {'Version': '2012-10-17', 'Statement': [
        {'Effect': 'Allow', 'Action': ['s3:GetObject'], 'Resource': f'arn:aws:s3:::{BUCKET}/wp-test/*'},
        {'Effect': 'Allow', 'Action': ['s3:GetBucketLocation'], 'Resource': f'arn:aws:s3:::{BUCKET}'}]}
    return trust, runtime, ec2_read

def optional(call, *args):
    try:
        return call(*args)
    except Stop as e:
        if str(e) in ['AWS_NoSuchEntity', 'AWS_InvalidDocument', 'AWS_InvalidDocumentException']:
            return None
        raise

def setup(apply=False, call=aws):
    require(call('sts', 'get-caller-identity').get('Account') == ACCOUNT, 'WRONG_AWS_ACCOUNT')
    provider = call('iam', 'get-open-id-connect-provider', '--open-id-connect-provider-arn', PROVIDER)
    require('sts.amazonaws.com' in provider.get('ClientIDList', []), 'EXISTING_OIDC_PROVIDER_NOT_READY')
    info = call('ec2', 'describe-instances', '--instance-ids', INSTANCE)['Reservations']
    require(len(info) == 1 and len(info[0]['Instances']) == 1, 'INSTANCE_NOT_UNIQUE')
    instance = info[0]['Instances'][0]
    require(instance['InstanceId'] == INSTANCE, 'INSTANCE_MISMATCH')
    profile_arn = instance.get('IamInstanceProfile', {}).get('Arn', '')
    require(profile_arn.startswith(f'arn:aws:iam::{ACCOUNT}:instance-profile/'), 'EXISTING_INSTANCE_PROFILE_MISSING')
    profile_name = profile_arn.split('/')[-1]
    profile = call('iam', 'get-instance-profile', '--instance-profile-name', profile_name)['InstanceProfile']
    roles = profile['Roles']
    require(len(roles) == 1 and roles[0]['Arn'].startswith(f'arn:aws:iam::{ACCOUNT}:role/'), 'INSTANCE_ROLE_NOT_UNIQUE')
    instance_role = roles[0]['RoleName']
    require(instance_role != ROLE_NAME, 'WP_ROLE_CANNOT_BE_INSTANCE_ROLE')
    existing_role = optional(call, 'iam', 'get-role', '--role-name', ROLE_NAME)
    if existing_role:
        attached = call('iam', 'list-attached-role-policies', '--role-name', ROLE_NAME)['AttachedPolicies']
        inline = call('iam', 'list-role-policies', '--role-name', ROLE_NAME)['PolicyNames']
        require(not attached and set(inline) <= {'PPB-WP-Draft-Runtime'}, 'WP_ROLE_HAS_UNEXPECTED_POLICIES')
    existing_doc = optional(call, 'ssm', 'describe-document', '--name', DOCUMENT)
    trust, runtime, ec2_read = policies()
    content = (ROOT / 'PPB-V2-WP-Draft-Test.ssm.json').read_text()
    spec=importlib.util.spec_from_file_location('approved_ssm',ROOT/'render-ssm.py')
    renderer=importlib.util.module_from_spec(spec);spec.loader.exec_module(renderer)
    require(json.loads(content)==renderer.document(),'LOCAL_SSM_DOCUMENT_NOT_REPRODUCIBLE')
    approved=hashlib.sha256((ROOT/'ppb_wpn2_verified.sh').read_bytes()).hexdigest()
    require(json.loads(content)['parameters']['PackageSha256'].get('allowedValues')==[approved], 'PACKAGE_NOT_PINNED')
    old_versions=call('ssm','list-document-versions','--name',DOCUMENT)['DocumentVersions'] if existing_doc else []
    current=call('ssm','get-document','--name',DOCUMENT,'--document-version','$LATEST','--document-format','JSON') if existing_doc else None
    retained=current['DocumentVersion'] if current and json.loads(current['Content'])==json.loads(content) else None
    plan = dict(account=ACCOUNT, region=REGION, instance_id=INSTANCE, oidc_provider=PROVIDER,
                wp_role_arn=ROLE, instance_role_arn=roles[0]['Arn'], ssm_document_arn=DOC_ARN,
                iam_trust=trust, wp_role_policy=runtime, instance_role_additional_policy=ec2_read,
                creates_oidc_provider=False, modifies_deploy_role=False, installs_ec2_runner=False,
                accesses_wp=False, applies=apply, approved_package_sha256=approved,
                retires_previous_document_versions=[v['DocumentVersion'] for v in old_versions if v['DocumentVersion']!=retained])
    if not apply:
        return plan
    if not existing_role:
        call('iam', 'create-role', '--role-name', ROLE_NAME,
             '--assume-role-policy-document', json.dumps(trust),
             '--description', 'PPB Issue 12 fixed SSM WordPress draft acceptance only')
    # Revoke command execution while replacing unsafe historical versions.
    # A failure below leaves this dedicated role denied, never partly enabled.
    quarantine={'Version':'2012-10-17','Statement':[{'Effect':'Deny','Action':'ssm:SendCommand','Resource':'*'}]}
    call('iam', 'put-role-policy', '--role-name', ROLE_NAME, '--policy-name', 'PPB-WP-Draft-Runtime',
         '--policy-document', json.dumps(quarantine))
    if existing_role:
        call('iam','update-assume-role-policy','--role-name',ROLE_NAME,'--policy-document',json.dumps(trust))
    call('iam', 'put-role-policy', '--role-name', instance_role, '--policy-name', 'PPB-WP-Test-ArtifactRead',
         '--policy-document', json.dumps(ec2_read))
    if existing_doc:
        if json.loads(current['Content']) == json.loads(content):
            version = current['DocumentVersion']
        else:
            changed = call('ssm', 'update-document', '--name', DOCUMENT, '--document-version', '$LATEST',
                           '--document-format', 'JSON', '--content', content)
            version = changed['DocumentDescription']['DocumentVersion']
    else:
        created = call('ssm', 'create-document', '--name', DOCUMENT, '--document-type', 'Command',
                       '--document-format', 'JSON', '--content', content)
        version = created['DocumentDescription']['DocumentVersion']
    call('ssm', 'update-document-default-version', '--name', DOCUMENT, '--document-version', version)
    # IAM SendCommand does not pin a document version. Retire every older
    # version so the branch cannot select a previously unrestricted root script.
    versions=call('ssm','list-document-versions','--name',DOCUMENT)['DocumentVersions']
    for item in versions:
        if item['DocumentVersion'] != version:
            call('ssm','delete-document','--name',DOCUMENT,'--document-version',item['DocumentVersion'])
    remaining=call('ssm','list-document-versions','--name',DOCUMENT)['DocumentVersions']
    require({v['DocumentVersion'] for v in remaining}=={version}, 'UNAPPROVED_DOCUMENT_VERSIONS_REMAIN')
    installed=call('ssm','get-document','--name',DOCUMENT,'--document-version',version,'--document-format','JSON')
    require(json.loads(installed['Content'])==json.loads(content),'INSTALLED_DOCUMENT_NOT_APPROVED')
    call('iam','put-role-policy','--role-name',ROLE_NAME,'--policy-name','PPB-WP-Draft-Runtime',
         '--policy-document',json.dumps(runtime))
    plan['document_version'] = version
    return plan

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--render-policies', action='store_true')
    args = parser.parse_args()
    if args.render_policies:
        for name, policy in zip(['wp-role-trust.json', 'wp-role-policy.json', 'ec2-artifact-read.json'], policies()):
            (ROOT / 'iam' / name).write_text(json.dumps(policy, indent=2) + '\n')
    else:
        try:
            print(json.dumps(setup(args.apply), ensure_ascii=False, indent=2))
        except (Stop, KeyError, ValueError, OSError) as e:
            print('STOP: ' + (str(e) if isinstance(e, Stop) else type(e).__name__), file=sys.stderr)
            sys.exit(2)
