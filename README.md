# AI Form Builder

Laravel 11 application for building, publishing, and collecting responses from dynamic forms.

## Stack

- Laravel 11.55.0
- PHP 8.3
- MySQL
- Livewire 3

## Implemented Features

### Domain & persistence (Phase 1)

- Forms, sections, fields, submissions, submission answers, AI jobs, and form imports
- JSON schema storage on forms
- Form versioning and soft deletes

### Form builder API (Phases 2–3)

- Authenticated form CRUD
- Section and field management (create, update, delete, reorder)
- Schema compilation from ordered sections/fields
- Publish / unpublish workflow
- Owner-based authorization via `FormPolicy`

### Public forms & submissions (Phase 4)

- Public retrieval of published forms by slug
- Public submission endpoint with schema-driven validation
- Submission persistence with form version and schema snapshot
- Stable answer storage via `field_key`

### AI form generation (Phase 5)

- Natural-language prompt → structured form definition
- Provider abstraction via `AIProvider` contract
- AI job lifecycle tracking (`pending` → `processing` → `completed` / `failed`)
- Strict validation of AI output before persistence
- Preview/draft behavior — generation does not auto-publish
- Explicit apply step to commit generated structure to a form

### DOCX / XLSX import (Phase 6)

- Staged import workflow via `form_imports`
- DOCX and XLSX parser abstraction (`phpoffice/phpword` for DOCX; `phpoffice/phpspreadsheet` directly for XLSX — not the Maatwebsite Excel facade)
- Canonical normalized import structure shared by both formats
- Preview before commit — import does not modify form until explicitly committed
- Reuses existing field-type validation and form structure apply logic

### Livewire form builder UI (Phase 7)

- Browser-based form builder for authenticated form owners
- Section and field CRUD with inline editing
- Drag-and-drop section/field reordering (SortableJS)
- Field duplication with unique keys
- Field configuration panel (required, placeholder, options, number min/max)
- Two-way JSON editor synchronized with `FormService::compileSchema()`
- Draft/publish workflow with unsaved-change indicators
- Owner preview page and public slug-based form page
- Server-side validation via existing services (`SubmissionService`, `FormService`)

### Production-style AI architecture (Phase 8)

- Async AI generation/editing via Laravel queue (`GenerateAIFormJob`)
- FastAPI microservice (`ai-service/`) consumed over REST
- AI edit support with current schema context
- Livewire AI assistant panel with polling and explicit apply
- Retry/backoff for transient provider failures; validation failures fail fast

### Autosave + draft recovery (Phase 9)

- Debounced autosave (~1.5s) for form metadata, field editor, and JSON editor changes
- Draft state persists in normalized `sections` / `fields` tables — no competing schema format
- `forms.schema` remains the published snapshot; autosave never writes to it
- Optimistic revision tracking via `draft_revision` + `draft_saved_at` on `forms`
- Stale autosave requests rejected when a newer server draft exists
- Livewire autosave indicator (`Saving…`, `Saved just now`, `Unsaved changes`, etc.)
- Manual **Save Draft** button uses the same `FormDraftAutosaveService` path
- Browser `localStorage` recovery with Restore / Discard prompt after refresh/crash
- Relaxed draft validation (incomplete keys/labels allowed); publish validation unchanged
- API: `PUT /api/forms/{form}/draft`

### Product enhancement: Form Health Score (Phase 10)

- Deterministic, explainable **Form Health / Quality Score** (0–100) calculated from current draft structure
- Five scoring categories: Structure, Field Configuration, Validation, Required Fields, Usability
- Severity-modeled issues (`error`, `warning`, `info`) with actionable recommendations
- Not persisted — calculated on demand via `FormHealthService`
- Livewire builder panel + `GET /api/forms/{form}/health`
- Original product idea beyond core assignment requirements — helps identify quality issues before publishing

### Smart Submission Insights (Phase 11)

- Aggregated submission analytics for form owners from normalized `submissions` / `submission_answers`
- Runtime **database aggregation** (COUNT, GROUP BY, conditional SUM, MIN/MAX/AVG) — no denormalized stats table
- Overview metrics, 30-day daily trend, field-level response rates, option distributions, numeric summaries
- Deterministic rule-based recommendations (`success`, `warning`, `info`)
- API: `GET /api/forms/{form}/insights`
- Livewire insights page at `/forms/{form}/insights`

