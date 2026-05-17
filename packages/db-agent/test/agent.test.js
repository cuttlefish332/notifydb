import assert from 'node:assert/strict';
import test from 'node:test';
import { forwardBatch } from '../src/agent.js';

test('forwardBatch marks successful rows as sent', async () => {
    const calls = [];
    globalThis.fetch = async (url, options) => {
        calls.push({ url, options });

        return new Response('{}', { status: 201 });
    };

    const database = fakeDatabase([fakeRow(1)]);
    const result = await forwardBatch(database, testConfig(), silentLogger());

    assert.deepEqual(result, { claimed: 1, sent: 1, failed: 0 });
    assert.deepEqual(database.sent, [1]);
    assert.deepEqual(database.failed, []);
    assert.equal(calls[0].url, 'https://notifydb.test/api/events');
    assert.equal(JSON.parse(calls[0].options.body).eventType, 'updated');
});

test('forwardBatch records failed NotifyDB responses', async () => {
    globalThis.fetch = async () => new Response('quota', { status: 429 });

    const database = fakeDatabase([fakeRow(7)]);
    const result = await forwardBatch(database, testConfig(), silentLogger());

    assert.deepEqual(result, { claimed: 1, sent: 0, failed: 1 });
    assert.deepEqual(database.sent, []);
    assert.equal(database.failed[0].id, 7);
    assert.match(database.failed[0].error, /429/);
});

function testConfig() {
    return {
        apiUrl: 'https://notifydb.test',
        projectToken: 'ndb_test',
        batchSize: 25,
    };
}

function fakeRow(id) {
    return {
        id,
        tableName: 'users',
        recordId: '42',
        eventType: 'updated',
        oldValues: { email: 'old@example.com' },
        newValues: { email: 'new@example.com' },
    };
}

function fakeDatabase(rows) {
    return {
        sent: [],
        failed: [],
        async claimPending() {
            return rows;
        },
        async markSent(id) {
            this.sent.push(id);
        },
        async markFailed(id, error) {
            this.failed.push({ id, error });
        },
    };
}

function silentLogger() {
    return { log() {}, error() {} };
}
