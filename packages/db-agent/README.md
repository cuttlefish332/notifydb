# NotifyDB Database Agent

The NotifyDB database agent watches a local database outbox table and forwards row changes to `POST /api/events`.

## Quickstart

```bash
npm install
npx notifydb-db-agent init
```

Edit `.env.notifydb`. The CLI loads this file by default when it exists.

Install outbox triggers:

```bash
npx notifydb-db-agent install
```

Run the forwarder:

```bash
npx notifydb-db-agent run
```

## Docker

```bash
docker build -t notifydb-db-agent .
docker run --env-file .env.notifydb notifydb-db-agent run
```

## Requirements

- PostgreSQL or MySQL.
- Watched tables must have a single-column primary key.
- The agent must be able to connect to the database and to NotifyDB.
