import { setTimeout as sleep } from 'node:timers/promises';
import { loadConfig } from './config.js';
import { openDatabase } from './db.js';

export async function forwardBatch(database, config, logger = console) {
    const rows = await database.claimPending(config.batchSize);
    let sent = 0;
    let failed = 0;

    for (const row of rows) {
        try {
            await postEvent(config, row);
            await database.markSent(row.id);
            sent++;
        } catch (error) {
            failed++;
            const message = error instanceof Error ? error.message : String(error);
            await database.markFailed(row.id, message);
            logger.error(`Failed to forward outbox row ${row.id}: ${message}`);
        }
    }

    return { claimed: rows.length, sent, failed };
}

export async function runAgent(config = loadConfig(), logger = console, signal = undefined) {
    const database = await openDatabase(config.databaseUrl);
    logger.log(`NotifyDB agent started for ${config.tables.map((table) => table.label).join(', ')}`);

    try {
        while (!signal?.aborted) {
            const result = await forwardBatch(database, config, logger);
            if (result.claimed > 0) {
                logger.log(`Forwarded ${result.sent} event(s), ${result.failed} failed.`);
            }

            await sleep(config.pollIntervalMs, undefined, { signal }).catch((error) => {
                if (error?.name !== 'AbortError') {
                    throw error;
                }
            });
        }
    } finally {
        await database.close();
    }
}

async function postEvent(config, row) {
    const response = await fetch(`${config.apiUrl}/api/events`, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${config.projectToken}`,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            tableName: row.tableName,
            recordId: row.recordId,
            eventType: row.eventType,
            oldValues: row.oldValues,
            newValues: row.newValues,
        }),
    });

    if (!response.ok) {
        const body = await response.text();
        throw new Error(`NotifyDB returned ${response.status}: ${body.slice(0, 500)}`);
    }
}
