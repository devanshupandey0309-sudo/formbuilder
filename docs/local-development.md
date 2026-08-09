# Local Development Guide

Exact setup steps based on repository configuration.

## Prerequisites

| Tool | Version (verified in repo) |
|------|---------------------------|
| PHP | ^8.3 |
| Composer | 2.x |
| Node.js | 18+ (for Vite 8) |
| npm | 9+ |
| Database | MySQL (recommended) or SQLite |
| Python 3.12 | Optional — only for FastAPI sidecar |

**Package versions (from lock files):**

- Laravel Framework v11.55.0
- Livewire v3.8.3
- PHPUnit 11.5.56
- Vite ^8.0.0
- Tailwind CSS ^3.1.0

## 1. Clone and install

```bash
git clone <repository-url>
cd ai-form-builder
composer install
npm install
```

Or use the composer setup script:

```bash
composer setup
```

This runs `composer install`, copies `.env.example` → `.env`, generates `APP_KEY`, migrates, and builds frontend assets.

## 2. Environment configuration

```bash
cp .env.example .env   # skip if composer setup already did this
php artisan key:generate
```

Edit `.env`:

```env
APP_URL=http://localhost:8000

# MySQL (recommended for development matching production)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ai_form_builder
DB_USERNAME=root
DB_PASSWORD=

# Queue (required for async AI)
QUEUE_CONNECTION=database

# AI (mock = no external service)
AI_PROVIDER_DRIVER=mock

# Optional Gemini (opt-in; server-side only)
# GEMINI_API_KEY=your-key
# AI_PROVIDER_DRIVER=gemini
# GEMINI_MODEL=gemini-2.5-flash
```

`.env.example` defaults to SQLite for quick starts. Tests use MySQL via `phpunit.xml`.

## 3. Database setup

Create the database (MySQL):

```sql
CREATE DATABASE ai_form_builder;
```

Run migrations and seed:

```bash
php artisan migrate:fresh --seed
```

Seeder creates: `test@example.com` (password from factory default — register a new user via UI for manual testing).

## 4. Frontend build

**Production assets:**

```bash
npm run build
```

**Development with hot reload:**

```bash
npm run dev
```

## 5. Start Laravel

```bash
php artisan serve
```

Application available at `http://localhost:8000` (or configured `APP_URL`).

### Bootstrap and rate limiting

Laravel 11 configures the application in `bootstrap/app.php` (routing, middleware, exceptions). **Do not register rate limiters there** — `RateLimiter::for()` inside `withMiddleware()` runs before the facade root exists and causes `A facade root has not been set`.

Named rate limiters (`public-form-view`, `public-form-submit`, `ai-form`, `form-draft`, `form-import`) are registered in `App\Providers\AppServiceProvider::configureRateLimiting()`. Route `throttle:*` middleware references those names unchanged.

## 6. Start queue worker (required for AI)

Asynchronous AI **requires** a queue worker when using `QUEUE_CONNECTION=database`.

**Prerequisite:** migrations must have created `cache`, `cache_locks`, and `jobs` on the configured database. Do not start the worker before `php artisan migrate`.

```bash
php artisan queue:work
```

Without a worker, AI jobs remain `pending`.

After code changes affecting queue workers:

```bash
php artisan queue:restart
```

Laravel stores the restart signal in the **database cache** (`cache` table). If you see `Table '...cache' doesn't exist`, run `php artisan migrate:status` and apply pending migrations — do not change `CACHE_STORE` to `file`.

`QUEUE_CONNECTION=sync` processes jobs inline (used in some tests) but does not demonstrate production-like async behavior.

## 7. Optional: Gemini provider

Only needed when `AI_PROVIDER_DRIVER=gemini`:

1. Obtain a Gemini API key (Google AI Studio)
2. Add to `.env`:

```env
GEMINI_API_KEY=your-key
AI_PROVIDER_DRIVER=gemini
GEMINI_MODEL=gemini-2.5-flash
```

3. Restart the queue worker

Gemini is called **server-side only** via `GeminiAIProvider`. The API key is never exposed to frontend JavaScript or committed to the repository. All output is validated by `AIOutputValidator` before a job completes.

Manual QA prompts (verify field fidelity after apply):

```bash
php scripts/verify-prompt-fidelity.php
```

## 8. Optional: FastAPI AI sidecar

Only needed when `AI_PROVIDER_DRIVER=http`:

```bash
cd ai-service
python -m venv .venv
.venv\Scripts\activate          # Windows
# source .venv/bin/activate     # macOS/Linux
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8001
```

Update `.env`:

```env
AI_PROVIDER_DRIVER=http
AI_SERVICE_URL=http://127.0.0.1:8001
```

## 8. All-in-one dev script

```bash
composer dev
```

Runs concurrently: `php artisan serve`, `queue:listen`, `pail` (logs), and `npm run dev`.

## 9. Running tests

```bash
php artisan test
```

Current suite: **321 tests, 1005 assertions**.

```bash
npm run build   # verify frontend builds
```

**Note:** `php artisan test --parallel` requires `brianium/paratest` 7.x (not installed).

FastAPI tests (optional, 4 tests):

```bash
cd ai-service
pytest
```

## 10. Web routes

| URL | Purpose |
|-----|---------|
| `/register`, `/login` | Authentication |
| `/forms` | Form list |
| `/forms/{id}/builder` | Form builder |
| `/forms/{id}/preview` | Owner preview |
| `/forms/{id}/insights` | Submission analytics |
| `/f/{slug}` | Public form |

Opening `/` redirects guests to `/login` and verified users to `/dashboard` (not Laravel's default welcome page). The login page includes a **Register** link for new accounts.

## Troubleshooting

| Issue | Fix |
|-------|-----|
| AI stuck on pending | Start `php artisan queue:work` |
| 419 on API calls | Ensure session cookie / CSRF for non-API; API uses session auth |
| Migration errors | Check DB credentials; run `migrate:fresh --seed` on clean DB |
| Vite assets missing | Run `npm run build` |

See [evaluator-checklist.md](evaluator-checklist.md) for manual verification steps.
