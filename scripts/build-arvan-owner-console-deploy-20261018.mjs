// Local, deterministic packaging only. Never contacts or modifies production.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const base = 'd10c54974abe3fdf399e9ec00659bc8e273c5686';
const targets = [
  ['app/Http/Controllers/Account/CloudServerController.php', '4a5ac214d51b511fc653b4f4fab8e2a14eb8d154d17b191d57c534f4faeb806f'],
  ['app/Services/Cloud/ArvanConsoleBroker.php', '48fc53a2b8796254ac036bf83f07b34c1b03085e91f319dbe414d3d18725c7bc'],
];
const sha = value => createHash('sha256').update(value).digest('hex');
const lf = value => value.replaceAll('\r\n', '\n');
const b64 = value => Buffer.from(value).toString('base64');
const git = args => execFileSync('git', args, { cwd: root, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 });

export function applyHunks(source, hunks) {
  for (const { before, after } of hunks) {
    if (!before || source.split(before).length !== 2) throw new Error('Non-unique patch context');
    source = source.replace(before, () => after);
  }
  return source;
}

export function parseHunks(diff) {
  const hunks = [];
  let current;
  for (const line of lf(diff).split('\n')) {
    if (line.startsWith('@@ ')) {
      current = { before: '', after: '' };
      hunks.push(current);
    } else if (current && /^[ +\-]/.test(line)) {
      if (line[0] !== '+') current.before += line.slice(1) + '\n';
      if (line[0] !== '-') current.after += line.slice(1) + '\n';
    } else if (line.startsWith('\\ No newline')) {
      throw new Error('No-newline patch requires manual review');
    }
  }
  if (!hunks.length) throw new Error('Empty patch');
  return hunks;
}

export function buildPayload() {
  const files = targets.map(([relative, expected_live_sha256]) => {
    if (!/^[a-f0-9]{64}$/.test(expected_live_sha256)) throw new Error('Invalid live hash');
    const repoPath = 'website/' + relative;
    const original = lf(git(['show', base + ':' + repoPath]));
    const patched = lf(readFileSync(path.join(root, repoPath), 'utf8'));
    const hunks = parseHunks(git(['diff', '--no-ext-diff', '--no-textconv', '--unified=3', base, '--', repoPath]));
    if (applyHunks(original, hunks) !== patched) throw new Error('Patch does not reproduce complete Git result: ' + relative);
    if (applyHunks(patched, hunks.map(h => ({ before: h.after, after: h.before }))) !== original) throw new Error('Reverse contract mismatch');
    return { path: relative, expected_live_sha256, base_sha256: sha(original), patched_sha256: sha(patched),
      base_b64: b64(original), patched_b64: b64(patched),
      hunks: hunks.map(h => ({ before_b64: b64(h.before), after_b64: b64(h.after) })) };
  });
  return JSON.stringify({ schema: 1, base_commit: base, files }, null, 2) + '\n';
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  if (process.argv.length !== 3 || !path.isAbsolute(process.argv[2])) throw new Error('Usage: node builder.mjs ABSOLUTE_NEW_PRIVATE_OUTPUT_DIRECTORY');
  const output = path.resolve(process.argv[2]);
  const relative = path.relative(root, output);
  if (!relative.startsWith('..' + path.sep) && !path.isAbsolute(relative)) throw new Error('Artifact must be outside repository');
  const payload = buildPayload();
  const template = readFileSync(path.join(root, 'scripts/deploy-arvan-owner-console-20261018.php'), 'utf8');
  if (template.split('__PAYLOAD_SHA256__').length !== 2) throw new Error('Helper hash placeholder mismatch');
  const helper = template.replace('__PAYLOAD_SHA256__', sha(payload));
  mkdirSync(output, { mode: 0o700 }); // Existing destinations fail; never overwrite a previous release.
  writeFileSync(path.join(output, 'payload.json'), payload, { flag: 'wx', mode: 0o600 });
  writeFileSync(path.join(output, 'deploy.php'), helper, { flag: 'wx', mode: 0o600 });
  console.log(JSON.stringify({ prepared_locally: true, files: targets.map(t => t[0]), payload_sha256: sha(payload), helper_sha256: sha(helper), output }));
}
