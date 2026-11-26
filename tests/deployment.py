"""Exercise deployment arguments with a fake CLI; no cloud resources or builds."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent


class DeploymentTests(unittest.TestCase):
    def deploy(self, **overrides):
        with tempfile.TemporaryDirectory() as directory:
            temporary = Path(directory)
            calls = temporary / 'calls.jsonl'
            cli = temporary / 'gcloud'
            cli.write_text('''#!/usr/bin/env python3
import json, os, sys
with open(os.environ['COLLECTOR_TEST_CALLS'], 'a') as stream:
    stream.write(json.dumps(sys.argv[1:]) + '\\n')
if sys.argv[1:4] == ['artifacts', 'repositories', 'describe'] and os.environ.get('COLLECTOR_TEST_NEW_REPO') == 'true':
    sys.exit(1)
''')
            cli.chmod(0o700)
            environment = {**os.environ, 'PATH': directory + os.pathsep + os.environ['PATH'],
                           'PROJECT_ID': 'fixture-project', 'REGION': 'us-central1',
                           'SYNAPSE_URL': 'https://example.com',
                           'ADMIN_INVOKER_EMAIL': 'php@fixture-project.iam.gserviceaccount.com',
                           'COLLECTOR_TEST_CALLS': str(calls), **overrides}
            result = subprocess.run(['bash', 'deploy.sh'], cwd=ROOT, env=environment,
                                    text=True, capture_output=True, timeout=20)
            recorded = [json.loads(line) for line in calls.read_text().splitlines()] if calls.exists() else []
            return result, recorded

    def test_http_job_is_bounded_and_daily_eat(self):
        result, calls = self.deploy(IMAGE_TARGET='http')
        self.assertEqual(result.returncode, 0, result.stderr)
        job = next(call for call in calls if call[:3] == ['run', 'jobs', 'deploy'])
        for flag in ['--tasks=1', '--parallelism=1', '--max-retries=0', '--task-timeout=1200s', '--cpu=1', '--memory=512Mi']:
            self.assertIn(flag, job)
        self.assertNotIn('--execute-now', job)
        schedule = next(call for call in calls if call[:4] == ['scheduler', 'jobs', 'update', 'http'])
        self.assertIn('--schedule=0 7 * * *', schedule)
        self.assertIn('--time-zone=Africa/Nairobi', schedule)
        self.assertIn('--max-retry-attempts=0', schedule)
        self.assertFalse(any('cloudsql' in part or 'vpc-connector' in part for call in calls for part in call))
        self.assertFalse(any(call[:3] == ['secrets', 'versions', 'add'] for call in calls))

    def test_browser_image_and_existing_repository_policy(self):
        result, calls = self.deploy(IMAGE_TARGET='browser')
        self.assertEqual(result.returncode, 0, result.stderr)
        job = next(call for call in calls if call[:3] == ['run', 'jobs', 'deploy'])
        self.assertIn('--memory=2Gi', job)
        self.assertFalse(any(call[:3] == ['artifacts', 'repositories', 'set-cleanup-policies'] for call in calls))

    def test_new_repository_gets_image_cleanup(self):
        result, calls = self.deploy(IMAGE_TARGET='http', COLLECTOR_TEST_NEW_REPO='true')
        self.assertEqual(result.returncode, 0, result.stderr)
        cleanup = next(call for call in calls if call[:3] == ['artifacts', 'repositories', 'set-cleanup-policies'])
        self.assertIn('--policy=deploy/cleanup-policy.json', cleanup)
        self.assertIn('--no-dry-run', cleanup)

    def test_invalid_configuration_stops_before_cloud_calls(self):
        for values in [{'IMAGE_TARGET': 'unexpected'}, {'SYNAPSE_URL': 'http://127.0.0.1'}, {'PROJECT_ID': 'invalid/value'}]:
            result, calls = self.deploy(**values)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(calls, [])


if __name__ == '__main__':
    unittest.main()
