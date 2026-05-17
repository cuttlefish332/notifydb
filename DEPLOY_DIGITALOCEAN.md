# Deploy NotifyDB on a DigitalOcean Droplet

This guide assumes one Ubuntu 24.04 droplet running Nginx, PHP-FPM, Composer, SQLite for the first launch, and a systemd worker for queued emails. For higher traffic, switch `DATABASE_URL` and `MESSENGER_TRANSPORT_DSN` to PostgreSQL or Redis-backed infrastructure.

## 1. Server packages

```bash
sudo apt update
sudo apt install -y nginx git unzip sqlite3 acl cron
sudo apt install -y php8.4-fpm php8.4-cli php8.4-mbstring php8.4-intl php8.4-xml php8.4-sqlite3 php8.4-curl php8.4-zip
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php
```

If your Ubuntu package source does not provide PHP 8.4 yet, install PHP from a trusted PHP package repository first, or deploy on a runtime image that already includes PHP 8.4.

## 2. Clone and install

```bash
sudo mkdir -p /var/www/notifydb
sudo chown "$USER":"$USER" /var/www/notifydb
git clone https://github.com/YOUR_ORG/notifydb.git /var/www/notifydb
cd /var/www/notifydb
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader
```

## 3. Production environment

Create `/var/www/notifydb/.env.local`:

```bash
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=replace_with_a_long_random_secret
APP_URL=https://YOUR_DOMAIN.com
DATABASE_URL="sqlite:///%kernel.project_dir%/var/notifydb_prod.sqlite"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

MAILER_DSN=smtp://YOUR_SMTP_USER:YOUR_SMTP_PASSWORD@YOUR_SMTP_HOST:587
MAILER_FROM_EMAIL=alerts@YOUR_DOMAIN.com
MAILER_FROM_NAME=NotifyDB

STRIPE_SECRET_KEY=replace_with_stripe_live_secret_key
STRIPE_PUBLISHABLE_KEY=replace_with_stripe_live_publishable_key
STRIPE_WEBHOOK_SECRET=whsec_replace_me
STRIPE_PRO_PRICE_ID=price_replace_me

OPENAI_API_KEY=replace_with_openai_api_key
OPENAI_MODEL=gpt-5.4-nano
```

Generate `APP_SECRET` with:

```bash
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
```

For Gmail SMTP during a tiny beta, use a Google app password:

```bash
MAILER_DSN=smtp://yourname%40gmail.com:GOOGLE_APP_PASSWORD@smtp.gmail.com:587
MAILER_FROM_EMAIL=yourname@gmail.com
```

## 4. Database, assets, and cache

```bash
cd /var/www/notifydb
APP_ENV=prod APP_DEBUG=0 bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod APP_DEBUG=0 bin/console asset-map:compile
APP_ENV=prod APP_DEBUG=0 bin/console cache:warmup --no-debug
sudo chown -R www-data:www-data var public/assets
```

## 5. Nginx

Create `/etc/nginx/sites-available/notifydb`:

```nginx
server {
    listen 80;
    server_name YOUR_DOMAIN.com;
    root /var/www/notifydb/public;

    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

Enable it:

```bash
sudo ln -s /etc/nginx/sites-available/notifydb /etc/nginx/sites-enabled/notifydb
sudo nginx -t
sudo systemctl reload nginx
```

Add HTTPS with Certbot:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d YOUR_DOMAIN.com
```

## 6. Queue worker

Create `/etc/systemd/system/notifydb-worker.service`:

```ini
[Unit]
Description=NotifyDB Messenger Worker
After=network.target

[Service]
WorkingDirectory=/var/www/notifydb
ExecStart=/usr/bin/php /var/www/notifydb/bin/console messenger:consume async --env=prod --time-limit=3600 --memory-limit=128M
Restart=always
RestartSec=5
User=www-data
Environment=APP_ENV=prod
Environment=APP_DEBUG=0

[Install]
WantedBy=multi-user.target
```

Enable it:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now notifydb-worker
sudo systemctl status notifydb-worker
```

## 7. Digest schedules

Edit the web user crontab:

```bash
sudo crontab -u www-data -e
```

Add:

```cron
0 8 * * * cd /var/www/notifydb && APP_ENV=prod APP_DEBUG=0 /usr/bin/php bin/console app:send-daily-digests
0 8 * * 1 cd /var/www/notifydb && APP_ENV=prod APP_DEBUG=0 /usr/bin/php bin/console app:send-weekly-digests
```

## 8. Stripe webhook

In Stripe Dashboard, create a webhook endpoint:

```text
https://YOUR_DOMAIN.com/stripe/webhook
```

Subscribe to:

- `checkout.session.completed`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`

Copy the endpoint signing secret into `STRIPE_WEBHOOK_SECRET`, then clear cache:

```bash
cd /var/www/notifydb
APP_ENV=prod APP_DEBUG=0 bin/console cache:clear --no-debug
```

## 9. Deployment updates

For each deploy:

```bash
cd /var/www/notifydb
git pull --ff-only
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader
APP_ENV=prod APP_DEBUG=0 bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod APP_DEBUG=0 bin/console asset-map:compile
APP_ENV=prod APP_DEBUG=0 bin/console cache:clear --no-debug
sudo chown -R www-data:www-data var public/assets
sudo systemctl restart php8.4-fpm notifydb-worker
```

## 10. Smoke test

After deploy:

1. Visit `/docs`.
2. Register or log in.
3. Create a project and save the one-time token.
4. Send the cURL test event.
5. Confirm the event appears on the dashboard.
6. Confirm the worker sends email.
7. Complete a Stripe checkout and verify the webhook delivery returns `200 ok`.
