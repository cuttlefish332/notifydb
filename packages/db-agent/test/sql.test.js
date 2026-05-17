import assert from 'node:assert/strict';
import test from 'node:test';
import {
    mysqlOutboxSql,
    mysqlTriggerSql,
    postgresOutboxSql,
    postgresTriggerSql,
} from '../src/sql.js';

test('postgresOutboxSql creates the expected outbox columns', () => {
    const sql = postgresOutboxSql();
    for (const column of ['table_name', 'record_id', 'event_type', 'old_values', 'new_values', 'sent_at', 'locked_at', 'retry_count', 'last_error']) {
        assert.match(sql, new RegExp(column));
    }
});

test('postgresTriggerSql writes NotifyDB payloads for insert update delete', () => {
    const sql = postgresTriggerSql({ schema: 'public', table: 'users', label: 'public.users' }, 'id');
    assert.match(sql, /AFTER INSERT OR UPDATE OR DELETE/);
    assert.match(sql, /'created'/);
    assert.match(sql, /'updated'/);
    assert.match(sql, /'deleted'/);
    assert.match(sql, /to_jsonb\(OLD\)/);
    assert.match(sql, /to_jsonb\(NEW\)/);
});

test('mysqlOutboxSql creates the expected outbox columns', () => {
    const sql = mysqlOutboxSql();
    for (const column of ['table_name', 'record_id', 'event_type', 'old_values', 'new_values', 'sent_at', 'locked_at', 'retry_count', 'last_error']) {
        assert.match(sql, new RegExp(column));
    }
});

test('mysqlTriggerSql creates operation-specific triggers with row JSON', () => {
    const sql = mysqlTriggerSql({ schema: null, table: 'orders', label: 'orders' }, 'id', ['id', 'status']);
    assert.match(sql, /AFTER INSERT/);
    assert.match(sql, /AFTER UPDATE/);
    assert.match(sql, /AFTER DELETE/);
    assert.match(sql, /JSON_OBJECT\('id', NEW.`id`, 'status', NEW.`status`\)/);
    assert.match(sql, /JSON_OBJECT\('id', OLD.`id`, 'status', OLD.`status`\)/);
});