## Project Structure

```
app/
├── Contracts/
│   ├── AIProvider.php
│   └── FormImportParser.php
├── Http/
│   ├── Controllers/
│   └── Requests/
├── Livewire/
│   └── Forms/
│       ├── FormBuilder.php
│       ├── FormIndex.php
│       ├── FormInsights.php
│       ├── FormPreview.php
│       └── PublicForm.php
├── Models/
├── Policies/
└── Services/
    ├── AI/
    │   ├── AIOutputValidator.php
    │   └── MockAIProvider.php
    ├── Import/
    │   ├── DocxFormParser.php
    │   └── XlsxFormParser.php
    ├── AIFormApplyService.php
    ├── AIFormGenerationService.php
    ├── FormImportService.php
    ├── FormDraftAutosaveService.php
    ├── FormHealthService.php
    ├── SubmissionInsightService.php
    ├── FormSchemaValidator.php
    ├── FormStructureApplyService.php
    ├── FormService.php
    ├── SectionService.php
    ├── FieldService.php
    └── SubmissionService.php
routes/
├── api.php
└── web.php
resources/
├── js/
│   ├── app.js
│   └── form-builder.js
└── views/livewire/forms/
tests/
├── Feature/
│   ├── Builder/
│   ├── AI/
│   ├── Import/
│   ├── Form/
│   ├── Section/
│   ├── Field/
│   └── PublicForm/
└── Support/
    └── ImportFixtureBuilder.php
docs/
└── decisions.md
```

## API Endpoints

### Authenticated (session auth required)

| Method | URI | Description |
|---|---|---|
| GET | `/api/forms` | List user's forms |
| POST | `/api/forms` | Create form |
| GET | `/api/forms/{form}` | Show form |
| PUT | `/api/forms/{form}` | Update form |
| DELETE | `/api/forms/{form}` | Delete form |
| POST | `/api/forms/{form}/publish` | Publish form |
| POST | `/api/forms/{form}/unpublish` | Unpublish form |
| PUT | `/api/forms/{form}/draft` | Autosave draft (metadata, field editor, optional JSON) |
| GET | `/api/forms/{form}/health` | Form health / quality score (read-only) |
| GET | `/api/forms/{form}/insights` | Submission insights / analytics (read-only) |
| POST | `/api/forms/{form}/sections` | Create section |
| PUT | `/api/forms/{form}/sections/{section}` | Update section |
| DELETE | `/api/forms/{form}/sections/{section}` | Delete section |
| POST | `/api/forms/{form}/sections/reorder` | Reorder sections |
| POST | `/api/forms/{form}/sections/{section}/fields` | Create field |
| PUT | `/api/forms/{form}/sections/{section}/fields/{field}` | Update field |
| DELETE | `/api/forms/{form}/sections/{section}/fields/{field}` | Delete field |
| POST | `/api/forms/{form}/sections/{section}/fields/reorder` | Reorder fields |
| POST | `/api/forms/{form}/ai/generate` | Queue AI form generation from prompt |
| POST | `/api/forms/{form}/ai/edit` | Queue AI edit of existing form schema |
| GET | `/api/forms/{form}/ai/jobs/{aiJob}` | Retrieve AI job status/output |
| POST | `/api/forms/{form}/ai/jobs/{aiJob}/apply` | Apply completed AI output to form |
| POST | `/api/forms/{form}/imports` | Upload DOCX/XLSX import file |
| GET | `/api/forms/{form}/imports/{formImport}` | Retrieve import status |
| GET | `/api/forms/{form}/imports/{formImport}/preview` | Retrieve validated import preview |
| POST | `/api/forms/{form}/imports/{formImport}/commit` | Commit import to form structure |

### Public (no authentication)

| Method | URI | Description |
|---|---|---|
| GET | `/api/public/forms/{slug}` | Retrieve published form schema |
| POST | `/api/public/forms/{slug}/submit` | Submit answers |

## Public Form Examples

### Retrieve a published form

**Request**

```
GET /api/public/forms/contact-form
```

**Response**

