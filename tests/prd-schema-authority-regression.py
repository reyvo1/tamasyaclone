#!/usr/bin/env python3
from __future__ import annotations
import importlib.util
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
VERIFY_PATH=ROOT/'tests/uat_prd/mysql-canonical-authority-verify.py'
spec=importlib.util.spec_from_file_location('tamasya_authority_verify',VERIFY_PATH)
mod=importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(mod)

passed=0
def check(name,fn):
    global passed
    fn(); passed+=1; print(f'PASS {name}')

def canonical_objects():
    tables,triggers,files=mod.parse_schema_files([ROOT/'database_setup.sql'])
    assert len(tables)==133,(len(tables),sorted(tables)[:5])
    assert len(triggers)==5,(len(triggers),triggers)
    assert files[0]["tables"]==133 and files[0]["triggers"]==5
check('canonical backtick schema parses 133 tables and 5 trigger signatures',canonical_objects)

def hq_objects():
    tables,triggers,files=mod.parse_schema_files([ROOT/'hq/schema.sql',ROOT/'hq/delivery_schema.sql'])
    expected={'hq_property_locks','hq_snapshots','hq_heads','hq_receipts','hq_nonces','hq_reports','hq_delivery_jobs','hq_role_presets','hq_role_preset_state'}
    assert tables==expected,(tables,expected)
    assert triggers=={}
    assert [x['tables'] for x in files]==[8,1],files
check('HQ unquoted and IF NOT EXISTS schema parses exact seven tables',hq_objects)

def control_objects():
    tables,triggers,_=mod.parse_schema_files([ROOT/'hq/control_schema.sql'])
    assert tables=={'hq_companies','hq_properties','hq_principals','hq_control_audit'},tables
    assert triggers=={}
check('HQ control-plane schema uses the same unquoted identifier grammar',control_objects)

def mixed_identifier_grammar():
    with tempfile.TemporaryDirectory() as td:
        p=Path(td)/'mixed.sql'
        p.write_text('''\nCREATE TABLE plain_table (id INT PRIMARY KEY);\nCREATE TABLE IF NOT EXISTS `quoted_table` (id INT PRIMARY KEY);\nCREATE TABLE app.qualified_table (id INT PRIMARY KEY);\nCREATE TRIGGER `trg_q` BEFORE INSERT ON `quoted_table` FOR EACH ROW SET NEW.id=NEW.id;\nCREATE TRIGGER trg_u AFTER UPDATE ON plain_table FOR EACH ROW SET NEW.id=NEW.id;\n''',encoding='utf-8')
        tables,triggers,_=mod.parse_schema_files([p])
        assert tables=={'plain_table','quoted_table','qualified_table'},tables
        assert triggers['trg_q']=={'timing':'BEFORE','event':'INSERT','table':'quoted_table'}
        assert triggers['trg_u']=={'timing':'AFTER','event':'UPDATE','table':'plain_table'}
check('authority parser accepts quoted unquoted and schema-qualified identifiers',mixed_identifier_grammar)

def empty_source_fails_closed():
    result=mod.verify(set(),{},set(),{},['empty.sql'],[{'path':'empty.sql','tables':0,'triggers':0}])
    assert result['success'] is False,result
    assert result['parserIssues'],result
check('zero parsed tables is a verifier error instead of silent success',empty_source_fails_closed)

def profile_contract():
    helper=(ROOT/'tests/uat_prd/mysql-bootstrap-runtime-boundary.sh').read_text()
    smoke=(ROOT/'tests/uat_prd/run_saas_runtime_smoke.sh').read_text()
    workflow=(ROOT/'.github/workflows/tamasya-enterprise-rc1-uat.yml').read_text()
    assert 'AUTHORITY_PROFILE="${7:?authority profile required: property|hq}"' in helper
    assert 'property|hq)' in helper
    assert 'if [[ "$AUTHORITY_PROFILE" == "property" ]]' in helper
    assert 'PASS HQ migration-authority schema source-set digest' in helper
    assert 'SELECT,INSERT,UPDATE,DELETE property database_setup.sql' in smoke
    hq=workflow[workflow.index('Execute HQ delivery and object-storage adapters'):workflow.index('Re-run deployment, database-authority and scale guards after runtime simulation')]
    assert 'SELECT,INSERT,UPDATE hq hq/schema.sql hq/delivery_schema.sql' in hq
check('PMS and HQ authority profiles are explicit and cannot borrow each other metadata contract',profile_contract)

print(f'{passed} passed; 0 failed')
