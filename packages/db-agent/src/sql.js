const OUTBOX_TABLE = 'notifydb_outbox';

export function postgresOutboxSql() {
    return `
CREATE TABLE IF NOT EXISTS ${OUTBOX_TABLE} (
    id BIGSERIAL PRIMARY KEY,
    table_name TEXT NOT NULL,
    record_id TEXT NOT NULL,
    event_type TEXT NOT NULL CHECK (event_type IN ('created', 'updated', 'deleted')),
    old_values JSONB NOT NULL DEFAULT '{}'::jsonb,
    new_values JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    sent_at TIMESTAMPTZ NULL,
    locked_at TIMESTAMPTZ NULL,
    retry_count INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_notifydb_outbox_pending
    ON ${OUTBOX_TABLE} (sent_at, locked_at, id);
`.trim();
}

export function mysqlOutboxSql() {
    return `
CREATE TABLE IF NOT EXISTS ${OUTBOX_TABLE} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(255) NOT NULL,
    record_id VARCHAR(255) NOT NULL,
    event_type ENUM('created', 'updated', 'deleted') NOT NULL,
    old_values JSON NOT NULL,
    new_values JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    locked_at TIMESTAMP NULL,
    retry_count INT NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    INDEX idx_notifydb_outbox_pending (sent_at, locked_at, id)
);
`.trim();
}

export function postgresTriggerSql(table, primaryKey) {
    const tableRef = postgresTableRef(table);
    const functionName = pgIdentifier(`notifydb_capture_${table.schema ?? 'public'}_${table.table}`);
    const triggerName = pgIdentifier('notifydb_capture');
    const label = table.label.replaceAll("'", "''");
    const pk = pgIdentifier(primaryKey);

    return `
CREATE OR REPLACE FUNCTION ${functionName}()
RETURNS TRIGGER AS $$
DECLARE
    notifydb_record_id TEXT;
BEGIN
    notifydb_record_id := COALESCE(NEW.${pk}, OLD.${pk})::TEXT;

    INSERT INTO ${OUTBOX_TABLE} (table_name, record_id, event_type, old_values, new_values)
    VALUES (
        '${label}',
        notifydb_record_id,
        CASE TG_OP
            WHEN 'INSERT' THEN 'created'
            WHEN 'UPDATE' THEN 'updated'
            WHEN 'DELETE' THEN 'deleted'
        END,
        CASE WHEN TG_OP = 'INSERT' THEN '{}'::jsonb ELSE to_jsonb(OLD) END,
        CASE WHEN TG_OP = 'DELETE' THEN '{}'::jsonb ELSE to_jsonb(NEW) END
    );

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS ${triggerName} ON ${tableRef};
CREATE TRIGGER ${triggerName}
AFTER INSERT OR UPDATE OR DELETE ON ${tableRef}
FOR EACH ROW EXECUTE FUNCTION ${functionName}();
`.trim();
}

export function mysqlTriggerSql(table, primaryKey, columns = []) {
    const tableRef = mysqlTableRef(table);
    const base = `notifydb_capture_${table.table}`;
    const label = table.label.replaceAll("'", "''");
    const pk = mysqlIdentifier(primaryKey);
    const oldJson = mysqlJsonObject('OLD', columns);
    const newJson = mysqlJsonObject('NEW', columns);

    return `
DROP TRIGGER IF EXISTS ${mysqlIdentifier(`${base}_ai`)};
CREATE TRIGGER ${mysqlIdentifier(`${base}_ai`)}
AFTER INSERT ON ${tableRef}
FOR EACH ROW
BEGIN
    INSERT INTO ${OUTBOX_TABLE} (table_name, record_id, event_type, old_values, new_values)
    VALUES ('${label}', CAST(NEW.${pk} AS CHAR), 'created', JSON_OBJECT(), ${newJson});
END;

DROP TRIGGER IF EXISTS ${mysqlIdentifier(`${base}_au`)};
CREATE TRIGGER ${mysqlIdentifier(`${base}_au`)}
AFTER UPDATE ON ${tableRef}
FOR EACH ROW
BEGIN
    INSERT INTO ${OUTBOX_TABLE} (table_name, record_id, event_type, old_values, new_values)
    VALUES ('${label}', CAST(NEW.${pk} AS CHAR), 'updated', ${oldJson}, ${newJson});
END;

DROP TRIGGER IF EXISTS ${mysqlIdentifier(`${base}_ad`)};
CREATE TRIGGER ${mysqlIdentifier(`${base}_ad`)}
AFTER DELETE ON ${tableRef}
FOR EACH ROW
BEGIN
    INSERT INTO ${OUTBOX_TABLE} (table_name, record_id, event_type, old_values, new_values)
    VALUES ('${label}', CAST(OLD.${pk} AS CHAR), 'deleted', ${oldJson}, JSON_OBJECT());
END;
`.trim();
}

export function postgresUninstallSql(table) {
    const tableRef = postgresTableRef(table);
    const functionName = pgIdentifier(`notifydb_capture_${table.schema ?? 'public'}_${table.table}`);

    return `
DROP TRIGGER IF EXISTS ${pgIdentifier('notifydb_capture')} ON ${tableRef};
DROP FUNCTION IF EXISTS ${functionName}();
`.trim();
}

export function mysqlUninstallSql(table) {
    const base = `notifydb_capture_${table.table}`;

    return [
        `DROP TRIGGER IF EXISTS ${mysqlIdentifier(`${base}_ai`)};`,
        `DROP TRIGGER IF EXISTS ${mysqlIdentifier(`${base}_au`)};`,
        `DROP TRIGGER IF EXISTS ${mysqlIdentifier(`${base}_ad`)};`,
    ].join('\n');
}

export function postgresTableRef(table) {
    return table.schema ? `${pgIdentifier(table.schema)}.${pgIdentifier(table.table)}` : pgIdentifier(table.table);
}

export function mysqlTableRef(table) {
    return table.schema ? `${mysqlIdentifier(table.schema)}.${mysqlIdentifier(table.table)}` : mysqlIdentifier(table.table);
}

export function pgIdentifier(value) {
    assertSafeIdentifier(value);

    return `"${value.replaceAll('"', '""')}"`;
}

export function mysqlIdentifier(value) {
    assertSafeIdentifier(value);

    return `\`${value.replaceAll('`', '``')}\``;
}

function assertSafeIdentifier(value) {
    if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(value)) {
        throw new Error(`Unsafe SQL identifier "${value}".`);
    }
}

function mysqlJsonObject(rowAlias, columns) {
    if (columns.length === 0) {
        return 'JSON_OBJECT()';
    }

    return `JSON_OBJECT(${columns.map((column) => {
        assertSafeIdentifier(column);

        return `'${column}', ${rowAlias}.${mysqlIdentifier(column)}`;
    }).join(', ')})`;
}