```json
{
  "success": true,
  "message": "Form retrieved successfully.",
  "data": {
    "slug": "contact-form",
    "title": "Contact Form",
    "description": "Get in touch with us.",
    "version": 1,
    "published_at": "2026-08-08T10:00:00.000000Z",
    "schema": {
      "version": 1,
      "title": "Contact Form",
      "description": "Get in touch with us.",
      "sections": [
        {
          "id": 1,
          "title": "Contact Details",
          "description": null,
          "fields": [
            {
              "key": "full_name",
              "type": "text",
              "label": "Full Name",
              "required": true,
              "config": {},
              "validation": {}
            },
            {
              "key": "email",
              "type": "email",
              "label": "Email",
              "required": true,
              "config": {},
              "validation": {}
            }
          ]
        }
      ]
    }
  }
}
```

Draft and unpublished forms return `404 Not Found`.

### Submit a form

**Request**

```
POST /api/public/forms/contact-form/submit
Content-Type: application/json
```

```json
{
  "answers": {
    "full_name": "John Doe",
    "email": "john@example.com",
    "age": 25
  }
}
```

**Success response**

```json
{
  "success": true,
  "message": "Submission created successfully.",
  "data": {
    "submission_id": 1
  }
}
```

**Validation error response**

```json
{
  "success": false,
  "message": "Submission validation failed.",
  "data": {
    "errors": {
      "answers.email": ["The email field must be a valid email address."]
    }
  }
}
```

Submissions are validated against the **published compiled schema** (`forms.schema`), not mutable draft field definitions.

Supported field types: `text`, `textarea`, `number`, `email`, `date`, `select`, `radio`, `checkbox`.

## AI Form Generation

AI generation and editing convert natural-language prompts into structured draft form definitions compatible with the existing section/field architecture.

### Architecture

```
Client / Livewire Builder
        |
        v
Laravel API (auth, ai_jobs lifecycle, validation, apply)
        |
        | REST POST /generate-form
        v
FastAPI AI service (provider abstraction)
        |
        v
AI provider (mock by default)
```

- **`ai_jobs`** — domain-level AI workflow records (`pending` → `processing` → `completed` / `failed`)
- **`jobs` (Laravel queue table)** — infrastructure queue payloads only
- Laravel **`AIOutputValidator`** remains authoritative; malformed AI output never mutates forms
- AI proposes changes; the owner must explicitly **Apply**

### Async workflow

1. Authenticated form owner sends a prompt to `POST /api/forms/{form}/ai/generate` or `POST /api/forms/{form}/ai/edit`.
2. Laravel creates an `ai_jobs` record with status `pending` and dispatches `GenerateAIFormJob`.
3. The HTTP response returns immediately (`202 Accepted`) with the pending job.
4. A queue worker processes the job, calls the configured `AIProvider` (HTTP → FastAPI by default in production-style setups).
5. Output is validated by `AIOutputValidator` and stored on the AI job.
6. The client polls `GET /api/forms/{form}/ai/jobs/{aiJob}` (Livewire builder polls every 2s while pending/processing).
7. To commit the structure, the owner calls `POST /api/forms/{form}/ai/jobs/{aiJob}/apply`.

Generation/editing does **not** auto-publish. Applying sets the form to `draft`, replaces sections/fields transactionally via `FormStructureApplyService`, and clears stale schema.

### Retry behavior

- Transient FastAPI/network failures throw `TransientAIServiceException` and are retried by Laravel (3 attempts, backoff 5/15/30 seconds).
- Validation failures mark the AI job `failed` immediately and are **not** retried.

### Generate form structure

**Request**

```
POST /api/forms/{form}/ai/generate
Content-Type: application/json
```

```json
{
  "prompt": "Create an employee onboarding form with personal information, emergency contact details, department and joining date."
}
```

**Queued response (`202 Accepted`)**

```json
{
  "success": true,
  "message": "AI form generation queued successfully.",
  "data": {
    "ai_job": {
      "id": 1,
      "status": "pending",
      "type": "generate",
      "prompt": "Create an employee onboarding form..."
    },
    "generated_form": null
  }
}
```

Poll the job until `status` is `completed` or `failed`.

### Edit existing form

**Request**

```
POST /api/forms/{form}/ai/edit
Content-Type: application/json
```

