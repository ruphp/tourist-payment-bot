# Tourist Payment Bot

Telegram bot for tourist payments through U-ON CRM and Tochka acquiring.

## Local start

```bash
cp .env.example .env
docker compose up -d
docker run --rm -v D:/Projects/tourist-payment-bot:/app -w /app composer:2 composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Open:

```text
http://localhost:8080
```

## Services

- `app` - PHP 8.3 FPM with Laravel-friendly extensions.
- `nginx` - public HTTP entrypoint.
- `db` - MariaDB.

## Environment

Fill these values in `.env`:

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=

UON_API_KEY=
UON_BASE_URL=https://api.u-on.ru

TOCHKA_CLIENT_ID=
TOCHKA_CLIENT_SECRET=
TOCHKA_WEBHOOK_SECRET=

PAYMENT_TIMEZONE=Europe/Moscow
PAYMENT_ACCEPT_FROM=07:00
PAYMENT_ACCEPT_UNTIL=17:00
```

## MVP bot behavior

One Telegram user has one active U-ON request binding.

Flow:

- tourist logs in with contract/request A and phone;
- bot remembers contract/request A;
- `/status` shows contract/request A;
- to switch to contract/request B, tourist sends `/logout`;
- after `/logout`, tourist enters contract/request B and phone again.

Multiple active contracts per Telegram user are intentionally out of MVP scope.

## Payment acceptance window

Payments are accepted only from `07:00` to `17:00` Moscow time.

Reason: after `17:00`, the agency may not have enough time to pay suppliers on the same operator exchange rate. If the rate changes later, previously accepted funds may no longer cover the tour balance.

When Tochka acquiring is connected, payment link creation must be blocked outside this window.

## Production notes

For a small VPS, keep the runtime light:

- use `docker compose`, Nginx, PHP-FPM, MariaDB;
- do not run npm builds on the server unless needed;
- use `composer install --no-dev --optimize-autoloader`;
- add 1-2 GB swap on a 1 GB RAM VPS;
- run Laravel cache commands after deploy:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
