# Smart Orange — Lead Import

Test assignment: a page for importing leads from XLSX into a database. Files are processed in the background through Laravel queues, while PHP's `max_execution_time` remains `30`.

- Assignment: [Завдання.docx](Завдання.docx).
- Import file: [База даних.xlsx](База%20даних.xlsx), containing 100,000 leads.
- The database schema is defined by the migrations in `database/migrations`.

## Technologies

Laravel 13, PHP 8.5, MySQL 8, Nginx, and Supervisor. The interface uses Blade, CSS, and vanilla JavaScript with Fetch; Vite builds the assets. OpenSpout reads XLSX files, and Brick Money converts amounts to kopiykas.

## Prerequisites

Your machine needs:

- Docker with Docker Engine running and Docker Compose available.
- Node.js and npm. The locked Vite version requires Node.js `^20.19.0` or `>=22.12.0`.
- Available ports `20000` for the website and `30010` for phpMyAdmin.

PHP and Composer are available inside the container. Run all commands below from the project root.

## Initial Setup

### 1. Configure the Environment

For a new installation, create `.env`:

```bash
cp .env.example .env
```

If `.env` already exists, do not overwrite it. Check the following values and retain the current `APP_KEY`.

```dotenv
APP_NAME="Smart Orange"
APP_URL=http://localhost:20000

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=smart_orange
DB_USERNAME=admin
DB_PASSWORD=admin

FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
```

`mysql` is the service name within the Docker network. Run Artisan commands that access this database inside the PHP container.

### 2. Build the Containers and Install Dependencies

```bash
docker compose build php worker
docker compose up -d mysql php
docker compose exec php composer install
```

The worker starts later, after dependencies are installed and queue tables have been created.

### 3. Generate the Application Key and Run Migrations

For a new `.env` with an empty `APP_KEY`:

```bash
docker compose exec php php artisan key:generate
```

Clear the configuration cache and create the tables:

```bash
docker compose exec php php artisan config:clear
docker compose exec php php artisan migrate
```

PHP-FPM needs write access to `storage` and `bootstrap/cache`. For the container's `www-data` user:

```bash
docker compose exec php chown -R www-data:www-data storage bootstrap/cache
```

### 4. Build the Frontend and Start the Services

```bash
npm ci
npm run build
docker compose up -d
```

Available addresses:

| Service | Address |
| --- | --- |
| Import page | http://localhost:20000 |
| phpMyAdmin | http://localhost:30010 |

Log in to phpMyAdmin using server `mysql`, username `admin`, password `admin`, and database `smart_orange`. These credentials belong to the local Docker environment.

During development, you can run Vite in a separate terminal instead of building the assets:

```bash
npm run dev
```

## Running an Import

1. Open http://localhost:20000.
2. Select `База даних.xlsx`.
3. Click “Імпортувати” (Import).
4. After the message “Файл прийнято та передано на обробку” (File accepted for processing), wait for the job to finish in the worker logs:

```bash
docker compose logs -f worker
```

The page confirms that the file was accepted, not that the import has finished. The job appears as `RUNNING` in the logs, followed by `DONE` or `FAIL`. Pressing `Ctrl+C` stops following the logs without stopping the worker container.

Supervisor automatically starts the queue process and restarts it when it exits. There is no need to run `queue:work` separately.

Check the process status:

```bash
docker compose exec worker supervisorctl status
```

## Verifying the Result

Select the `smart_orange` database in phpMyAdmin and run:

```sql
SELECT COUNT(*) AS total FROM leads;
```

After one successful import of the supplied file into an empty table, expect **100,000 records**. If records already existed, compare the counts before and after the import.

Inspect stored values:

```sql
SELECT id, external_id, created_at, first_name, last_name,
       phone, budget_uah, next_contact_at
FROM leads
ORDER BY id
LIMIT 10;
```

`budget_uah` stores an integer number of kopiykas: for example, `12345` represents `123.45 UAH`. `created_at` contains the lead's date from the file.

All batches are written within one transaction. New records may not be visible to another connection until it commits. Uploading the file again adds new records; `external_id` uniqueness is not checked.

If the job reports `FAIL`, inspect the application log and failed jobs:

```bash
docker compose exec php tail -n 100 storage/logs/laravel.log
docker compose exec php php artisan queue:failed
```

After fixing the cause, upload the file again. The job's `finally` block deletes the file after processing completes or throws an exception. This block may not execute if the process is forcibly terminated.

## How the Import Works

- `POST /imports` validates the upload through `ImportLeadsRequest`.
- `ImportLeadsController::import()` passes the file to the service and returns JSON: `202` when accepted, `422` for validation errors, or `500` if accepting the file fails.
- `LeadImportService::import()` stores the file and dispatches one `ImportLeads` job to the database queue.
- The job calls `LeadImportService::process()`, which streams the first sheet and inserts leads in batches of 1,000 rows. The final partial batch is also inserted.
- `LeadRowMapper` converts dates, cached formula results, and amounts. Batch INSERT bypasses model setters, so monetary conversion happens in the mapper.
- A read or write error rolls back the transaction. The exception propagates to the worker, and the job's `finally` block deletes the file.

The file must contain headers in this order:

```text
external_id, created_at, first_name, last_name, phone,
email, city, source, utm_campaign, product,
budget_uah, status, manager, comment, next_contact_at
```

The first non-empty row is treated as the header. Empty rows are skipped. Dates must be Excel dates that OpenSpout recognizes as date objects. Formulas use results cached in the file; they are not calculated during import.

## Limits and Queue Settings

| Setting | Value |
| --- | --- |
| Laravel and PHP file size limit | 20 MiB |
| Nginx request body limit and `post_max_size` | 21 MiB, including form data |
| PHP `max_execution_time` | 30 seconds |
| Job timeout | 600 seconds |
| Database queue `retry_after` | 660 seconds |
| Automatic job attempts | 1 |

The import runs in the background; the web request does not wait for all rows to be processed. The job timeout and `max_execution_time` are separate limits: setting the former to `600` does not change the PHP configuration. `retry_after` determines when an unfinished job becomes available in the queue again and exceeds the worker timeout.

## Automated Tests

After installing dependencies and building the frontend:

```bash
docker compose exec php php artisan test
```

To run only the import tests:

```bash
docker compose exec php php artisan test tests/Feature/ImportUploadTest.php tests/Feature/LeadImportTest.php tests/Unit/LeadRowMapperTest.php
```

The test environment is configured in `phpunit.xml`: in-memory SQLite and test drivers for sessions, cache, and queues. The PHP extension `pdo_sqlite` is required. If the application configuration has been cached, run `php artisan config:clear` inside the PHP container before testing.

Feature tests cover uploads, job dispatch, database writes, headers, the final batch, transaction rollback, and file cleanup. Unit tests cover dates, money, and cached phone formula results. Test XLSX files are generated temporarily; the largest contains 1,001 leads. `external_id` is not tested. The full 100,000-row file is verified manually.

## Updating and Stopping the Application

After changing PHP code, restart the queue process so it loads the new code:

```bash
docker compose exec php php artisan queue:restart
```

Supervisor starts the worker again after the current job finishes.

After changing the Dockerfile, `php.ini`, or Supervisor configuration:

```bash
docker compose build php worker
docker compose up -d php worker
docker compose restart nginx
```

After changing only the Nginx configuration:

```bash
docker compose restart nginx
```

Stop the project while preserving the MySQL volume:

```bash
docker compose down
```