```json
{
  "prompt": "Make phone number required"
}
```

Laravel sends the current compiled schema to FastAPI as `current_schema` with `operation=edit`. The AI returns a proposed **complete** schema stored in `validated_output`. The live form is unchanged until Apply.

### Completed job response

```json
{
  "success": true,
  "message": "AI job retrieved successfully.",
  "data": {
    "ai_job": {
      "id": 1,
      "status": "completed",
      "type": "generate",
      "attempt_count": 1
    },
    "generated_form": {
      "title": "Employee Onboarding Form",
      "sections": []
    }
  }
}
```

**Failure response (job status `failed`)**

```json
{
  "success": true,
  "message": "AI job retrieved successfully.",
  "data": {
    "ai_job": {
      "id": 1,
      "status": "failed",
      "error_message": "Generated form must include a title."
    },
    "generated_form": null
  }
}
```

### Apply generated/edited form

**Request**

```
POST /api/forms/{form}/ai/jobs/{aiJob}/apply
```

Only `completed` jobs with validated output can be applied.

### Running the full AI stack locally

**Terminal 1 — FastAPI**

```bash
cd ai-service
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8001
```

Requires **Python 3.12** (see `ai-service/Dockerfile`). Use Docker if local Python differs.

**Terminal 2 — Laravel queue worker**

```bash
php artisan queue:work
```

**Terminal 3 — Laravel app**

```bash
php artisan serve
npm run dev
```

Set in `.env`:

```env
AI_PROVIDER_DRIVER=http
AI_SERVICE_URL=http://127.0.0.1:8001
QUEUE_CONNECTION=database
```

### Legacy synchronous note

Phase 5 originally processed AI jobs synchronously in the HTTP request. Phase 8 queues all AI work; clients must poll job status.

## DOCX/XLSX Import

Imports follow a staged workflow:

`pending` → `processing` → `preview_ready` → `committed`  
or `failed` on validation/parsing errors.

Upload and parsing do **not** modify form structure. Commit is explicit and transactional.

`XlsxFormParser` reads spreadsheets via `phpoffice/phpspreadsheet` (`IOFactory`) directly. `DocxFormParser` uses `phpoffice/phpword`. Both parsers implement the shared `FormImportParser` contract.

### Expected XLSX structure

First row must contain headers:

| Section | Field | Type | Required | Options |
|---|---|---|---|---|
| Personal | Full Name | text | yes | |
| Personal | Email | email | yes | |
| Employment | Department | select | no | HR,IT,Finance |

- `Section`, `Field`, and `Type` are required per row
- `Required` accepts `yes`, `true`, or `1`
- `Options` is comma-separated and required for `select`, `radio`, and `checkbox`
- Supported types match `FieldService::SUPPORTED_TYPES`

### Expected DOCX structure

Use section headings followed by a field table:

```text
Personal Information

| Field     | Type  | Required | Options |
| Full Name | text  | yes      |         |
| Email     | email | yes      |         |
```

Headings may be Word titles or paragraph text immediately preceding the table.

### Upload import

**Request**

```
POST /api/forms/{form}/imports
Content-Type: multipart/form-data
```

Form field: `file` (`.docx` or `.xlsx`, max 5 MB)

**Success response**

```json
{
  "success": true,
  "message": "Form import processed successfully.",
  "data": {
    "form_import": {
      "id": 1,
      "status": "preview_ready",
      "source_type": "xlsx",
      "preview_data": {
        "title": "Imported Form",
        "sections": []
      }
    }
  }
}
```

### Commit import

**Request**

```
POST /api/forms/{form}/imports/{formImport}/commit
```

Commit replaces the form's sections/fields, clears schema, and keeps the form in `draft`.

## Livewire Form Builder

### Web routes

| Method | URI | Description |
|---|---|---|
| GET | `/forms` | List/create forms |
| GET | `/forms/{form}/builder` | Visual + JSON builder |
| GET | `/forms/{form}/preview` | Owner preview (draft or published) |
| GET | `/forms/{form}/insights` | Submission insights / analytics |
| GET | `/f/{slug}` | Public published form |

All builder routes require authentication and verified email. Authorization uses the existing `FormPolicy`.

### Builder workflow

