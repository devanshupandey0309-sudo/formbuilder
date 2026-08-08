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

## Project Structure

```
app/
├── Contracts/
│   ├── AIProvider.php
│   └── FormImportParser.php
├── Http/
│   ├── Controllers/
│   │   ├── AIFormController.php
│   │   ├── FormController.php
│   │   ├── FormImportController.php
│   │   ├── SectionController.php
│   │   ├── FieldController.php
│   │   └── PublicFormController.php
│   └── Requests/
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
    ├── FormStructureApplyService.php
    ├── FormService.php
    ├── SectionService.php
    ├── FieldService.php
    └── SubmissionService.php
routes/
└── api.php
tests/
├── Feature/
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
| POST | `/api/forms/{form}/ai/generate` | Generate form structure from prompt |
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

AI generation converts a natural-language prompt into a structured draft form definition compatible with the existing section/field architecture.

### Workflow

1. Authenticated form owner sends a prompt to `POST /api/forms/{form}/ai/generate`.
2. An `ai_jobs` record is created and processed synchronously.
3. The configured `AIProvider` returns a structured form definition.
4. Output is validated and normalized by `AIOutputValidator`.
5. Raw and validated output are stored on the AI job.
6. The client inspects the generated preview via the job response or `GET /api/forms/{form}/ai/jobs/{aiJob}`.
7. To commit the structure, the owner calls `POST /api/forms/{form}/ai/jobs/{aiJob}/apply`.

Generation does **not** auto-publish. Applying sets the form to `draft`, replaces sections/fields transactionally, and clears stale schema.

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

**Success response**

```json
{
  "success": true,
  "message": "AI form generation completed successfully.",
  "data": {
    "ai_job": {
      "id": 1,
      "status": "completed",
      "prompt": "Create an employee onboarding form...",
      "raw_output": { "...": "..." },
      "validated_output": { "...": "..." }
    },
    "generated_form": {
      "title": "Employee Onboarding Form",
      "description": "Employee onboarding information",
      "sections": [
        {
          "title": "Personal Information",
          "description": null,
          "fields": [
            {
              "key": "full_name",
              "label": "Full Name",
              "type": "text",
              "required": true,
              "config": {}
            }
          ]
        }
      ]
    }
  }
}
```

**Failure response**

```json
{
  "success": false,
  "message": "AI form generation failed.",
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

### Apply generated form

**Request**

```
POST /api/forms/{form}/ai/jobs/{aiJob}/apply
```

**Success response**

```json
{
  "success": true,
  "message": "Generated form applied successfully.",
  "data": {
    "id": 1,
    "title": "Employee Onboarding Form",
    "status": "draft",
    "schema": null,
    "sections": []
  }
}
```

Only `completed` jobs with validated output can be applied.

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

## Running Tests

```bash
php artisan test
```

Current suite: **114 tests passing**.

## Documentation

Architectural decisions are recorded in [`docs/decisions.md`](docs/decisions.md).

## License

MIT
