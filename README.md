# AI Form Builder

Laravel 11 application for building, publishing, and collecting responses from dynamic forms — with AI-assisted generation, DOCX/XLSX import, submission insights, and a Livewire visual builder.

| | |
|---|---|
| **Live Demo** | _Pending deployment — URL will be added here before final submission_ |
| **GitHub** | _Add repository URL before push_ |
| **Demo credentials (local seed)** | `test@example.com` / `password` (register a new account for verified-email flows) |

## Overview

Authenticated users create forms with sections and fields, publish them to a public slug URL, collect submissions, and analyze responses. AI generation and file import propose structure changes that require explicit owner approval before applying.

**Current verified state:** 349 tests passing · 1069 assertions · 28 API routes · `npm run build` passes

## Features

| Area | Capabilities |
|------|-------------|
| **Authentication** | Register, login, email verification (Breeze) |
| **Form builder** | Sections, fields, drag-and-drop reorder, duplicate, JSON editor |
| **Field types** | text, textarea, number, email, date, select, radio, checkbox |
| **Draft workflow** | Autosave, revision conflict handling, browser recovery |
| **Publishing** | Publish, unpublish, republish after edits |
| **Public forms** | `/f/{slug}` — schema-driven validation, success state |
| **Submissions** | Atomic persistence with schema snapshot and version |
| **Insights** | Overview, trends, field analytics, recommendations |
| **Form health** | On-demand quality score (0–100) with actionable issues |
| **AI generation** | Async queue, prompt → validated structure, explicit apply |
| **AI editing** | Edit existing schema via natural language |
| **Import** | DOCX/XLSX upload → preview → commit |
| **API** | 28 REST endpoints with consistent JSON envelope |
| **Security** | Single-owner policy, scoped bindings, rate limiting |

## Technology Stack

| Layer | Technology | Version (lock file) |
|-------|------------|-------------------|
| Backend | Laravel | v11.55.0 |
| Language | PHP | ^8.3 |
| Database | MySQL / SQLite | configurable |
| ORM | Eloquent | (Laravel) |
| Frontend | Blade + Livewire | Livewire v3.8.3 |
| CSS | Tailwind CSS | ^3.1.0 |
| Build | Vite | ^8.0.0 |
| DnD | SortableJS | ^1.15.6 |
| Queue | Laravel Queue | database driver (default) |
| AI | Provider abstraction | mock (default) / gemini / HTTP → FastAPI |
| Testing | PHPUnit | 11.5.56 |

## Architecture

```
Browser → Laravel (Livewire + API) → Controllers → Policies/Requests → Services → Models/DB
```

AI flow: API (202) → AIJob → Queue → GenerateAIFormJob → AIProvider → Validate → Poll → Apply

Full diagrams and lifecycle details: **[docs/architecture.md](docs/architecture.md)**

### Bootstrap and rate limiting

- `bootstrap/app.php` configures routing, middleware registration (via empty `withMiddleware()` callback), and API exception rendering.
- Named rate limiters are registered in `App\Providers\AppServiceProvider::configureRateLimiting()` — not inside `withMiddleware()`, because Laravel facades (including `RateLimiter`) are not available until the application container is booted.
- Route-level `throttle:*` middleware references the same limiter names defined in the service provider.

### Database cache and queue

This project uses **database-backed infrastructure** by default (`CACHE_STORE=database`, `QUEUE_CONNECTION=database`). Laravel's standard migrations create `cache`, `cache_locks`, `jobs`, `job_batches`, and `failed_jobs`. Run `php artisan migrate` before starting `php artisan queue:work`. If the worker reports `Table '...cache' doesn't exist`, check `php artisan migrate:status` — do not switch the cache driver to `file`.

Opening `/` redirects guests to login (not Laravel's welcome page). The login page includes a Register link for new accounts.

## Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configure DB in .env, then:
php artisan migrate:fresh --seed
npm install && npm run build
php artisan serve
php artisan queue:work    # required for async AI — run AFTER migrations
```

Or: `composer setup` then start server + queue worker.

Detailed setup: **[docs/local-development.md](docs/local-development.md)**

