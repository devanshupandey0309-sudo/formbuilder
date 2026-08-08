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

## Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── FormController.php
│   │   ├── SectionController.php
│   │   ├── FieldController.php
│   │   └── PublicFormController.php
│   └── Requests/
├── Models/
├── Policies/
└── Services/
    ├── FormService.php
    ├── SectionService.php
    ├── FieldService.php
    └── SubmissionService.php
routes/
└── api.php
tests/
└── Feature/
    ├── Form/
    ├── Section/
    ├── Field/
    └── PublicForm/
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

## Running Tests

```bash
php artisan test
```

Current suite: **76 tests passing**.

## Documentation

Architectural decisions are recorded in [`docs/decisions.md`](docs/decisions.md).

## License

MIT
