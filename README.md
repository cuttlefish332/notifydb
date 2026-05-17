# NotifyDB

Symfony MVP for database-change email notifications.

## Local Setup

```bash
cp .env.local.example .env.local
bin/console doctrine:migrations:migrate
docker compose up -d
symfony server:start -d
```

Keep real Stripe and OpenAI secrets in `.env.local`. Do not commit `.env.local`.

## Initial Deployment

Run Composer with production env vars in scope. If `APP_ENV` is left as `dev`, Symfony will try to boot dev-only bundles that are not installed by `--no-dev`.

```bash
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader
APP_ENV=prod APP_DEBUG=0 bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod APP_DEBUG=0 bin/console asset-map:compile
APP_ENV=prod APP_DEBUG=0 bin/console cache:warmup --no-debug
```

Required production variables:

- `APP_SECRET`
- `APP_URL`
- `DATABASE_URL`
- `MAILER_DSN`
- `MAILER_FROM_EMAIL`
- `MAILER_FROM_NAME`
- `MESSENGER_TRANSPORT_DSN`
- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `STRIPE_PRO_PRICE_ID`
- `OPENAI_API_KEY` if AI summaries are enabled

## Workers

NotifyDB queues instant notification work and the Mailer handoff through Messenger. Keep a worker running while testing email delivery:

```bash
bin/console messenger:consume async -vv
```

For one-off tests after posting an instant event:

```bash
bin/console messenger:consume async -vv --limit=2
```

The first message builds the notification. The second message hands the email to Mailpit.

## Mailpit

Mailpit runs from Docker Compose:

- SMTP: `127.0.0.1:1025`
- UI: `http://127.0.0.1:8025`

## Stripe

Local webhook forwarding:

```bash
stripe listen --forward-to http://127.0.0.1:8000/stripe/webhook
```

Put the printed `whsec_...` value in `.env.local` as `STRIPE_WEBHOOK_SECRET`.

NotifyDB Pro is implemented as a flat product plan in the app. In Stripe, use a recurring flat-rate `$9/month` Price for `STRIPE_PRO_PRICE_ID`. A metered Price may require usage reporting and different checkout behavior.

## Quotas

Current weekly limits:

- Free: 5 emails, 100 events, 0 AI summaries
- Pro: 100 emails, 5,000 events, 500 AI summaries

Email quota counts emails handed off to Symfony Mailer. Event and AI quotas count accepted `ChangeEvent` rows since Monday 00:00.
