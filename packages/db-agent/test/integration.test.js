import assert from 'node:assert/strict';
import { execFile as execFileCallback } from 'node:child_process';
import { promisify } from 'node:util';
import test from 'node:test';
import { forwardBatch } from '../src/agent.js';
import { openDatabase } from '../src/db.js';
import { parseTableName } from '../src/config.js';

const execFile = promisify(execFileCallback);
const runIntegration = process.env.RUN_DB_AGENT_INTEGRATION === '1';

test('PostgreSQL triggers write outbox rows and the agent forwards them', { skip: !runIntegration }, async () => {
    const container = await startContainer('postgres:16-alpine', [
        '-e', 'POSTGRES_PASSWORD=notifydb',
        '-e', 'POSTGRES_DB=notifydb_test',
        '-p', '5432',
    ]);

    try {
        const port = await mappedPort(container, '5432/tcp');
        const databaseUrl = `postgresql://postgres:notifydb@127.0.0.1:${port}/notifydb_test`;
        const database = await waitForDatabase(databaseUrl);

        try {
            await database.client.query('CREATE TABLE users (id BIGSERIAL PRIMARY KEY, email TEXT NOT NULL, plan TEXT NOT NULL)');
            await database.install([parseTableName('public.users')]);
            await database.client.query("INSERT INTO users (email, plan) VALUES ('first@example.com', 'free')");
            await database.client.query("UPDATE users SET plan = 'pro' WHERE id = 1");
            await database.client.query('DELETE FROM users WHERE id = 1');

            const rows = await postgresOutboxRows(database);
            assert.equal(rows.length, 3);
            assert.deepEqual(rows.map((row) => row.eventType), ['created', 'updated', 'deleted']);
            assert.equal(rows[0].tableName, 'public.users');
            assert.equal(rows[0].recordId, '1');
            assert.equal(rows[1].oldValues.plan, 'free');
            assert.equal(rows[1].newValues.plan, 'pro');

            const received = [];
            globalThis.fetch = async (url, options) => {
                received.push({ url, body: JSON.parse(options.body) });
                return new Response('{}', { status: 201 });
            };

            await forwardBatch(database, testConfig(), silentLogger());
            assert.equal(received.length, 3);
        } finally {
            await database.close();
        }
    } finally {
        await stopContainer(container);
    }
});

test('MySQL triggers write outbox rows and the agent forwards them', { skip: !runIntegration }, async () => {
    const container = await startContainer('mysql:8.4', [
        '-e', 'MYSQL_ROOT_PASSWORD=notifydb',
        '-e', 'MYSQL_DATABASE=notifydb_test',
        '-p', '3306',
    ]);

    try {
        const port = await mappedPort(container, '3306/tcp');
        const databaseUrl = `mysql://root:notifydb@127.0.0.1:${port}/notifydb_test`;
        const database = await waitForDatabase(databaseUrl);

        try {
            await database.connection.query('CREATE TABLE users (id BIGINT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, plan VARCHAR(64) NOT NULL)');
            await database.install([parseTableName('users')]);
            await database.connection.query("INSERT INTO users (email, plan) VALUES ('first@example.com', 'free')");
            await database.connection.query("UPDATE users SET plan = 'pro' WHERE id = 1");
            await database.connection.query('DELETE FROM users WHERE id = 1');

            const rows = await mysqlOutboxRows(database);
            assert.equal(rows.length, 3);
            assert.deepEqual(rows.map((row) => row.eventType), ['created', 'updated', 'deleted']);
            assert.equal(rows[0].tableName, 'users');
            assert.equal(rows[0].recordId, '1');
            assert.equal(rows[1].oldValues.plan, 'free');
            assert.equal(rows[1].newValues.plan, 'pro');

            const received = [];
            globalThis.fetch = async (url, options) => {
                received.push({ url, body: JSON.parse(options.body) });
                return new Response('{}', { status: 201 });
            };

            await forwardBatch(database, testConfig(), silentLogger());
            assert.equal(received.length, 3);
        } finally {
            await database.close();
        }
    } finally {
        await stopContainer(container);
    }
});

async function startContainer(image, args) {
    const { stdout } = await execFile('docker', ['run', '-d', '--rm', ...args, image]);

    return stdout.trim();
}

async function stopContainer(container) {
    await execFile('docker', ['stop', container]).catch(() => {});
}

async function mappedPort(container, port) {
    const { stdout } = await execFile('docker', ['port', container, port]);
    const match = stdout.trim().match(/:(\d+)$/);
    if (!match) {
        throw new Error(`Could not read mapped Docker port for ${port}.`);
    }

    return match[1];
}

async function waitForDatabase(databaseUrl) {
    const deadline = Date.now() + 60000;
    let lastError = null;

    while (Date.now() < deadline) {
        try {
            return await openDatabase(databaseUrl);
        } catch (error) {
            lastError = error;
            await new Promise((resolve) => setTimeout(resolve, 1000));
        }
    }

    throw lastError;
}

function testConfig() {
    return {
        apiUrl: 'https://notifydb.test',
        projectToken: 'ndb_test',
        batchSize: 25,
    };
}

function silentLogger() {
    return { log() {}, error() {} };
}

async function postgresOutboxRows(database) {
    const result = await database.client.query('SELECT table_name, record_id, event_type, old_values, new_values FROM notifydb_outbox ORDER BY id');

    return result.rows.map((row) => ({
        tableName: row.table_name,
        recordId: row.record_id,
        eventType: row.event_type,
        oldValues: row.old_values,
        newValues: row.new_values,
    }));
}

async function mysqlOutboxRows(database) {
    const [rows] = await database.connection.query('SELECT table_name, record_id, event_type, old_values, new_values FROM notifydb_outbox ORDER BY id');

    return rows.map((row) => ({
        tableName: row.table_name,
        recordId: row.record_id,
        eventType: row.event_type,
        oldValues: normalizeJson(row.old_values),
        newValues: normalizeJson(row.new_values),
    }));
}

function normalizeJson(value) {
    return typeof value === 'string' ? JSON.parse(value) : value;
}
