import pg from 'pg';
import mysql from 'mysql2/promise';
import { detectDialect } from './config.js';
import {
    mysqlOutboxSql,
    mysqlTriggerSql,
    mysqlUninstallSql,
    postgresOutboxSql,
    postgresTriggerSql,
    postgresUninstallSql,
} from './sql.js';

export async function openDatabase(databaseUrl) {
    const dialect = detectDialect(databaseUrl);
    if (dialect === 'postgres') {
        const client = new pg.Client({ connectionString: databaseUrl });
        await client.connect();

        return new PostgresDatabase(client);
    }

    const connection = await mysql.createConnection({
        uri: databaseUrl,
        multipleStatements: true,
        namedPlaceholders: true,
    });

    return new MysqlDatabase(connection);
}

class PostgresDatabase {
    dialect = 'postgres';

    constructor(client) {
        this.client = client;
    }

    async close() {
        await this.client.end();
    }

    async install(tables) {
        await this.client.query(postgresOutboxSql());
        for (const table of tables) {
            const primaryKey = await this.singlePrimaryKey(table);
            await this.client.query(postgresTriggerSql(table, primaryKey));
        }
    }

    async uninstall(tables, dropOutbox = false) {
        for (const table of tables) {
            await this.client.query(postgresUninstallSql(table));
        }

        if (dropOutbox) {
            await this.client.query('DROP TABLE IF EXISTS notifydb_outbox');
        }
    }

    async claimPending(limit) {
        const result = await this.client.query(`
UPDATE notifydb_outbox
SET locked_at = NOW()
WHERE id IN (
    SELECT id FROM notifydb_outbox
    WHERE sent_at IS NULL
      AND (locked_at IS NULL OR locked_at < NOW() - INTERVAL '5 minutes')
    ORDER BY id
    LIMIT $1
    FOR UPDATE SKIP LOCKED
)
RETURNING id, table_name, record_id, event_type, old_values, new_values;
`, [limit]);

        return result.rows.map(normalizeRow);
    }

    async markSent(id) {
        await this.client.query('UPDATE notifydb_outbox SET sent_at = NOW(), locked_at = NULL, last_error = NULL WHERE id = $1', [id]);
    }

    async markFailed(id, error) {
        await this.client.query('UPDATE notifydb_outbox SET retry_count = retry_count + 1, locked_at = NULL, last_error = $2 WHERE id = $1', [id, error.slice(0, 2000)]);
    }

    async singlePrimaryKey(table) {
        const result = await this.client.query(`
SELECT a.attname AS column_name
FROM pg_index i
JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
JOIN pg_class c ON c.oid = i.indrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE i.indisprimary = true
  AND c.relname = $1
  AND n.nspname = $2
ORDER BY a.attnum
`, [table.table, table.schema ?? 'public']);

        return assertSinglePrimaryKey(table, result.rows.map((row) => row.column_name));
    }
}

class MysqlDatabase {
    dialect = 'mysql';

    constructor(connection) {
        this.connection = connection;
    }

    async close() {
        await this.connection.end();
    }

    async install(tables) {
        await this.connection.query(mysqlOutboxSql());
        for (const table of tables) {
            const primaryKey = await this.singlePrimaryKey(table);
            const columns = await this.columns(table);
            await this.connection.query(mysqlTriggerSql(table, primaryKey, columns));
        }
    }

    async uninstall(tables, dropOutbox = false) {
        for (const table of tables) {
            await this.connection.query(mysqlUninstallSql(table));
        }

        if (dropOutbox) {
            await this.connection.query('DROP TABLE IF EXISTS notifydb_outbox');
        }
    }

    async claimPending(limit) {
        const [rows] = await this.connection.query(`
SELECT id, table_name, record_id, event_type, old_values, new_values
FROM notifydb_outbox
WHERE sent_at IS NULL
  AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
ORDER BY id
LIMIT ?
`, [limit]);

        const ids = rows.map((row) => row.id);
        if (ids.length > 0) {
            await this.connection.query('UPDATE notifydb_outbox SET locked_at = NOW() WHERE id IN (?)', [ids]);
        }

        return rows.map(normalizeRow);
    }

    async markSent(id) {
        await this.connection.query('UPDATE notifydb_outbox SET sent_at = NOW(), locked_at = NULL, last_error = NULL WHERE id = ?', [id]);
    }

    async markFailed(id, error) {
        await this.connection.query('UPDATE notifydb_outbox SET retry_count = retry_count + 1, locked_at = NULL, last_error = ? WHERE id = ?', [error.slice(0, 2000), id]);
    }

    async singlePrimaryKey(table) {
        const [rows] = await this.connection.query(`
SELECT COLUMN_NAME AS column_name
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = COALESCE(?, DATABASE())
  AND TABLE_NAME = ?
  AND CONSTRAINT_NAME = 'PRIMARY'
ORDER BY ORDINAL_POSITION
`, [table.schema, table.table]);

        return assertSinglePrimaryKey(table, rows.map((row) => row.column_name));
    }

    async columns(table) {
        const [rows] = await this.connection.query(`
SELECT COLUMN_NAME AS column_name
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = COALESCE(?, DATABASE())
  AND TABLE_NAME = ?
ORDER BY ORDINAL_POSITION
`, [table.schema, table.table]);

        if (rows.length === 0) {
            throw new Error(`Table "${table.label}" was not found.`);
        }

        return rows.map((row) => row.column_name);
    }
}

function assertSinglePrimaryKey(table, columns) {
    if (columns.length === 0) {
        throw new Error(`Table "${table.label}" must have a single-column primary key. No primary key was found.`);
    }

    if (columns.length > 1) {
        throw new Error(`Table "${table.label}" must have a single-column primary key. Composite primary keys are not supported in v1.`);
    }

    return columns[0];
}

function normalizeRow(row) {
    return {
        id: row.id,
        tableName: row.table_name,
        recordId: String(row.record_id),
        eventType: row.event_type,
        oldValues: normalizeJson(row.old_values),
        newValues: normalizeJson(row.new_values),
    };
}

function normalizeJson(value) {
    if (value === null || value === undefined) {
        return {};
    }

    if (typeof value === 'string') {
        return JSON.parse(value);
    }

    return value;
}
