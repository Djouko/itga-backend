# ITGA Backend/Admin Deployment

This repository contains the Laravel API and the web admin panel.

## Required production secrets

Copy `.env.example` to `.env` on the server and set real values:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-backend-domain.example
API_SECRET_KEY=replace-with-long-random-public-client-key
ADMIN_API_SECRET_KEY=replace-with-long-random-admin-key
READINESS_TOKEN=replace-with-private-readiness-token
QUEUE_CONNECTION=database
CACHE_DRIVER=file
SESSION_DRIVER=file
```

Use Redis for `CACHE_DRIVER`, `QUEUE_CONNECTION` and `SESSION_DRIVER` before multi-node scaling.

## Hostinger deployment

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
php artisan ops:public-readiness --json
```

Do not open public traffic if readiness reports blockers.

## Local validation

```bash
php artisan ops:public-readiness --json
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```
