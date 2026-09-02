# Control-plane deployment

Runs on **its own VPS**, separate from every VPN node. It is a normal Laravel
web app on 443 (users, plans, subscriptions, payments, provisioning, admin).

## Server

- Ubuntu 24.04, 2 vCPU, 4 GB RAM. Any reputable provider (EU / US is fine —
  this host is not the thing that needs to punch through the GFW).
- Domain: `cp.mbunievpn.com` → A record → VPS IP.

## Stack

```bash
apt-get install -y php8.3-{cli,fpm,mbstring,xml,curl,mysql,redis,bcmath,gd,zip,intl} \
                   mysql-server redis-server nginx    # or caddy
# composer
curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
```

MySQL:

```sql
CREATE DATABASE mvpn_control CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mvpn'@'localhost' IDENTIFIED BY '...';
GRANT ALL ON mvpn_control.* TO 'mvpn'@'localhost';
```

## App

```bash
git clone <repo> /var/www/mvpn && cd /var/www/mvpn/control-plane
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# edit .env — see below
php artisan migrate --force
php artisan db:seed --class=MvpnSeeder --force   # plans + admin (NOT the demo node)
php artisan config:cache route:cache view:cache
php artisan storage:link
```

### `.env` (production)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cp.mbunievpn.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=mvpn_control
DB_USERNAME=mvpn
DB_PASSWORD=...

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
CRYPTOMUS_MERCHANT_ID=...
CRYPTOMUS_PAYMENT_KEY=...

ANTHROPIC_API_KEY=sk-ant-...
MVPN_ALERT_TELEGRAM_BOT_TOKEN=...
MVPN_ALERT_TELEGRAM_CHAT_ID=...
```

`dev/pay` route auto-disables when `APP_ENV != local`.

## Workers (systemd)

```ini
# /etc/systemd/system/mvpn-queue.service
[Service]
ExecStart=/usr/bin/php /var/www/mvpn/control-plane/artisan queue:work --sleep=1 --tries=5 --max-time=3600
Restart=always
User=www-data

# /etc/systemd/system/mvpn-scheduler.service  (or a * * * * * cron running schedule:run)
[Service]
ExecStart=/usr/bin/php /var/www/mvpn/control-plane/artisan schedule:work
Restart=always
User=www-data
```

`pcntl` is available on Linux, so **Horizon** can be added here
(`composer require laravel/horizon`) — it was skipped only for Windows dev.

## Webhooks to register with providers

- Stripe: `https://cp.mbunievpn.com/webhooks/stripe`
  events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
  `checkout.session.async_payment_failed`, `charge.refunded`
- Cryptomus: `https://cp.mbunievpn.com/webhooks/cryptomus`

## Post-deploy

1. Log in at `/admin`, change the admin password.
2. Set real plan prices (USD + CNY) in **Plans**.
3. Register node(s) — see `node/DEPLOY.md` §3.
4. Tighten `config/cors.php` `allowed_origins` to the app's real origins.
