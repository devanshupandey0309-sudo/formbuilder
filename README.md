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

### Frontend dependency

Drag-and-drop ordering uses [SortableJS](https://github.com/SortableJS/Sortable) (`sortablejs` npm package), initialized from `resources/js/form-builder.js`.

## Running Tests

```bash
php artisan test
```

Current suite: **155 Laravel tests passing (404 assertions)**.

FastAPI service tests (`ai-service/tests/`, **4 tests**): run with Python 3.12 via `pytest` inside `ai-service/` or through the provided Dockerfile.

## Documentation

Architectural decisions are recorded in [`docs/decisions.md`](docs/decisions.md).

## License

MIT
