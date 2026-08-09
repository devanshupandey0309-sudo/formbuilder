# API Reference

Source of truth: `php artisan route:list --path=api` (28 routes).

All API routes return JSON with this envelope:

**Success**

```json
{
  "success": true,
  "message": "...",
  "data": { }
}
```

**Error**

```json
{
  "success": false,
  "message": "..."
}
```

**Validation error (422)**

```json
{
  "success": false,
  "message": "Validation failed.",
  "data": {
    "errors": { "field": ["..."] }
  }
}
```

Some endpoints return contextual messages (e.g. `"Submission validation failed."`, `"Form not found."`) while preserving the same envelope shape.

## HTTP status codes

| Code | When |
|------|------|
| 200 | Successful read/update/delete |
| 201 | Resource created (form, submission, import upload) |
| 202 | AI job queued |
| 401 | Unauthenticated |
| 403 | Unauthorized (FormPolicy) |
| 404 | Resource not found (including scoped nested resources) |
| 422 | Validation failure |
| 429 | Rate limit exceeded |
| 500 | Unexpected server error (`APP_DEBUG=false` returns generic message) |

Rate limits (per `bootstrap/app.php`):

| Limiter | Limit |
|---------|-------|
| `public-form-view` | 60/min per IP |
| `public-form-submit` | 10/min per IP + slug |
| `ai-form` | 5/min per user + form |
| `form-draft` | 30/min per user + form |
| `form-import` | 5/min per user + form |

Authenticated routes require session auth (`auth` middleware) and use scoped route model binding.

---

## Forms

### List forms

`GET /api/forms`

- **Auth:** required
- **Response data:** array of user's forms

### Create form

`POST /api/forms`

- **Auth:** required
- **Body:** `{ "title": "...", "description": "...", "settings": {} }`
- **Success:** 201

### Show form

`GET /api/forms/{form}`

- **Auth:** required (owner)

### Update form

`PUT /api/forms/{form}`

- **Auth:** required (owner)
- **Body:** `{ "title": "...", "description": "...", "settings": {} }`

### Delete form

`DELETE /api/forms/{form}`

- **Auth:** required (owner)

### Publish form

`POST /api/forms/{form}/publish`

- **Auth:** required (owner)
- Compiles schema, sets `status = published`, increments version

### Unpublish form

`POST /api/forms/{form}/unpublish`

- **Auth:** required (owner)

---

## Draft / autosave

### Save draft

`PUT /api/forms/{form}/draft`

- **Auth:** required (owner)
- **Rate limit:** `form-draft`
- **Body:**

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

- **422:** stale `draft_revision` conflict

---

## Sections

### Create section

`POST /api/forms/{form}/sections`

- **Body:** `{ "title": "...", "description": "..." }`

### Update section

`PUT /api/forms/{form}/sections/{section}`

### Delete section

`DELETE /api/forms/{form}/sections/{section}`

### Reorder sections

`POST /api/forms/{form}/sections/reorder`

- **Body:** `{ "section_ids": [3, 1, 2] }`
- **404:** section from another form (scoped binding)

---

## Fields

Supported types: `text`, `textarea`, `number`, `email`, `date`, `select`, `radio`, `checkbox`

### Create field

`POST /api/forms/{form}/sections/{section}/fields`

- **Body:** `{ "key": "email", "type": "email", "label": "Email", "is_required": true, "placeholder": "...", "config": {}, "validation": {} }`

### Update field

`PUT /api/forms/{form}/sections/{section}/fields/{field}`

### Delete field

`DELETE /api/forms/{form}/sections/{section}/fields/{field}`

### Reorder fields

`POST /api/forms/{form}/sections/{section}/fields/reorder`

- **Body:** `{ "field_ids": [5, 4, 6] }`

---

## AI

All AI endpoints queue work asynchronously. The controller does **not** call the provider synchronously.

### Generate form

`POST /api/forms/{form}/ai/generate`

- **Auth:** required (owner)
- **Rate limit:** `ai-form`
- **Body:** `{ "prompt": "Create a contact form..." }` (min 10, max 5000 chars)
- **Success:** **202 Accepted**

### Edit form

`POST /api/forms/{form}/ai/edit`

- **Auth:** required (owner)
- **Body:** `{ "prompt": "Make phone required" }`
- **Success:** **202 Accepted**

### Get AI job

`GET /api/forms/{form}/ai/jobs/{aiJob}`

- **Auth:** required (owner)
- **404:** job belongs to another form (scoped binding)

### Apply AI output

`POST /api/forms/{form}/ai/jobs/{aiJob}/apply`

- **Auth:** required (owner)
- **422:** job not completed, already applied, or invalid output
- Applies validated structure to draft; does not auto-publish

---

## Imports

Supported upload types: **DOCX** and **XLSX** only (max 5 MB).

### Upload import

`POST /api/forms/{form}/imports`

- **Auth:** required (owner)
- **Content-Type:** `multipart/form-data`
- **Field:** `file`
- **Success:** 201 (or 422 if parse/validation fails)
- **`file_path` is never returned** in API responses

### Show import

`GET /api/forms/{form}/imports/{formImport}`

### Preview import

`GET /api/forms/{form}/imports/{formImport}/preview`

### Commit import

`POST /api/forms/{form}/imports/{formImport}/commit`

- Replaces form structure transactionally; form remains draft

---

## Public forms

No authentication required.

### Retrieve published form

`GET /api/public/forms/{slug}`

- **404:** unknown slug, draft, or unpublished form
- **422:** published but schema missing

### Submit form

`POST /api/public/forms/{slug}/submit`

- **Rate limit:** `public-form-submit`
- **Body:** `{ "answers": { "field_key": "value" } }`
- **Success:** 201 with `{ "submission_id": 1 }`
- **422:** validation failure (unknown fields rejected)
- Validates against **published** `forms.schema`

---

## Health

### Form health score

`GET /api/forms/{form}/health`

- **Auth:** required (owner)
- Returns on-demand quality score (0–100), issues, recommendations
- Not persisted

---

## Insights

### Submission insights

`GET /api/forms/{form}/insights`

- **Auth:** required (owner)
- Returns overview, trend, field analytics, recommendations
- Computed at runtime; not persisted

---

## Example: public form retrieval

```
GET /api/public/forms/contact-form
```

```json
{
  "success": true,
  "message": "Form retrieved successfully.",
  "data": {
    "slug": "contact-form",
    "title": "Contact Form",
    "description": "Get in touch.",
    "version": 1,
    "published_at": "2026-08-08T10:00:00.000000Z",
    "schema": { "version": 1, "title": "Contact Form", "sections": [] }
  }
}
```

## Example: public submission validation error

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

See also: [security.md](security.md), [ai-architecture.md](ai-architecture.md), [architecture.md](architecture.md).