## Environment Configuration

Key variables (see `.env.example`):

```env
APP_URL=http://localhost:8000
DB_CONNECTION=mysql          # or sqlite
QUEUE_CONNECTION=database    # not sync for production-like AI
AI_PROVIDER_DRIVER=mock      # mock | gemini | http
AI_SERVICE_URL=http://127.0.0.1:8001
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
```

No API keys or secrets are committed. `.env.example` contains placeholders only.

## Database Setup

```bash
php artisan migrate:fresh --seed
```

Schema documentation: **[docs/data-model.md](docs/data-model.md)**

## Running the Application

| Command | Purpose |
|---------|---------|
| `php artisan serve` | Web server |
| `php artisan queue:work` | Process AI/import queue jobs |
| `npm run dev` | Vite dev server (hot reload) |
| `npm run build` | Production frontend assets |
| `composer dev` | All of the above concurrently |

## Queue Worker

Asynchronous AI **requires** a queue worker when `QUEUE_CONNECTION=database`:

```bash
php artisan queue:work
```

Without it, AI jobs remain `pending`. See **[docs/ai-architecture.md](docs/ai-architecture.md)**.

## Frontend

Tailwind CSS via Vite. Builder drag-and-drop uses SortableJS (`resources/js/form-builder.js`).

```bash
npm run dev     # development
npm run build   # production
```

## Testing

```bash
php artisan test
```

**349 tests · 1069 assertions**

Optional FastAPI tests: `cd ai-service && pytest` (4 tests, Python 3.12).

## Evaluator Quick Start

Fastest path to verify the project:

1. **Install:** `composer install && npm install`
2. **Configure:** copy `.env.example` → `.env`, set database, `php artisan key:generate`
3. **Migrate:** `php artisan migrate:fresh --seed`
4. **Build:** `npm run build`
5. **Start:** `php artisan serve` + `php artisan queue:work` (separate terminal)
6. **Register** a new account at `/register`
7. **Create form** at `/forms`
8. **Build:** add sections/fields in `/forms/{id}/builder`
9. **Publish** and note the public URL
10. **Open public form** at `/f/{slug}` and submit a response
11. **View insights** at `/forms/{id}/insights`
12. **Test AI:** generate via builder AI panel (queue worker must be running)
13. **Test import:** upload DOCX/XLSX via API (see [docs/api.md](docs/api.md))
14. **Run tests:** `php artisan test`

Manual checklist: **[docs/evaluator-checklist.md](docs/evaluator-checklist.md)**

Requirements mapping: **[docs/assignment-requirements-checklist.md](docs/assignment-requirements-checklist.md)**

## Public Form Flow

1. Owner publishes form → compiled schema stored in `forms.schema`
2. Public access at `/f/{slug}` or `GET /api/public/forms/{slug}`
3. Submissions validated against published schema (not draft fields)
4. Draft/unpublished forms return 404

