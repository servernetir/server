#!/usr/bin/env python3
"""Checks for one article; fixtures use local Git only, no CMS or credentials.

Laravel integration and production acceptance are separate required gates.
"""
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'website/resources/content/growth/git-basics-for-deployment.json'
COMMANDS = [
    ['git', 'status', '--porcelain'],
    ['git', 'rev-parse', 'HEAD'],
    ['git', 'fetch', 'origin'],
    ['git', 'log', '--oneline', 'HEAD..origin/production'],
    ['git', 'diff', '--stat', 'HEAD..origin/production'],
]
SNIPPET = '\n'.join(' '.join(command) for command in COMMANDS)


class Body(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.stack = []
        self.errors = []
        self.blocks = []
        self.block = None
        self.links = []
        self.h2 = 0

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag not in {'p', 'strong', 'em', 'code', 'h2', 'h3', 'pre', 'a', 'ol', 'ul', 'li'}:
            self.errors.append('unexpected tag: ' + tag)
        if any(key.startswith('on') for key in attrs):
            self.errors.append('event-handler attribute')
        self.stack.append(tag)
        if tag == 'h2':
            self.h2 += 1
        if tag == 'a':
            self.links.append(attrs.get('href', ''))
        if tag == 'pre':
            self.block = []

    def handle_endtag(self, tag):
        if not self.stack or self.stack.pop() != tag:
            self.errors.append('unbalanced tag: ' + tag)
        if tag == 'pre' and self.block is not None:
            self.blocks.append(''.join(self.block))
            self.block = None

    def handle_data(self, data):
        if self.block is not None:
            self.block.append(data)


class ArticleSourceTest(unittest.TestCase):
    def test_titles_locales_and_urls_are_preserved(self):
        article = json.loads(SOURCE.read_text(encoding='utf-8'))
        self.assertEqual('git-basics-for-deployment', article['slug'])
        self.assertEqual(['fa', 'en', 'tr'], list(article['translations']))
        titles = {
            'fa': 'آموزش گیت برای استقرار سایت؛ از مخزن تا دیپلوی روی سرور',
            'en': 'Git basics for deploying your site',
            'tr': 'Site dağıtımı için Git temelleri',
        }
        for locale, title in titles.items():
            with self.subTest(locale=locale):
                translation = article['translations'][locale]
                self.assertEqual(title, translation['title'])
                self.assertTrue(translation['excerpt'].strip())
                self.assertTrue(translation['content'].strip())

    def test_markup_sources_and_only_reviewed_cli_block(self):
        article = json.loads(SOURCE.read_text(encoding='utf-8'))
        for locale, prefix in [('fa', ''), ('en', '/en'), ('tr', '/tr')]:
            with self.subTest(locale=locale):
                source = article['translations'][locale]['content']
                body = Body()
                body.feed(source)
                body.close()
                self.assertEqual([], body.errors)
                self.assertEqual([], body.stack)
                self.assertGreaterEqual(body.h2, 5)
                self.assertEqual([SNIPPET], body.blocks)
                self.assertEqual([prefix + '/vps/hourly'], [link for link in body.links if link.startswith('/')])
                self.assertIn('https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries', body.links)
                self.assertIn('https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/', body.links)
                self.assertNotIn('<?php', source)
                self.assertNotIn('shell_exec(', source)
                self.assertIn('X-Hub-Signature-256', source)
                self.assertIn('X-GitHub-Delivery', source)


class GitExampleTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='git-article-fixture-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.origin = self.root / 'origin'
        self.checkout = self.root / 'checkout'
        self.env = os.environ.copy()
        for key in list(self.env):
            if key.startswith('GIT_'):
                self.env.pop(key)
        self.env.update({
            'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': os.devnull,
            'GIT_TERMINAL_PROMPT': '0', 'GIT_ALLOW_PROTOCOL': 'file',
            'GIT_AUTHOR_NAME': 'Synthetic fixture', 'GIT_COMMITTER_NAME': 'Synthetic fixture',
            'GIT_AUTHOR_EMAIL': 'fixture@example.invalid', 'GIT_COMMITTER_EMAIL': 'fixture@example.invalid',
        })
        self.run_git(self.root, 'init', '-q', '-b', 'production', str(self.origin))
        (self.origin / '.gitignore').write_text('ignored-state.txt\n')
        (self.origin / 'index.txt').write_text('initial\n')
        self.run_git(self.origin, 'add', '.')
        self.run_git(self.origin, 'commit', '-qm', 'Initial fixture')
        self.run_git(self.root, 'clone', '-q', str(self.origin), str(self.checkout))
        self.initial = self.run_git(self.checkout, 'rev-parse', 'HEAD').strip()
        (self.origin / 'index.txt').write_text('reviewed candidate\n')
        self.run_git(self.origin, 'commit', '-qam', 'Candidate fixture')
        self.candidate = self.run_git(self.origin, 'rev-parse', 'HEAD').strip()

    def run_git(self, cwd, *args):
        result = subprocess.run(['git', *args], cwd=cwd, env=self.env,
                                text=True, capture_output=True, timeout=15, check=True)
        return result.stdout

    def test_exact_article_sequence_fetches_refs_without_installing_candidate(self):
        outputs = [self.run_git(self.checkout, *command[1:]) for command in COMMANDS]
        self.assertEqual('', outputs[0])
        self.assertEqual(self.initial, outputs[1].strip())
        self.assertIn('Candidate fixture', outputs[3])
        self.assertIn('index.txt', outputs[4])
        self.assertEqual(self.candidate, self.run_git(self.checkout, 'rev-parse', 'origin/production').strip())
        self.assertEqual(self.initial, self.run_git(self.checkout, 'rev-parse', 'HEAD').strip())
        self.assertEqual('initial\n', (self.checkout / 'index.txt').read_text())

    def test_existing_worktree_edits_are_visible_and_preserved(self):
        (self.checkout / 'index.txt').write_text('coworker change\n')
        outputs = [self.run_git(self.checkout, *command[1:]) for command in COMMANDS]
        self.assertIn(' M index.txt', outputs[0])
        self.assertEqual('coworker change\n', (self.checkout / 'index.txt').read_text())
        self.assertEqual(self.initial, self.run_git(self.checkout, 'rev-parse', 'HEAD').strip())

    def test_clean_status_does_not_prove_ignored_runtime_files_absent(self):
        (self.checkout / 'ignored-state.txt').write_text('synthetic persistent state\n')
        self.assertEqual('', self.run_git(self.checkout, 'status', '--porcelain'))
        self.assertTrue((self.checkout / 'ignored-state.txt').exists())
        self.assertIn('ignored-state.txt', self.run_git(self.checkout, 'status', '--porcelain', '--ignored'))

    def test_missing_production_ref_is_a_failed_prerequisite(self):
        other = self.root / 'no-production'
        self.run_git(self.root, 'init', '-q', '-b', 'main', str(other))
        (other / 'file.txt').write_text('sample\n')
        self.run_git(other, 'add', '.')
        self.run_git(other, 'commit', '-qm', 'Main only')
        with self.assertRaises(subprocess.CalledProcessError):
            self.run_git(other, 'log', '--oneline', 'HEAD..origin/production')


if __name__ == '__main__':
    unittest.main(verbosity=2)
