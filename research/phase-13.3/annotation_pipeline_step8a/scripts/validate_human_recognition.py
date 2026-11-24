"""Validate Step 8A human recognition records.

Read-only with respect to collected images and existing pipeline outputs.
It validates JSON records against the local schema and checks inventory refs.
"""
from __future__ import annotations
import argparse, csv, json, pathlib, sys
from jsonschema import Draft202012Validator

def load_json(p):
    return json.loads(pathlib.Path(p).read_text(encoding='utf-8'))

def load_inventory(csv_path):
    rows=list(csv.DictReader(pathlib.Path(csv_path).open(encoding='utf-8',newline='')))
    by_id={r['image_id']:r for r in rows}
    by_path={r['relative_path'].replace('\\','/'):r for r in rows}
    return rows,by_id,by_path

def iter_records(path):
    p=pathlib.Path(path)
    if p.is_dir():
        for f in sorted(p.rglob('*.json')):
            if f.name.endswith('.template.json'): continue
            yield f, load_json(f)
    else:
        obj=load_json(p)
        if isinstance(obj,list):
            for i,r in enumerate(obj): yield p.joinpath(f'#{i}'), r
        else:
            yield p,obj

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--records', required=True, help='Record JSON file or directory')
    ap.add_argument('--schema', default='research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json')
    ap.add_argument('--inventory', default='research/phase-13.3/annotation_pipeline/exports/master_image_inventory.csv')
    ap.add_argument('--taxonomy', default='research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json')
    args=ap.parse_args()
    schema=load_json(args.schema)
    validator=Draft202012Validator(schema)
    inv_rows,by_id,by_path=load_inventory(args.inventory)
    tax=load_json(args.taxonomy)
    allowed=set(tax.get('in_scope_classes',[]))|set(tax.get('out_of_scope_classes',[]))
    errors=[]; warnings=[]; count=0
    for src,rec in iter_records(args.records):
        count+=1
        for e in sorted(validator.iter_errors(rec), key=str):
            loc='/'.join(map(str,e.path))
            errors.append(f'{src}: schema {loc}: {e.message}')
        method=rec.get('recognition_method')
        status=rec.get('verification_status')
        prov=rec.get('provenance',{})
        if status=='confirmed' and not prov.get('is_human_ground_truth'):
            errors.append(f'{src}: confirmed record must have provenance.is_human_ground_truth=true')
        if status=='confirmed' and not rec.get('recognition_date') and prov.get('source_type','').startswith('historical_'):
            warnings.append(f'{src}: confirmed historical record has no recognition_date; preserve unknowns explicitly if date unavailable')
        for arr in ['ingredient_labels','uncertain_labels','out_of_scope_labels']:
            for lbl in rec.get(arr,[]) or []:
                if lbl not in allowed:
                    errors.append(f'{src}: label {lbl!r} not in approved taxonomy')
        if method=='individual':
            ind=rec.get('individual',{})
            iid=ind.get('image_id'); rel=(ind.get('relative_path') or '').replace('\\','/')
            if iid and iid not in by_id: errors.append(f'{src}: unknown image_id {iid}')
            if rel and rel not in by_path: errors.append(f'{src}: unknown relative_path {rel}')
            if iid and rel and by_id.get(iid,{}).get('relative_path','').replace('\\','/')!=rel:
                errors.append(f'{src}: image_id/path mismatch for {iid}')
        elif method=='group':
            grp=rec.get('group',{}); ids=grp.get('image_ids',[])
            if grp.get('image_count')!=len(ids):
                errors.append(f'{src}: group.image_count != len(image_ids)')
            for iid in ids:
                if iid not in by_id: errors.append(f'{src}: unknown group image_id {iid}')
            if status=='confirmed':
                warnings.append(f'{src}: confirmed group record must not be converted to individually verified labels without image-level evidence')
    print(f'Records checked: {count}')
    print(f'Errors: {len(errors)}')
    for e in errors: print('ERROR',e)
    print(f'Warnings: {len(warnings)}')
    for w in warnings: print('WARN',w)
    return 1 if errors else 0

if __name__=='__main__':
    raise SystemExit(main())