1. Create a form from `/forms`.
2. Open the builder to add/reorder sections and fields.
3. Configure fields in the side panel (type, key, label, required, placeholder, options, validation).
4. Switch to the **JSON** tab to inspect or edit the compiled schema representation.
5. Click **Apply JSON** to sync valid JSON back into the builder. Invalid JSON is rejected with an error and does not mutate the form.
6. Use **Preview** to render the current draft or published schema.
7. Click **Publish** to compile schema and set the form live. Structure changes after publish clear cached schema until republished.

The JSON editor uses the same structure as `FormService::compileSchema()`:

```json
{
  "version": 1,
  "title": "Contact Form",
  "description": null,
  "sections": [
    {
      "id": 1,
      "title": "Contact Details",
      "description": null,
      "fields": [
        {
          "key": "email",
          "type": "email",
          "label": "Email",
          "required": true,
          "config": {},
          "validation": {}
        }
      ]
    }
  ]
}
```

### Draft vs published schema

| Layer | Storage | Purpose |
|---|---|---|
| **Draft builder state** | `sections` + `fields` tables (normalized) | Live editing; autosave target |
| **Published schema** | `forms.schema` JSON | Public API contract; submission validation source |
| **Draft revision** | `forms.draft_revision`, `forms.draft_saved_at` | Optimistic concurrency for autosave |

Autosave updates draft tables and metadata only. It never sets `status = published`, never increments `forms.version`, and never writes compiled JSON into `forms.schema`. Publishing remains an explicit **Publish** action with full validation.

### Autosave behavior

- Form title, description, field editor fields, and JSON editor text trigger debounced autosave (~1.5s after the last change via `wire:model.live.debounce.1500ms`).
- Structural actions (add/delete/reorder section or field) persist immediately through existing services and bump `draft_revision`.
- The header shows autosave status: `Saving…`, `Saved just now`, `Unsaved changes`, `Save failed — retry`, or `Newer draft on server — refresh`.
- **Save Draft** calls the same `FormDraftAutosaveService` as debounced autosave.
- Incomplete drafts are allowed during editing (e.g. invalid field keys are ignored and the previous key is kept). Publish still enforces strict validation.

**API autosave**

```
PUT /api/forms/{form}/draft
```

```json
{
  "draft_revision": 0,
  "title": "My Form",
  "description": "Optional",
  "field_id": 12,
  "field_editor": { "label": "Email", "key": "email", "type": "email" },
  "json_editor": "{ ... }",
  "apply_json": false
}
```

When `apply_json` is `true` and the JSON passes `FormSchemaValidator`, structure is applied while preserving published status/version.

### Draft recovery (browser)

If the browser closes before autosave completes, a recovery snapshot is stored in `localStorage` under `form-builder-recovery:{formId}`.

On builder load:

1. Server draft loads normally.
2. JavaScript compares the local snapshot timestamp with `draft_saved_at`.
3. If local is newer, a **Restore / Discard** banner appears.
4. **Restore** applies the snapshot and triggers autosave; **Discard** clears local storage.

After a successful server autosave, the local snapshot is updated with the latest server revision.

### Concurrency

Each successful autosave increments `draft_revision`. Clients must send the revision they last received; stale requests return `422` on `draft_revision` and do not overwrite a newer draft.

### Known limitations

- Browser recovery requires JavaScript and `localStorage`; it is not covered by PHPUnit (server-side restore/discard is tested via Livewire).
- Invalid JSON in the JSON tab is kept client-side until it validates or the user clicks **Apply JSON**.
- Multi-tab editing: the revision check prevents silent overwrites; refresh when a conflict is shown.

## Form Health / Quality Score

**Product enhancement (Phase 10)** — an original product idea that goes beyond basic form CRUD. Form Health proactively identifies structure, validation, usability, and configuration issues before publishing.

The score is calculated **dynamically** from the current normalized draft structure (`sections` / `fields`). It is **not stored** in the database, so it never becomes stale.

### Scoring categories (100 points total)

| Category | Max | Evaluates |
|---|---:|---|
| Structure | 20 | Title, sections, section titles, non-empty sections, ordering |
| Field Configuration | 25 | Keys, labels, supported types, options, duplicate keys |
| Validation | 25 | Type-appropriate validation (email, number, date), required field labels |
| Required Fields | 15 | Required-field balance, clarity, optional improvement hints |
| Usability | 15 | Placeholders, meaningful labels, large sections |

