import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { chmodSync, copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const playwrightVersion = JSON.parse(readFileSync(path.join(root, 'Build/package-lock.json'), 'utf8'))
    .packages['node_modules/@playwright/test'].version;

// Run the real helper in a throw-away project with a Docker recorder. No daemon,
// containers, package installs or application files are touched by these tests.
function runHelper(args) {
    const fixture = mkdtempSync(path.join(tmpdir(), 'mcp helper contract '));
    try {
        const build = path.join(fixture, 'Build');
        const bin = path.join(fixture, 'bin');
        const log = path.join(fixture, 'docker.jsonl');
        mkdirSync(build);
        mkdirSync(bin);
        copyFileSync(path.join(root, 'Build/runTests.sh'), path.join(build, 'runTests.sh'));
        symlinkSync(process.execPath, path.join(bin, 'node'));
        const docker = path.join(bin, 'docker');
        writeFileSync(docker, `#!/usr/bin/env node
import { appendFileSync, readFileSync } from 'node:fs';
const args = process.argv.slice(2);
const log = process.env.MCP_HELPER_DOCKER_LOG;
appendFileSync(log, JSON.stringify(args) + '\\n');
if (args[0] === 'ps') {
    for (const call of readFileSync(log, 'utf8').trim().split('\\n').map(JSON.parse)) {
        const index = call.indexOf('--name');
        if (call[0] === 'run' && index !== -1) console.log(call[index + 1]);
    }
} else if (!['info', 'run', 'exec', 'network', 'rm', 'logs'].includes(args[0])) {
    process.exit(99);
}
`);
        chmodSync(docker, 0o755);
        // Limit PATH so even a broken recorder cannot fall through to a host Docker.
        const result = spawnSync('/bin/bash', [path.join(build, 'runTests.sh'), ...args], {
            env: { ...process.env, PATH: `${bin}:/usr/bin:/bin`, MCP_HELPER_DOCKER_LOG: log },
            encoding: 'utf8',
            timeout: 10_000,
        });
        const calls = (() => {
            try { return readFileSync(log, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse); }
            catch (error) { if (error.code === 'ENOENT') return []; throw error; }
        })();
        return { ...result, calls };
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
}

function assertSuccess(result) {
    assert.equal(result.error, undefined);
    assert.equal(result.status, 0, result.stderr || result.stdout);
}

for (const [label, options, version] of [
    ['default', [], '8.5'],
    ['supported PHP 8.4', ['-p', '8.4'], '8.4'],
]) {
    test(`functional helper selects ${label} runtime and preserves PHPUnit arguments`, () => {
        const result = runHelper([...options, '--', '--filter=Tool with spaces']);
        assertSuccess(result);
        const run = result.calls.find((call) => call[0] === 'run');
        assert.ok(run.includes(`php:${version}-cli`));
        assert.equal(run.at(-1), '--filter=Tool with spaces');
    });

    test(`Docker E2E helper selects ${label} runtime and lock-matched browser image`, () => {
        const result = runHelper(['-s', 'e2e', ...options, '--', '--grep', 'backend module']);
        assertSuccess(result);
        const runs = result.calls.filter((call) => call[0] === 'run');
        assert.ok(runs.some((call) => call.includes(`chialab/php:${version}`)));
        const browser = runs.find((call) => call.some((arg) => arg.startsWith('mcr.microsoft.com/playwright:')));
        assert.ok(browser.includes(`mcr.microsoft.com/playwright:v${playwrightVersion}-noble`));
        assert.deepEqual(browser.slice(-3), ['bash', '--grep', 'backend module']);
        assert.match(browser[browser.indexOf('-c') + 1], /playwright test "\$@"/);
    });
}

test('lint helper honors the selected PHP runtime', () => {
    const result = runHelper(['-s', 'lint', '-p', '8.4']);
    assertSuccess(result);
    assert.ok(result.calls.some((call) => call[0] === 'run' && call.includes('php:8.4-cli')));
});

test('default functional helper works without extra arguments', () => {
    const result = runHelper([]);
    assertSuccess(result);
    assert.ok(result.calls.some((call) => call[0] === 'run' && call.includes('php:8.5-cli')));
});

test('PHP below the supported baseline is rejected before invoking Docker', () => {
    const result = runHelper(['-p', '8.3']);
    assert.equal(result.status, 1);
    assert.match(result.stderr, /unsupported PHP version/);
    assert.deepEqual(result.calls, []);
});
