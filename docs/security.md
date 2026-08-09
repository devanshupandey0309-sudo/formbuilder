# Security

Security controls were implemented and covered by regression tests. This document describes the actual model — not an absolute security guarantee.

## Authentication

- Laravel Breeze session authentication
- Builder web routes require `auth` + `verified` email
- API authenticated routes use `auth` middleware (session-based)
- Unauthenticated API requests return **401** with `{ "success": false, "message": "Unauthenticated." }`

## Authorization (FormPolicy)

Single-owner model via `App\Policies\FormPolicy`:

| Ability | Rule |
|---------|------|
| viewAny, create | any authenticated user |
| view, update, delete | `$user->id === $form->user_id` |

Controllers call `$this->authorize(...)` before mutating or reading owner data. Form requests also check policy in `authorize()`.

## Scoped route model binding

Authenticated API routes use `scopeBindings()`:

```
/api/forms/{form}/sections/{section}/fields/{field}
```

Nested resources must belong to the parent form/section. Cross-form or cross-section IDs return **404** (not 403) to avoid leaking resource existence.

Covered by `tests/Feature/Security/AuthorizationTest.php`.

## Service-level ownership assertions

Services assert relationships before mutations:

- Section belongs to form
- Field belongs to section and form
- AI job belongs to form
- Import belongs to form

## AI job access

- Generate/edit require form `update` permission
- Show job requires form `view`
- Apply requires form `update`
- Cross-user apply denied (**403**)
- Cross-form job access returns **404** via scoped binding

## Import access

- Upload/commit require form `update`
- Show/preview require form `view`
- Cross-form import access returns **404** without leaking preview data
- Internal `file_path` excluded from API via `FormImportResource`

## Draft autosave

- `PUT /api/forms/{form}/draft` requires form ownership
- Stale revision rejected with **422**
- Cannot target another user's form

## Public form security

- Only `status = published` forms accessible by slug
- Draft/unpublished return **404**
- Submissions validated against published schema snapshot
- Unknown field keys rejected
- Owner email/user_id not exposed on public web pages
- Public endpoints intentionally unauthenticated

## Rate limiting

Configured in `App\Providers\AppServiceProvider::configureRateLimiting()`:

| Endpoint group | Limiter | Limit |
|----------------|---------|-------|
| Public form view | `public-form-view` | 60/min/IP |
| Public submit | `public-form-submit` | 10/min/IP+slug |
| AI generate/edit | `ai-form` | 5/min/user+form |
| Draft autosave | `form-draft` | 30/min/user+form |
| Import upload | `form-import` | 5/min/user+form |

**429** responses use `{ "success": false, "message": "Too many requests." }`.

## Mass assignment protection

Models use explicit `$fillable` arrays. Controllers and services assign only validated/known fields — not raw request input to models.

## API exception sanitization

When `APP_DEBUG=false`, unhandled API exceptions return:

```json
{ "success": false, "message": "An unexpected error occurred." }
```

No stack traces, filesystem paths, or SQL details leak. Intentional contextual messages (validation, not found, submission errors) are preserved.

## Livewire authorization

Livewire components load forms through authenticated routes. `FormBuilder` and related components operate on forms the user can access via route model binding and policy checks on API actions invoked from the builder.

## Sensitive data exposure controls

- Public API schema excludes internal IDs where appropriate
- Import `file_path` not in API responses
- Insights return aggregates only, not raw answer dumps for all fields
- `.env.example` contains placeholders only (no real API keys)

## Known limitations

1. **Form ID enumeration:** accessing another user's form by ID may return **403** rather than **404** on direct form routes (policy-based).
2. **Fillable strategy:** models use `$fillable` rather than global `$guarded = []`; relies on disciplined service-layer assignment.
3. **Public forms are unauthenticated:** by design; rate limiting and schema validation are the primary controls.
4. **Livewire authorization:** enforced via component architecture and underlying services — not a separate API token layer.
5. **Session auth for API:** no Sanctum/token API; evaluator must be logged in for authenticated API calls from browser session.

## Test coverage

| Area | Test file |
|------|-----------|
| Authorization / IDOR | `tests/Feature/Security/AuthorizationTest.php` |
| Security regressions | `tests/Feature/Regression/SecurityRegressionTest.php` |
| API error envelope | `tests/Feature/API/ApiResponseConsistencyTest.php` |
| Rate limiting | `tests/Feature/Regression/ApiRateLimitRegressionTest.php` |
| Production safety | `tests/Feature/Regression/ProductionSafetyTest.php` |

See [known-limitations.md](known-limitations.md) for intentional trade-offs.
