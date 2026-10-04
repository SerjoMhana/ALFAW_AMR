# Free online demo deployment

This repository is prepared as one Docker web service: Vite builds the Vue
frontend, its static output is copied into Laravel's public directory, and the
API remains under `/api`. The single-origin layout keeps Sanctum session login
simple and secure.

## Services

- Render Free web service for the application.
- TiDB Cloud Starter for a persistent MySQL-compatible database.

## TiDB Cloud

1. Create a Starter instance and keep its spending limit at `0`.
2. Create an empty database for the application.
3. Copy its public endpoint, port, database, username and password.
4. Import the local demo database once. Do not commit the SQL dump.

## Render

1. Create a Blueprint from this repository's `render.yaml`.
2. Fill the secret environment values when prompted:
   `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and
   `SEED_ADMIN_PASSWORD`.
3. Keep the service on the Free plan and deploy.

The container runs `php artisan migrate --force` on every start. Migrations are
idempotent, so imported data is retained in TiDB.

## Free-plan limitation

Render's free filesystem is temporary. The report-card logos currently bundled
under `backend/storage/app/public/report-card` are present after each deploy,
but a logo uploaded through the admin after deployment can disappear after a
restart. Use an S3-compatible disk such as Cloudflare R2 before treating the
site as production.
