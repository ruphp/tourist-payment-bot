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
```

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
