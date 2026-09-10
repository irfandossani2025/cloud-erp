"""Exercise the local ERP API and remove only this run's test records."""
import json, urllib.request, urllib.error, uuid, sqlite3
from pathlib import Path
BASE='http://127.0.0.1:5173'
created={'agents':[], 'products':[], 'quotes':[]}
def call(action=None, payload=None, expected=200, path='/api/erp', headers=None):
 data=None if action is None else json.dumps({'action':action,**(payload or {})}).encode()
 req=urllib.request.Request(BASE+path,data=data,headers={'Content-Type':'application/json',**(headers or {})})
 try:
  with urllib.request.urlopen(req,timeout=70) as r: status,body=r.status,json.load(r)
 except urllib.error.HTTPError as e:
  status=e.code
  raw=e.read().decode()
  try: body=json.loads(raw)
  except json.JSONDecodeError: body={'error':raw}
 assert status in (expected if isinstance(expected,tuple) else (expected,)),(status,body)
 return body
try:
 original=call()
 assert len(original['products'])>0
 agent=call('agent',{'name':'Integration test '+str(uuid.uuid4())})['id'];created['agents'].append(agent)
 sku='TEST-'+str(uuid.uuid4())
 product={'name':'Integration test bottle','sku':sku,'description':'Temporary verification record','category':'Tests','image':'','warehouseStock':25,'saleBaisa':3500,'costBaisa':1750}
 pid=call('product',{'product':product})['id'];created['products'].append(pid)
 call('product',{'product':product},400)
 call('product',{'product':{**product,'sku':sku+'-negative','saleBaisa':-1}},400)
 quote={'agent':agent,'customer':'Integration test customer','email':'','notes':'Temporary test','rate':0.104699,'lines':[{'productId':pid,'quantity':100,'unitBaisa':3500,'branding':'One colour logo'}]}
 qid=call('quote',{'quote':quote})['id'];created['quotes'].append(qid)
 saved=next(q for q in call()['quotes'] if q['id']==qid)
 assert saved['total']==350000 and saved['lines'][0]['costBaisa']==1750
 call('quote',{'quote':{**quote,'lines':[{**quote['lines'][0],'quantity':1.5}]}},400)
 call('quote',{'quote':{**quote,'id':qid,'revision':saved['revision'],'rate':0.9,'customer':'Updated test customer'}})
 edited=next(q for q in call()['quotes'] if q['id']==qid)
 assert edited['rate']==saved['rate'] and edited['total']==saved['total']
 call('quote',{'quote':{**quote,'id':qid,'revision':saved['revision']}},400)
 call('status',{'id':qid,'revision':edited['revision'],'status':'Reviewed'})
 call('quote',{'quote':{**quote,'id':qid,'revision':edited['revision']+1}},400)
 call('agent',{'name':'Blocked request'},(400,403),headers={'Origin':'https://other.example'})
 call(expected=(400,403),headers={'Host':'public.example'})
 if not original['aiConfigured']:
  error=call('unused',{'agent':agent,'prompt':'100 bottles','rate':.104699},400,path='/api/ai')
  assert 'API key' in error['error']
 after=call()
 assert next(p for p in after['products'] if p['id']==pid)['warehouse_stock']==25
 assert len(after['products'])==len(original['products'])+1
 print('PASS: create product, duplicate SKU, validation, quotation arithmetic, persistence, rate preservation, revision conflicts, reviewed-draft protection, origin/host guard, missing AI configuration.')
finally:
 # The worker's local D1 SQLite file is owned by this checkout. Match the schema,
 # then delete only identifiers returned by this test run; never touch user data.
 for path in Path('.wrangler/state/v3/d1').rglob('*.sqlite'):
  con=sqlite3.connect(path)
  tables={r[0] for r in con.execute("SELECT name FROM sqlite_master WHERE type='table'")}
  if {'agents','products','quotes'}.issubset(tables):
   for table in ['quotes','products','agents']:
    for identifier in created[table]: con.execute(f'DELETE FROM {table} WHERE id=?',(identifier,))
   con.commit()
  con.close()
