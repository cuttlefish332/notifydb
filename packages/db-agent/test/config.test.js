import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { detectDialect, parseTables, resolveEnvFile } from '../src/config.js';

test('parseTables accepts table and schema.table forms', () => {
    assert.deepEqual(parseTables('users,public.orders'), [
        { schema: null, table: 'users', label: 'users' },
        { schema: 'public', table: 'orders', label: 'public.orders' },
    ]);
});

test('parseTables rejects unsafe identifiers', () => {
    assert.throws(() => parseTables('users;drop table users'), /Invalid identifier/);
});

test('detectDialect supports PostgreSQL and MySQL URLs', () => {
    assert.equal(detectDialect('postgresql://app:pass@localhost/app'), 'postgres');
    assert.equal(detectDialect('postgres://app:pass@localhost/app'), 'postgres');
    assert.equal(detectDialect('mysql://app:pass@localhost/app'), 'mysql');
});

test('resolveEnvFile prefers .env.notifydb when present', () => {
    const originalCwd = process.cwd();
    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'notifydb-agent-'));
    try {
        process.chdir(tempDir);
        assert.equal(resolveEnvFile(), '.env');
        fs.writeFileSync('.env.notifydb', 'NOTIFYDB_API_URL=https://notifydb.io\n');
        assert.equal(resolveEnvFile(), '.env.notifydb');
        assert.equal(resolveEnvFile('custom.env'), 'custom.env');
    } finally {
        process.chdir(originalCwd);
        fs.rmSync(tempDir, { recursive: true, force: true });
    }
});
