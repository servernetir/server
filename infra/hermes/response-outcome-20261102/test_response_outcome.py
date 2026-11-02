import io,json,pathlib,tempfile,time,unittest
from unittest import mock
import owner_ingress as m
import jobs

def completion(text='سلام',finish='stop',**extra):
 return {'choices':[{'message':{'role':'assistant','content':text},'finish_reason':finish}],**extra}

class ResponseOutcomeTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory()
  self.owner_root=m.ROOT;self.jobs_root=jobs.ROOT
  m.ROOT=jobs.ROOT=pathlib.Path(self.tmp.name)
  self.cfg={'owner_chat_id':'77','owner_user_id':'1'}
  self.payload={'bot':'servernetbot','owner_chat_id':'77','owner_user_id':'1','update_id':123,'timestamp':time.time(),'text':'/hermes سلام'}
 def tearDown(self):
  m.ROOT=self.owner_root;jobs.ROOT=self.jobs_root;self.tmp.cleanup()
 def call(self,response):
  with mock.patch.object(m,'localkey',return_value='test'),mock.patch.object(m.urllib.request,'urlopen',return_value=io.BytesIO(json.dumps(response).encode())) as api,mock.patch.object(m,'reply_bale') as delivery:
   result=m.process(self.payload,self.cfg,False)
  return result,api,delivery
 def test_completed_text_is_preserved(self):
  result,api,delivery=self.call(completion())
  self.assertEqual(result,{'accepted':True,'status':'completed','reply':'سلام'});api.assert_called_once();delivery.assert_not_called()
  with m.db() as d:self.assertEqual(d.execute('select status,reply from messages').fetchone(),('completed','سلام'))
 def test_exact_runtime_notices_are_not_answers(self):
  for text in m.FAILED_REPLY_NOTICES:
   with self.subTest(text=text),self.assertRaises(ValueError):m.completion_reply(completion('  '+text+'  '))
 def test_ordinary_text_quoting_notice_is_preserved(self):
  text='Explanation: '+next(iter(m.FAILED_REPLY_NOTICES))
  self.assertEqual(m.completion_reply(completion(text)),text)
 def test_structured_failed_or_partial_results_are_rejected(self):
  for meta in [{'failed':True},{'partial':True},{'completed':False},{'error':'private upstream detail'},None,'invalid']:
   with self.subTest(meta=meta),self.assertRaises(ValueError):m.completion_reply(completion(hermes=meta))
 def test_unfinished_results_are_rejected(self):
  for finish in ['error','length','tool_calls',None,'unknown']:
   with self.subTest(finish=finish),self.assertRaises(ValueError):m.completion_reply(completion(finish=finish))
 def test_empty_and_malformed_envelopes_are_rejected(self):
  for value in [None,[],{}, {'choices':[]},{'choices':[None]}, {'choices':[{'finish_reason':'stop','message':None}]},completion(''),completion('  '),completion(None),completion(['text']),completion(error={'message':'private'})]:
   with self.subTest(value=value),self.assertRaises(ValueError):m.completion_reply(value)
 def test_failure_persists_safe_reply_without_retry(self):
  result,api,delivery=self.call(completion(next(iter(m.FAILED_REPLY_NOTICES))))
  self.assertEqual(result,{'accepted':True,'status':'failed_or_uncertain','reply':m.UNCERTAIN_REPLY});api.assert_called_once();delivery.assert_not_called()
  with m.db() as d:self.assertEqual(d.execute('select status,reply from messages').fetchone(),('failed_or_uncertain',m.UNCERTAIN_REPLY))
  with mock.patch.object(m.urllib.request,'urlopen') as repeated:
   self.assertTrue(m.process(self.payload,self.cfg,False)['duplicate']);repeated.assert_not_called()
 def test_transport_error_never_exposes_raw_detail(self):
  with mock.patch.object(m,'localkey',return_value='test'),mock.patch.object(m.urllib.request,'urlopen',side_effect=RuntimeError('PRIVATE_SECRET')) as api:
   result=m.process(self.payload,self.cfg,False)
  self.assertEqual(result['status'],'failed_or_uncertain');self.assertNotIn('PRIVATE_SECRET',json.dumps(result));api.assert_called_once()
 def test_failed_status_is_read_only_owner_scoped_and_has_no_replay(self):
  result,_,_=self.call(completion('partial',finish='length'))
  jobs.submit(self.payload,self.cfg,'miniapp')
  with jobs.db() as d:d.execute('update jobs set status=?,result=? where id=?',('failed_or_uncertain',json.dumps(result),123))
  with mock.patch.object(m.urllib.request,'urlopen') as api:
   for _ in range(2):self.assertEqual(jobs.status(self.payload,self.cfg),{'accepted':True,'status':'failed_or_uncertain','reply':m.UNCERTAIN_REPLY})
   api.assert_not_called()
  with self.assertRaises(ValueError):jobs.status(dict(self.payload,owner_user_id='2'),self.cfg)
 def test_channel_and_dedup_guards_remain(self):
  jobs.submit(self.payload,self.cfg,'bale')
  with self.assertRaises(ValueError):jobs.status(self.payload,self.cfg)
  with self.assertRaises(ValueError):jobs.submit(dict(self.payload,text='/hermes different'),self.cfg,'bale')
  self.assertTrue(jobs.submit(self.payload,self.cfg,'bale')['duplicate'])

if __name__=='__main__':unittest.main()
