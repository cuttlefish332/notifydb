import { loadConfig, writeExampleConfig } from './config.js';
import { openDatabase } from './db.js';
import { runAgent } from './agent.js';

export async function main(argv = process.argv) {
    const command = argv[2] ?? 'help';
    const envFile = readOption(argv, '--env-file');

    if (command === 'help' || command === '--help' || command === '-h') {
        printHelp();
        return;
    }

    if (command === 'init') {
        const target = argv[3] ?? '.env.notifydb';
        const written = writeExampleConfig(target);
        console.log(`Created ${written}`);
        return;
    }

    if (command === 'install') {
        const config = loadConfig(process.env, { envFile });
        const database = await openDatabase(config.databaseUrl);
        try {
            await database.install(config.tables);
        } finally {
            await database.close();
        }

        console.log(`Installed NotifyDB outbox and triggers for ${config.tables.map((table) => table.label).join(', ')}`);
        return;
    }

    if (command === 'uninstall') {
        const config = loadConfig(process.env, { envFile });
        const dropOutbox = argv.includes('--drop-outbox');
        const database = await openDatabase(config.databaseUrl);
        try {
            await database.uninstall(config.tables, dropOutbox);
        } finally {
            await database.close();
        }

        console.log(`Removed NotifyDB triggers for ${config.tables.map((table) => table.label).join(', ')}`);
        return;
    }

    if (command === 'run') {
        const controller = new AbortController();
        process.on('SIGINT', () => controller.abort());
        process.on('SIGTERM', () => controller.abort());
        await runAgent(loadConfig(process.env, { envFile }), console, controller.signal);
        return;
    }

    throw new Error(`Unknown command "${command}". Run notifydb-db-agent help.`);
}

function readOption(argv, name) {
    const index = argv.indexOf(name);
    if (index === -1) {
        return null;
    }

    const value = argv[index + 1];
    if (!value || value.startsWith('--')) {
        throw new Error(`${name} requires a value.`);
    }

    return value;
}

function printHelp() {
    console.log(`NotifyDB database agent

Usage:
  notifydb-db-agent init [file]
  notifydb-db-agent install [--env-file .env.notifydb]
  notifydb-db-agent run [--env-file .env.notifydb]
  notifydb-db-agent uninstall [--env-file .env.notifydb] [--drop-outbox]

Required environment:
  NOTIFYDB_API_URL
  NOTIFYDB_PROJECT_TOKEN
  DATABASE_URL
  NOTIFYDB_TABLES

Optional environment:
  POLL_INTERVAL_MS
  BATCH_SIZE`);
}
