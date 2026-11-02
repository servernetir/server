import importlib.util,pathlib,tempfile,unittest,time,unittest.mock as mock
s=importlib.util.spec_from_file_location('owner_ingress',pathlib.Path(__file__).with_name('owner_ingress.py'));m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class OwnerTests(unittest.TestCase):
 def setUp(self):
  self.t=tempfile.TemporaryDirectory();m.ROOT=pathlib.Path(self.t.name);self.c={'owner_chat_id':'77','owner_user_id':'1'};self.p={'bot':'servernetbot','owner_chat_id':'77','owner_user_id':'1','update_id':123,'timestamp':time.time(),'text':'/hermes'}
 def tearDown(self):self.t.cleanup()
 def test_help_and_duplicate(self):
  self.assertEqual(m.process(self.p,self.c,False)['status'],'completed');self.assertTrue(m.process(self.p,self.c,False)['duplicate'])
 def test_owner_unconfigured_or_wrong(self):
  for c in [{},dict(self.c,owner_chat_id='88'),dict(self.c,owner_user_id='2')]:
   with self.assertRaises(ValueError):m.process(self.p,c,False)
 def test_expired_wrong_bot_and_non_command(self):
  for p in [dict(self.p,timestamp=0),dict(self.p,bot='other'),dict(self.p,text='/start'),dict(self.p,update_id=True)]:
   with self.assertRaises(ValueError):m.process(p,self.c,False)
 def test_busy_is_delivered_once(self):
  m.RUN.acquire()
  try:
   with mock.patch.object(m,'reply_bale')as send:
    self.assertEqual(m.process(self.p,self.c)['status'],'busy');self.assertTrue(m.process(self.p,self.c)['duplicate']);send.assert_called_once()
  finally:m.RUN.release()
 def test_uncertain_execution_is_never_retried(self):
  with mock.patch.object(m,'localkey',return_value='test'),mock.patch.object(m.urllib.request,'urlopen',side_effect=TimeoutError)as api:
   p=dict(self.p,text='/hermes a task');self.assertEqual(m.process(p,self.c,False)['status'],'failed_or_uncertain');self.assertTrue(m.process(p,self.c,False)['duplicate']);api.assert_called_once()
 def test_delivery_failure_does_not_repeat_execution(self):
  with mock.patch.object(m,'reply_bale',side_effect=TimeoutError)as send:
   self.assertEqual(m.process(self.p,self.c)['status'],'completed_delivery_uncertain');self.assertTrue(m.process(self.p,self.c)['duplicate']);send.assert_called_once()
if __name__=='__main__':unittest.main()
