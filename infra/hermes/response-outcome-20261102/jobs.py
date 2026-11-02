#!/usr/bin/env python3
"""Durable single-worker owner queue. Unknown outcomes are never replayed."""
import json,pathlib,sqlite3,threading,time
ROOT=pathlib.Path('/var/lib/servernet-hermes-owner')
WAKE=threading.Event();LOCK=threading.Lock();WORKER=None

def db():
 c=sqlite3.connect(ROOT/'jobs.db',timeout=10)
 c.execute('PRAGMA journal_mode=WAL')
 c.execute('CREATE TABLE IF NOT EXISTS jobs(id INTEGER PRIMARY KEY,utc REAL,updated REAL,status TEXT,channel TEXT,owner_chat TEXT,owner_user TEXT,payload TEXT,result TEXT)')
 return c

def validate(p,c):
 if not c.get('owner_chat_id')or not c.get('owner_user_id'):raise ValueError('Owner not configured')
 if str(p.get('owner_chat_id'))!=str(c['owner_chat_id'])or str(p.get('owner_user_id'))!=str(c['owner_user_id']):raise ValueError('Owner mismatch')
 if abs(time.time()-float(p.get('timestamp',0)))>180:raise ValueError('Expired request')
 if type(p.get('update_id'))is not int or p['update_id']<0:raise ValueError('Invalid update id')

def status(p,c):
 validate(p,c)
 with db()as d:r=d.execute('SELECT status,result,owner_chat,owner_user,channel FROM jobs WHERE id=?',(p['update_id'],)).fetchone()
 if not r:return {'accepted':False,'status':'not_found'}
 if r[2]!=str(c['owner_chat_id'])or r[3]!=str(c['owner_user_id'])or r[4]!='miniapp':raise ValueError('Job owner/channel mismatch')
 result=json.loads(r[1])if r[1]else{}
 return {'accepted':True,'status':r[0],**({'reply':result['reply']}if r[0]in ('completed','failed_or_uncertain')and isinstance(result.get('reply'),str)else{})}

def submit(p,c,channel):
 validate(p,c)
 if p.get('bot')!='servernetbot'or not isinstance(p.get('text'),str)or not 1<=len(p['text'])<=16000:raise ValueError('Invalid message')
 import re
 if not re.fullmatch(r'/hermes(?:@servernetbot)?(?:\s+[\s\S]*)?',p['text'].strip(),re.I):raise ValueError('Not a Hermes command')
 with LOCK,db()as d:
  old=d.execute('SELECT status,owner_chat,owner_user,channel,payload FROM jobs WHERE id=?',(p['update_id'],)).fetchone()
  if old:
   prior=json.loads(old[4])if old[4]else{}
   if old[1]!=str(c['owner_chat_id'])or old[2]!=str(c['owner_user_id'])or old[3]!=channel:raise ValueError('Job owner/channel mismatch')
   if prior.get('text')!=p['text']:raise ValueError('Update id already used for another message')
   return {'accepted':True,'duplicate':True,'status':old[0]}
  if d.execute("SELECT count(*) FROM jobs WHERE status IN ('queued','running')").fetchone()[0]>=5:return {'accepted':False,'status':'queue_full'}
  now=time.time();d.execute('INSERT INTO jobs VALUES(?,?,?,?,?,?,?,?,?)',(p['update_id'],now,now,'queued',channel,str(c['owner_chat_id']),str(c['owner_user_id']),json.dumps(p),None))
 WAKE.set();return {'accepted':True,'status':'queued','update_id':p['update_id']}

def start(process,config):
 global WORKER
 if WORKER and WORKER.is_alive():return
 # A process stop may have interrupted a mutation. Preserve all receipts, never replay.
 with db()as d:d.execute("UPDATE jobs SET status='failed_or_uncertain',updated=? WHERE status='running'",(time.time(),))
 def run():
  while True:
   with LOCK,db()as d:
    row=d.execute("SELECT id,payload,channel FROM jobs WHERE status='queued' ORDER BY utc LIMIT 1").fetchone()
    if row:d.execute("UPDATE jobs SET status='running',updated=? WHERE id=?",(time.time(),row[0]))
   if not row:WAKE.wait(2);WAKE.clear();continue
   ident,raw,channel=row
   try:
    p=json.loads(raw);c=config()
    # Refresh only the transport timestamp of this already accepted authenticated job.
    p['timestamp']=time.time();validate(p,c)
    result=process(p,c,deliver=channel=='bale');s=result.get('status','failed_or_uncertain')
    if s not in ['completed','completed_delivery_uncertain','failed_or_uncertain','busy']:s='failed_or_uncertain'
   except Exception as e:
    s='failed_or_uncertain';result={'accepted':True,'status':s,'error_type':type(e).__name__}
   with db()as d:d.execute('UPDATE jobs SET status=?,result=?,updated=? WHERE id=?',(s,json.dumps(result),time.time(),ident))
   print(json.dumps({'event':'owner_job','id':ident,'channel':channel,'status':s}),flush=True)
 WORKER=threading.Thread(target=run,name='owner-job-worker',daemon=True);WORKER.start()