Details: **[docs/api.md](docs/api.md#public-forms)**

## AI Generation

1. Owner sends prompt → `POST /api/forms/{form}/ai/generate` → **202 Accepted**
2. Queue worker processes job via mock, Gemini, or HTTP provider
3. Owner polls job status → applies when completed
4. Apply replaces draft structure; publish is separate

**Default: mock provider (no real LLM, no external service required).** When a prompt explicitly lists fields, the mock provider returns only those fields; generic prompts still use the default onboarding fixture. Validated output includes explicit email/date validation metadata where supported.

### Optional Gemini provider

Gemini is opt-in and called **server-side only** (never from frontend JavaScript):

1. Obtain a Gemini API key from Google AI Studio
2. Add to `.env`:

```env
GEMINI_API_KEY=your-key
AI_PROVIDER_DRIVER=gemini
GEMINI_MODEL=gemini-2.5-flash
```

3. Restart the queue worker after changing provider settings

All Gemini output passes through `AIOutputValidator` before a job completes. Missing `GEMINI_API_KEY` when `AI_PROVIDER_DRIVER=gemini` fails with a clear application error (no silent fallback to mock).

### Field validation in the builder

When editing a field in the builder, validation controls appear based on field type (email format, date range, number min/max, text length). Validation metadata is persisted on the field, enforced on public submission, and reflected in form health checks.

Full details: **[docs/ai-architecture.md](docs/ai-architecture.md)**

## Import Flow

1. Upload DOCX/XLSX → parse → preview
2. Owner reviews validated structure
3. Explicit commit replaces form structure (draft)
4. Publish required for public access

Supported: `.docx`, `.xlsx` (max 5 MB). Sample files: [`samples/import/`](samples/import/). Not supported: CSV file import (submission CSV export is not yet implemented).

## API

28 routes under `/api`. All responses use:

```json
{ "success": true|false, "message": "...", "data": { } }
```

Full reference: **[docs/api.md](docs/api.md)**

### Web routes

| Route | Component |
|-------|-----------|
| `/forms` | Form list |
| `/forms/{form}/builder` | Visual + JSON builder |
| `/forms/{form}/preview` | Owner preview |
| `/forms/{form}/insights` | Analytics |
| `/f/{slug}` | Public form |

## Security

Single-owner `FormPolicy`, scoped route model binding, rate limiting, schema-driven public validation, API error sanitization when `APP_DEBUG=false`.

Details: **[docs/security.md](docs/security.md)**

## Performance

Phase 12.6: insights batching, relation reuse, performance indexes on `submission_answers.field_key` and `form_imports(form_id, status)`. No application-level caching (intentional).

Details: **[docs/performance.md](docs/performance.md)**

## Project Structure

```
app/
  Http/Controllers/     API controllers
  Http/Requests/        Validation + authorization
  Http/Responses/       ApiResponse envelope
  Livewire/Forms/       Builder, public form, insights UI
  Jobs/                 GenerateAIFormJob
  Models/               Form, Section, Field, Submission, AIJob, FormImport
  Policies/             FormPolicy
  Services/             Domain business logic
  Services/AI/          Provider, parser, validator, prompt contract
  Services/Import/      DOCX/XLSX parsers
database/migrations/    Schema (13 migrations)
resources/views/        Blade + Livewire templates
resources/js/           Vite entry + form-builder.js
routes/                 web.php, api.php
tests/                  349 PHPUnit tests
docs/                   Architecture, API, security, checklists
ai-service/             Optional FastAPI sidecar
```

## Known Limitations

Mock AI by default, polling (not WebSockets), DOCX/XLSX import only, no response caching, single-owner auth.

Full list: **[docs/known-limitations.md](docs/known-limitations.md)**

## Deployment Preparation

The application is deployment-ready in code:

- Public URLs generated via `route()` and `APP_URL` (no hardcoded demo URL)
- Queue worker required for AI in production
- Run `npm run build` before deploy
- Set `APP_DEBUG=false` in production
- Configure real database and `QUEUE_CONNECTION`

Deployment steps and live demo URL: **Phase 12.9**

## Live Demo

**Deployment is planned for Phase 12.9.**

A live demo URL will be provided after deployment. Do not expect a hosted URL until Phase 12.9 is complete.

## Documentation Index

| Document | Contents |
|----------|----------|
| [architecture.md](docs/architecture.md) | System design, lifecycles |
| [data-model.md](docs/data-model.md) | Tables, indexes, relationships |
| [api.md](docs/api.md) | All 28 API endpoints |
| [security.md](docs/security.md) | Auth, policies, rate limits |
| [ai-architecture.md](docs/ai-architecture.md) | AI queue, providers, validation |
| [performance.md](docs/performance.md) | Phase 12.6 optimizations |
| [local-development.md](docs/local-development.md) | Setup guide |
| [evaluator-checklist.md](docs/evaluator-checklist.md) | Manual verification |
| [assignment-requirements-checklist.md](docs/assignment-requirements-checklist.md) | Requirement mapping |
| [known-limitations.md](docs/known-limitations.md) | Trade-offs and deferrals |
| [decisions.md](docs/decisions.md) | Architecture decision records |
| [phase-12.6-performance-audit.md](docs/phase-12.6-performance-audit.md) | Performance audit |

## License

MIT
