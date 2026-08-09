# Architecture

This document describes the actual runtime architecture of the AI Form Builder application.

## High-level architecture

```
Browser (Blade + Livewire + Vite assets)
        ↓
Laravel routes (web + api)
        ↓
Controllers / Livewire components
        ↓
Form Requests + FormPolicy authorization
        ↓
Domain Services
        ↓
Eloquent Models + Database
```

**Design principle:** business logic lives in services; controllers and Livewire components orchestrate requests and delegate to services. Policies enforce single-owner access.

## AI architecture (summary)

```
Browser / Livewire builder
        ↓
POST /api/forms/{form}/ai/generate|edit  (202 Accepted)
        ↓
AIJob record (status: pending)
        ↓
GenerateAIFormJob dispatched to Laravel queue
        ↓
Queue worker → AIFormGenerationService::processJob()
        ↓
AIProvider (mock or HTTP → FastAPI sidecar)
        ↓
AIResponseParser + AIOutputValidator
        ↓
AIJob updated (completed | failed)
        ↓
Owner polls job → explicit POST .../apply
        ↓
AIFormApplyService → FormStructureApplyService
```

See [ai-architecture.md](ai-architecture.md) for the full AI lifecycle.

## Form lifecycle

```
Create form (draft)
        ↓
Edit structure (sections / fields / JSON editor)
        ↓
Autosave draft (normalized sections + fields tables)
        ↓
Publish → compile schema → forms.schema snapshot
        ↓
Public form at /f/{slug} and GET /api/public/forms/{slug}
        ↓
Public submission → validate against published schema
        ↓
Submission + submission_answers persisted with schema_snapshot
        ↓
Insights aggregated from submissions (owner-only)
```

### Draft vs published

| Layer | Storage | Purpose |
|-------|---------|---------|
| Draft builder state | `sections` + `fields` | Live editing, autosave target |
| Published contract | `forms.schema` JSON | Public API, submission validation |
| Revision tracking | `draft_revision`, `draft_saved_at` | Optimistic concurrency for autosave |

Editing a published form clears the cached schema until republish. Public endpoints require `status = published` and a non-empty schema.

## AI job lifecycle

```
pending → processing → completed → apply (sets applied_at)
                    ↘ failed (terminal)
```

- **pending:** job created; HTTP response returned; no provider call yet.
- **processing:** queue worker owns the job.
- **completed:** validated output stored in `validated_output`; awaiting explicit apply.
- **failed:** terminal; safe error in `error_message`.
- **applied_at:** set once on successful apply; prevents duplicate application.

**Retry behavior:** transient infrastructure failures (`TransientAIServiceException`) retry up to 3 times with backoff (5s, 15s, 30s). Validation failures and permanent provider errors do not retry. Terminal jobs are not reprocessed.

## Import flow

```
Upload DOCX/XLSX
        ↓
FormImport record + synchronous parse
        ↓
preview_ready (or failed)
        ↓
GET preview (validated structure)
        ↓
POST commit (explicit, transactional)
        ↓
Form structure replaced (draft); publish still required
```

Import does not modify form structure until commit. Parsed structure passes the same validation rules as manual/AI structure apply.

## Public submission flow

```
GET published form by slug (status = published, schema present)
        ↓
Client renders schema fields
        ↓
POST answers
        ↓
SubmissionService validates against forms.schema (in memory)
        ↓
DB transaction: submission + answers
        ↓
schema_snapshot + form_version stored on submission
```

The **published compiled schema** is the source of truth for public submissions, not live draft field rows.

## Key services

| Service | Responsibility |
|---------|----------------|
| `FormService` | CRUD, compile schema, publish/unpublish |
| `SectionService` | Section CRUD, reorder |
| `FieldService` | Field CRUD, reorder, type validation |
| `FormDraftAutosaveService` | Debounced draft persistence, revision locking |
| `SubmissionService` | Published form lookup, submission validation/persistence |
| `AIFormGenerationService` | Queue AI jobs, process provider output |
| `AIFormApplyService` | Apply completed AI output (idempotent) |
| `FormImportService` | Upload, parse, preview, commit imports |
| `FormHealthService` | On-demand form quality score |
| `SubmissionInsightService` | Runtime analytics aggregation |

## Web UI components

| Component | Route | Purpose |
|-----------|-------|---------|
| `FormIndex` | `/forms` | List and create forms |
| `FormBuilder` | `/forms/{form}/builder` | Visual + JSON builder, AI panel, autosave |
| `FormPreview` | `/forms/{form}/preview` | Owner preview of draft/published schema |
| `FormInsights` | `/forms/{form}/insights` | Submission analytics |
| `PublicForm` | `/f/{slug}` | Public published form + submit |

## API layer

28 authenticated + public API routes under `/api`. All API responses use the `{ success, message, data }` envelope. Exception rendering is centralized in `bootstrap/app.php`.

See [api.md](api.md) for endpoint reference.

## Related documents

- [data-model.md](data-model.md) — database tables and indexes
- [security.md](security.md) — authorization and hardening
- [performance.md](performance.md) — Phase 12.6 optimizations
- [decisions.md](decisions.md) — architecture decision records
