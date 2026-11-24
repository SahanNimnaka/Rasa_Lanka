import json, subprocess, sys, tempfile, unittest, pathlib
from jsonschema import Draft202012Validator

ROOT = pathlib.Path(__file__).resolve().parents[4]
SCHEMA = ROOT/'research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json'
VALIDATOR_SCRIPT = ROOT/'research/phase-13.3/annotation_pipeline_step8a/scripts/validate_human_recognition.py'

def load_schema():
    return json.loads(SCHEMA.read_text(encoding='utf-8'))

def valid_individual():
    return {
        "schema_version":"human-recognition-v1",
        "record_id":"HR-IND-000001",
        "recognition_method":"individual",
        "verification_status":"confirmed",
        "ingredient_labels":["cardamom_pod"],
        "uncertain_labels":[],
        "out_of_scope_labels":[],
        "reviewer_id":"reviewer_a",
        "recognition_date":"2026-11-24",
        "notes":"confirmed from explicit evidence",
        "provenance":{"source_type":"historical_individual_inspection","source_reference":"doc-1","evidence_summary":"explicit","is_human_ground_truth":True},
        "individual":{"image_id":"IMG-000002","relative_path":"Black Pepper/black_pepper_00002.jpg","original_folder":"Black Pepper","original_filename":"black_pepper_00002.jpg"}
    }

def valid_group():
    return {
        "schema_version":"human-recognition-v1",
        "record_id":"HR-GRP-000001",
        "recognition_method":"group",
        "verification_status":"pending_confirmation",
        "ingredient_labels":["cardamom_pod"],
        "uncertain_labels":[],
        "out_of_scope_labels":[],
        "reviewer_id":None,
        "recognition_date":None,
        "notes":"group inspected; needs confirmation",
        "provenance":{"source_type":"historical_group_inspection","source_reference":"session-x","evidence_summary":"owner reported group review","is_human_ground_truth":False},
        "group":{"group_id":"GRP-cardamom","original_folder":"Cardamom","proposed_ingredient":"cardamom_pod","image_ids":["IMG-000002","IMG-000003"],"image_count":2}
    }

class TestHumanRecognitionSchema(unittest.TestCase):
    def setUp(self):
        self.validator = Draft202012Validator(load_schema())

    def test_individual_valid(self):
        errs=list(self.validator.iter_errors(valid_individual()))
        self.assertEqual(errs, [])

    def test_group_valid(self):
        errs=list(self.validator.iter_errors(valid_group()))
        self.assertEqual(errs, [])

    def test_multi_ingredient_supported(self):
        rec=valid_individual()
        rec['ingredient_labels']=["chilli_powder","turmeric_powder"]
        self.assertEqual(list(self.validator.iter_errors(rec)), [])

    def test_uncertain_preserved(self):
        rec=valid_individual()
        rec['verification_status']='uncertain'
        rec['provenance']['is_human_ground_truth']=False
        rec['ingredient_labels']=[]
        rec['uncertain_labels']=['chilli_powder']
        self.assertEqual(list(self.validator.iter_errors(rec)), [])

    def test_confirmed_requires_ground_truth(self):
        rec=valid_individual()
        rec['provenance']['is_human_ground_truth']=False
        errs=list(self.validator.iter_errors(rec))
        self.assertTrue(errs)

    def test_group_cannot_have_individual_block(self):
        rec=valid_group(); rec['individual']=valid_individual()['individual']
        errs=list(self.validator.iter_errors(rec))
        self.assertTrue(errs)

    def test_individual_requires_image_block(self):
        rec=valid_individual(); del rec['individual']
        errs=list(self.validator.iter_errors(rec))
        self.assertTrue(errs)

class TestValidatorScript(unittest.TestCase):
    def run_validator(self, rec):
        with tempfile.TemporaryDirectory() as td:
            p=pathlib.Path(td)/'rec.json'
            p.write_text(json.dumps(rec),encoding='utf-8')
            cmd=[sys.executable,str(VALIDATOR_SCRIPT),'--records',str(p)]
            proc=subprocess.run(cmd,capture_output=True,text=True,cwd=str(ROOT))
            return proc

    def test_valid_individual_passes(self):
        proc=self.run_validator(valid_individual())
        self.assertEqual(proc.returncode,0,proc.stdout+proc.stderr)

    def test_unknown_image_fails(self):
        rec=valid_individual(); rec['individual']['image_id']='IMG-999999'
        rec['individual']['relative_path']='Nope/nope.jpg'
        proc=self.run_validator(rec)
        self.assertNotEqual(proc.returncode,0)

    def test_unknown_taxonomy_label_fails(self):
        rec=valid_individual(); rec['ingredient_labels']=['not_a_real_label']
        proc=self.run_validator(rec)
        self.assertNotEqual(proc.returncode,0)

    def test_confirmed_group_warns_not_individual(self):
        rec=valid_group(); rec['verification_status']='confirmed'; rec['provenance']['is_human_ground_truth']=True
        rec['recognition_date']='2026-11-24'; rec['reviewer_id']='reviewer_a'
        proc=self.run_validator(rec)
        self.assertEqual(proc.returncode,0,proc.stdout+proc.stderr)
        self.assertIn('must not be converted to individually verified labels', proc.stdout)

if __name__=='__main__':
    unittest.main()
