"""Exercise the actual provisioning shell with a fake Ollama process, no API costs."""
import os
from pathlib import Path
import re
import signal
import subprocess
import tempfile
import time
import unittest

SOURCE = Path(__file__).resolve().parents[2] / 'website/app/Services/Cloud/SaladOperations.php'
COMMAND = re.search(r"OLLAMA_START_COMMAND = <<<'SH'\n(.*?)\nSH;", SOURCE.read_text(), re.S).group(1)
FAKE = '''#!/usr/bin/env python3
import os, pathlib, signal, sys, time
root=pathlib.Path(os.environ['FAKE_ROOT'])
mode=sys.argv[1]
if mode=='serve':
 (root/'server-host').write_text(os.environ['OLLAMA_HOST'])
 while True: time.sleep(.05)
elif mode=='list':
 sys.exit(0 if (root/'server-host').exists() else 1)
elif mode=='run':
 (root/'client-host').write_text(os.environ['OLLAMA_HOST'])
 (root/'model').write_text(sys.argv[3])
 time.sleep(.3)
 sys.exit(int(os.environ['FAKE_RUN_EXIT']))
elif mode=='ps':
 print('MODEL SIZE PROCESSOR\\nllama3.1 5GB '+os.environ.get('FAKE_GPU','100% GPU'))
else: sys.exit(2)
'''


class StartupTest(unittest.TestCase):
    def exercise(self, run_exit, gpu="100% GPU"):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            binary = root/'ollama'
            binary.write_text(FAKE)
            binary.chmod(0o700)
            marker = root/'ready'
            env = os.environ | {'PATH':folder+':'+os.environ['PATH'], 'FAKE_ROOT':folder,
                'FAKE_RUN_EXIT':str(run_exit), 'FAKE_GPU':gpu, 'OLLAMA_HOST':'[::]:11434',
                'OLLAMA_MODEL_NAME':'qwen3.5:4b'}
            command = COMMAND.replace('/tmp/ollama_model_ready', str(marker))
            p = subprocess.Popen(['/bin/sh','-c',command], env=env, start_new_session=True,
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                deadline=time.monotonic()+6
                saw_loading=False
                while time.monotonic()<deadline:
                    if (root/'model').exists() and not marker.exists(): saw_loading=True
                    if marker.exists() or p.poll() is not None: break
                    time.sleep(.02)
                self.assertTrue(saw_loading, 'Readiness must wait for model loading')
                self.assertEqual((root/'server-host').read_text(),'[::]:11434')
                self.assertEqual((root/'client-host').read_text(),'http://[::1]:11434')
                self.assertEqual((root/'model').read_text(),'qwen3.5:4b')
                if run_exit==0 and gpu!="100% CPU":
                    self.assertTrue(marker.exists())
                    self.assertIsNone(p.poll())
                else:
                    self.assertFalse(marker.exists())
                    self.assertIsNotNone(p.poll(), 'A failed model must stop the server')
            finally:
                if p.poll() is None: os.killpg(p.pid,signal.SIGTERM)
                p.wait(timeout=3)
    def test_ready_only_after_model_runs(self): self.exercise(0)
    def test_failed_model_never_becomes_ready(self): self.exercise(1)
    def test_cpu_only_model_never_becomes_ready(self): self.exercise(0, "100% CPU")
    def test_mixed_gpu_model_can_be_ready(self): self.exercise(0, "25%/75% CPU/GPU")
    def test_missing_model_stops_before_starting(self):
        env = os.environ.copy(); env.pop('OLLAMA_MODEL_NAME',None)
        result=subprocess.run(['/bin/sh','-c',COMMAND],env=env,capture_output=True,timeout=3)
        self.assertNotEqual(result.returncode,0)
        self.assertIn(b'model required',result.stderr)

if __name__=='__main__': unittest.main()