### Grades

| Score | Grade |
|---:|---|
| 90–100 | Excellent |
| 75–89 | Good |
| 60–74 | Needs Improvement |
| 40–59 | Poor |
| 0–39 | Critical |

### API

```
GET /api/forms/{form}/health
```

Example response:

```json
{
  "success": true,
  "message": "Form health retrieved successfully.",
  "data": {
    "score": 82,
    "grade": "Good",
    "summary": "Your form is in good shape with a few improvements recommended.",
    "categories": [
      { "key": "structure", "label": "Structure", "score": 20, "max": 20 },
      { "key": "fields", "label": "Field Configuration", "score": 23, "max": 25 }
    ],
    "issues": [
      {
        "severity": "warning",
        "code": "missing_validation",
        "field_key": "email",
        "section_id": 1,
        "section_title": "Contact",
        "message": "Email has no explicit validation rules."
      }
    ],
    "suggestions": [
      "Add email validation to Email."
    ]
  }
}
```

### Builder integration

The Form Builder displays a **Form Health** panel with score, grade, category breakdown, issues, and recommendations. Health is calculated once in Livewire state and refreshed on load, after structural changes, after successful autosave, and via a manual **Refresh** button.

Severity levels: `error` (should fix), `warning` (improvement recommended), `info` (optional suggestion).

## Smart Submission Insights

Phase 11 turns existing submission data into owner-facing analytics using **runtime database aggregation** against normalized `submissions` and `submission_answers` tables. Insights are **not persisted** and no denormalized statistics table is used in this phase.

### Architecture

```
Form
  ↓
SubmissionInsightService (SQL/Eloquent aggregation)
  ↓
overview + trend + field insights + recommendations
  ↓
API / Livewire UI
```

Field structure comes from `FormService::compileSchema()` (current normalized draft). Aggregates are scoped to the form via joins on `submissions.form_id`.

### Overview metrics

- Total submissions
- Submissions today / last 7 days / last 30 days
- Average submissions per day (total ÷ inclusive day span between first and latest submission)
- First / latest submission timestamps

Zero submissions return zeros and `null` timestamps without errors.

### Submission trend

Daily counts for the last 30 days (`DATE(submitted_at)` + `GROUP BY`), with zero-filled days for chart display.

### Field insights

Per field: key, label, type, required status, total responses, response rate (% of submissions with a non-empty answer).

Additional aggregates by type:

- **select / radio** — `GROUP BY value_text` option distribution
- **checkbox** — per-option counts via `JSON_CONTAINS` on `value_json` arrays
- **number** — `MIN` / `MAX` / `AVG` on numeric `value_text`

Raw answer values are not returned in the API response.

### Recommendations

Deterministic rules (not AI), e.g.:

- No submissions yet → share the public form
- High required-field completion → success message
- Low response rate → review optional/required settings
- Concentrated select option (≥ 60%) → informational insight

### API

```
GET /api/forms/{form}/insights
```

### Livewire UI

```
GET /forms/{form}/insights   (route name: forms.insights)
```

Requires auth + verified email. Builder / Preview / Insights navigation is shared via a form nav partial.

### Authorization

Uses existing `FormPolicy::view`. Cross-user access is forbidden. Only aggregated data is exposed.

### Performance note

Existing index on `submissions (form_id, submitted_at)` supports overview/trend queries. If field-key aggregation becomes a bottleneck at scale, a composite index on `submission_answers (field_key, submission_id)` could be considered — not added in this phase.

### Frontend dependency

Drag-and-drop ordering uses [SortableJS](https://github.com/SortableJS/Sortable) (`sortablejs` npm package), initialized from `resources/js/form-builder.js`.

## Running Tests

```bash
php artisan test
```

Current suite: **214 Laravel tests passing (584 assertions)**.

FastAPI service tests (`ai-service/tests/`, **4 tests**): run with Python 3.12 via `pytest` inside `ai-service/` or through the provided Dockerfile.

## Documentation

Architectural decisions are recorded in [`docs/decisions.md`](docs/decisions.md).

## License

MIT
