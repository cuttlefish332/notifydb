import fs from 'node:fs';
import path from 'node:path';
import { config as loadDotenv } from 'dotenv';

export function loadConfig(env = process.env, options = {}) {
    loadDotenv({ path: resolveEnvFile(options.envFile) });

    const databaseUrl = required(env, 'DATABASE_URL');
    const apiUrl = trimTrailingSlash(required(env, 'NOTIFYDB_API_URL'));
    const projectToken = required(env, 'NOTIFYDB_PROJECT_TOKEN');
    const tables = parseTables(required(env, 'NOTIFYDB_TABLES'));

    return {
        databaseUrl,
        apiUrl,
        projectToken,
        tables,
        pollIntervalMs: positiveInt(env.POLL_INTERVAL_MS, 5000, 'POLL_INTERVAL_MS'),
        batchSize: positiveInt(env.BATCH_SIZE, 25, 'BATCH_SIZE'),
    };
}

export function resolveEnvFile(envFile = null) {
    if (envFile) {
        return envFile;
    }

    if (fs.existsSync('.env.notifydb')) {
        return '.env.notifydb';
    }

    return '.env';
}

export function writeExampleConfig(filePath = '.env.notifydb') {
    const target = path.resolve(filePath);
    if (fs.existsSync(target)) {
        throw new Error(`${filePath} already exists.`);
    }

    fs.writeFileSync(target, [
        'NOTIFYDB_API_URL=https://notifydb.io',
        'NOTIFYDB_PROJECT_TOKEN=YOUR_PROJECT_TOKEN',
        'DATABASE_URL=postgresql://app:password@localhost:5432/app',
        'NOTIFYDB_TABLES=public.users,public.orders',
        'POLL_INTERVAL_MS=5000',
        'BATCH_SIZE=25',
        '',
    ].join('\n'));

    return target;
}

export function parseTables(value) {
    const tables = value
        .split(',')
        .map((table) => table.trim())
        .filter(Boolean);

    if (tables.length === 0) {
        throw new Error('NOTIFYDB_TABLES must contain at least one table.');
    }

    return tables.map(parseTableName);
}

export function parseTableName(value) {
    const parts = value.split('.').map((part) => part.trim()).filter(Boolean);
    if (parts.length === 1) {
        return { schema: null, table: assertIdentifier(parts[0], value), label: parts[0] };
    }

    if (parts.length === 2) {
        const schema = assertIdentifier(parts[0], value);
        const table = assertIdentifier(parts[1], value);

        return { schema, table, label: `${schema}.${table}` };
    }

    throw new Error(`Invalid table name "${value}". Use "table" or "schema.table".`);
}

export function detectDialect(databaseUrl) {
    const protocol = new URL(databaseUrl).protocol.replace(':', '');
    if (['postgres', 'postgresql'].includes(protocol)) {
        return 'postgres';
    }

    if (['mysql', 'mysql2'].includes(protocol)) {
        return 'mysql';
    }

    throw new Error(`Unsupported DATABASE_URL protocol "${protocol}". Use postgresql:// or mysql://.`);
}

function required(env, key) {
    const value = env[key]?.trim();
    if (!value) {
        throw new Error(`${key} is required.`);
    }

    return value;
}

function positiveInt(value, fallback, name) {
    if (value === undefined || value === '') {
        return fallback;
    }

    const parsed = Number.parseInt(value, 10);
    if (!Number.isInteger(parsed) || parsed <= 0) {
        throw new Error(`${name} must be a positive integer.`);
    }

    return parsed;
}

function assertIdentifier(value, original) {
    if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(value)) {
        throw new Error(`Invalid identifier in table name "${original}".`);
    }

    return value;
}

function trimTrailingSlash(value) {
    return value.replace(/\/+$/, '');
}
