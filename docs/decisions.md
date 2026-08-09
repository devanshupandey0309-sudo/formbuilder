# Architecture Decisions

This document records significant architectural decisions for the AI Form Builder project.

---

## ADR-001: Schema-driven submission validation

**Context**

Forms have mutable draft structure (sections/fields) and a compiled JSON schema stored on publish. Public submissions must be validated consistently even when draft structure changes after publishing.

**Decision**

Validate all public submissions against the published compiled schema stored in `forms.schema`, not against live `fields` / `sections` records.

**Reasoning**

- Draft edits must not change validation rules for already-published forms.
- The compiled schema is the contract exposed to public clients via `GET /api/public/forms/{slug}`.
- Using the same artifact for validation guarantees parity between rendered form and accepted input.

**Alternatives considered**

- Validating against live Eloquent field models: rejected because draft changes would affect public behavior before republish.
- Recompiling schema on every submission: rejected because draft state could differ from the published contract.

**Consequences**

- Submissions remain correct while draft structure evolves.
- If `forms.schema` is cleared (e.g. after builder edits), public retrieval/submission returns an error until the form is republished.
- Validation logic lives in `SubmissionService` and mirrors the compiled schema format produced by `FormService::compileSchema()`.

---

## ADR-002: Published schema as the validation source

**Context**

Forms can be edited after publishing. Field requirements, types, and options may change in draft state.

**Decision**

Only forms with `status = published` and a non-empty `schema` JSON value are publicly accessible. Submission validation reads field definitions exclusively from that schema snapshot.

**Reasoning**

- Prevents unpublished or incomplete forms from accepting data.
- Ensures required fields, supported types, and option lists reflect the published version.

**Alternatives considered**

- Falling back to live field records when schema is missing: rejected because it breaks version consistency.
- Allowing draft forms to accept submissions in development: rejected for production safety.

**Consequences**

- Draft/unpublished forms return `404` on public endpoints.
- Published forms missing schema return `422` with a clear error.
- Republish is required after structural edits that clear the cached schema.

---

## ADR-003: Submission schema snapshots

**Context**

Forms are versioned. Submissions must remain interpretable even if the form structure changes later.

**Decision**

Each submission stores:

- `form_version` — version at submit time
- `schema_snapshot` — exact published schema JSON used for validation

**Reasoning**

- Preserves historical context for reporting, export, and audit.
- Decouples submission meaning from current form state.

**Alternatives considered**

- Storing only `form_version` and reloading schema from a versions table: rejected because no version history table exists yet.
- Storing only answer key/value pairs: rejected because type/label context would be lost.

**Consequences**

- Submissions are self-describing.
- Slight storage duplication of schema JSON per submission.
- Future CSV/export features can rely on snapshot metadata.

---

## ADR-004: `field_key` as stable answer identifier

**Context**

Database field IDs may change or fields may be deleted. Submissions must remain stable and export-friendly.

**Decision**

Store answers in `submission_answers` with:

- `field_key` (required, unique per submission)
- `field_label` snapshot
- optional nullable `field_id` link to current field record

Values are stored in `value_text` for scalars and `value_json` for array values (e.g. multi-select checkbox).

**Reasoning**

- `fields.key` is already the stable identifier in the compiled schema.
- Denormalized `field_key` survives field edits/deletions.
- Matches the public submission payload shape (`answers` keyed by field key).

**Alternatives considered**

- Using `field_id` only: rejected because FK may become null after field deletion.
- Storing all values as JSON: rejected because scalar text export is simpler with `value_text`.

**Consequences**

- Public clients submit keyed objects, not positional arrays.
- Unknown keys are rejected during validation.
- CSV/export can pivot on `field_key` reliably.

---

## ADR-005: Transactional submission persistence

**Context**

A submission creates one `submissions` row and multiple `submission_answers` rows. Partial writes must not occur when validation or persistence fails.

**Decision**

Wrap submission creation in a single database transaction inside `SubmissionService::submit()`. Validation runs before any inserts.

**Reasoning**

- Guarantees atomicity.
- Prevents orphaned submissions or incomplete answer sets.
- Aligns with Laravel conventions for multi-record writes.

**Alternatives considered**

- Insert submission first, then answers without transaction: rejected due to partial failure risk.
- Queue/async submission processing: deferred to a later phase.

**Consequences**

- Failed validation produces no database rows.
- All answer rows share the same submission record.
- Rollback is automatic on any exception during persistence.

---

## ADR-006: AI provider abstraction

**Context**

Phase 5 introduces AI-assisted form generation. The application must support swapping AI backends (mock, OpenAI, Gemini, etc.) without rewriting business logic.

**Decision**

Define an `AIProvider` contract with a `generateForm(string $prompt): array` method. Business services depend on the interface; concrete providers live under `app/Services/AI/`. The default binding is `MockAIProvider` for deterministic local/testing behavior.

**Reasoning**

- Decouples generation workflow from vendor SDKs.
- Enables comprehensive tests without external API calls.
- Matches the existing service-oriented architecture.

**Alternatives considered**

- Calling a provider directly from `AIFormGenerationService`: rejected due to tight coupling.
- Using Laravel's queue `jobs` table for AI state: rejected; domain AI state belongs in `ai_jobs`.

**Consequences**

- Adding a real provider requires a new class and container binding only.
- Tests use `MockAIProvider::fake()` for deterministic scenarios.

---

## ADR-007: AI output validation

**Context**

AI-generated JSON cannot be trusted to be well-formed, complete, or compatible with the form builder.

**Decision**

All provider output passes through `AIOutputValidator` before an AI job is marked `completed`. Validation covers title, sections, fields, keys, labels, supported types, duplicate keys, and option requirements for select/radio/checkbox fields. Malformed output marks the job `failed` and stores an error message.

**Reasoning**

- Prevents corrupt section/field data from entering the system.
- Normalizes field keys to stable snake_case identifiers.
- Reuses `FieldService::SUPPORTED_TYPES` as the single type allowlist.

**Alternatives considered**

- Persisting raw AI output directly to sections/fields: rejected as unsafe.
- Validating only at apply-time: rejected because clients need trustworthy preview data earlier.

**Consequences**

- Failed jobs contain diagnostic `error_message` values.
- Validated output is safe to expose as preview and to apply later.

---

## ADR-008: AI generation does not automatically publish forms

**Context**

Forms have a distinct publish workflow that compiles schema and controls public availability.

**Decision**

AI generation stores preview data on `ai_jobs.validated_output` only. Committing structure requires an explicit apply operation via `AIFormApplyService`, which keeps the form in `draft`, clears stale schema, and replaces sections/fields transactionally. Publish remains the responsibility of `FormService::publishForm()`.

**Reasoning**

- Preserves owner review before public exposure.
- Avoids bypassing existing schema compilation and validation rules.
- Keeps AI generation separate from form persistence.

**Alternatives considered**

- Auto-creating and auto-publishing generated forms: rejected as too risky.
- Writing directly to sections/fields during generation: rejected to keep preview/commit separation.

**Consequences**

- Two-step workflow: generate → apply → publish.
- Apply replaces existing form structure; owners should treat it as a destructive draft update.

---

## ADR-009: AI jobs are separate from Laravel infrastructure jobs

**Context**

The project already has Laravel's queue `jobs` table for infrastructure processing. Phase 5 adds domain-level AI workflow tracking.

**Decision**

Use the existing `ai_jobs` table/model for AI prompt lifecycle, provider responses, validation results, retry metadata, and timestamps. Do not store AI domain state in Laravel's `jobs` table. Processing is synchronous in Phase 5; `laravel_job_id` remains available for future async integration.

**Reasoning**

- Domain AI jobs expose business fields (`prompt`, `validated_output`, `form_id`) not suited to Laravel's generic queue payload table.
- Avoids conflating infrastructure retries with AI generation semantics.
- Aligns with the schema created in Phase 1.

**Alternatives considered**

- Using only Laravel queue jobs for AI state: rejected due to poor fit for rich domain records and API inspection.
- Creating a second duplicate AI task table: rejected; `ai_jobs` already exists.

**Consequences**

- API clients inspect AI progress through `ai_jobs` records.
- Future async providers can populate `laravel_job_id` without changing the domain model.

---

## ADR-010: Staged import workflow

**Context**

DOCX/XLSX imports must not immediately mutate form structure. Owners need a preview/review step before committing imported fields.

**Decision**

Use the existing `form_imports` table with an explicit lifecycle:

`pending` → `processing` → `preview_ready` → `committed`  
or `failed`.

Upload creates a `form_import`, parses the file, validates normalized output, and stores preview data without touching `forms`, `sections`, or `fields`. Commit is a separate authenticated operation.

**Reasoning**

- Matches the AI generate → apply pattern already established in Phase 5.
- Gives owners a chance to inspect parsed content.
- Keeps failed imports from partially modifying forms.

**Alternatives considered**

- Immediate import into form structure: rejected as too risky.
- Creating a separate preview table: rejected; existing `preview_data` column is sufficient.

**Consequences**

- Import upload and commit are separate API calls.
- Failed imports remain inspectable via `form_imports.error_message`.

---

## ADR-011: Parser abstraction for imports

**Context**

DOCX and XLSX require different parsing strategies but must feed the same downstream validation and commit workflow.

**Decision**

Define a `FormImportParser` contract with `parse(string $path): array`. Implement `DocxFormParser` and `XlsxFormParser` under `app/Services/Import/`. `FormImportService` selects the parser based on detected file type.

**Reasoning**

- Avoids duplicated orchestration logic.
- Makes adding future formats (CSV, etc.) straightforward.
- Keeps controllers and import service free from format-specific parsing details.

**Alternatives considered**

- One monolithic import parser class: rejected due to poor separation.
- Parsing directly in the controller: rejected.

**Consequences**

- DOCX and XLSX each have focused parser classes and tests.
- Parser failures surface as import `failed` records with readable errors.

---

## ADR-012: Canonical normalized import format

**Context**

Multiple import sources must converge on the same structure expected by validation and form apply logic.

**Decision**

Both parsers produce the same canonical structure:

```json
{
  "title": "...",
  "description": null,
  "sections": [
    {
      "title": "...",
      "description": null,
      "fields": [
        {
          "key": "...",
          "label": "...",
          "type": "...",
          "required": true,
          "config": {}
        }
      ]
    }
  ]
}
```

Validation reuses `AIOutputValidator`. Field types must match `FieldService::SUPPORTED_TYPES`.

**Reasoning**

- One validator and one apply path for AI and import outputs.
- Stable field keys remain consistent with submission architecture.

**Alternatives considered**

- Separate import-only validation rules: rejected to avoid drift.
- Persisting parser-specific structures directly: rejected.

**Consequences**

- Import preview data is compatible with existing form apply logic.
- Parser authors must normalize rows/headings/tables into the canonical shape.

---

## ADR-013: Explicit import commit

**Context**

Even valid parsed imports should not become live form structure until the owner confirms.

**Decision**

Commit via `POST /api/forms/{form}/imports/{formImport}/commit` only when status is `preview_ready`. Commit uses `FormStructureApplyService` inside a transaction, replaces sections/fields, clears schema, sets form to `draft`, and marks the import `committed`.

**Reasoning**

- Preserves publish workflow control.
- Reuses the same transactional structure-apply logic as AI apply.
- Prevents partial imports through DB transactions.

**Alternatives considered**

- Auto-commit on successful upload: rejected.
- Merge import into existing sections without replacement: deferred; current apply replaces structure for clarity.

**Consequences**

- Import commit is destructive to existing form structure, similar to AI apply.
- Publishing still requires `FormService::publishForm()`.

---

## ADR-014: Livewire as the primary interactive builder UI

**Context**

Part A of the assignment requires a browser-based form builder with section/field editing, reordering, JSON synchronization, and publish workflow.

**Decision**

Implement the builder as Livewire 3 full-page components under `app/Livewire/Forms/`, using existing Blade/Tailwind Breeze styling. UI actions delegate to existing domain services rather than duplicating API controller logic in JavaScript.

**Reasoning**

- Livewire is already part of the stack (Breeze/Volt auth).
- Server-side services remain the source of truth for validation and persistence.
- Avoids building a separate SPA while still providing interactive editing.

**Alternatives considered**

- React/Vue SPA against REST API: rejected for scope and duplication.
- Blade-only forms without Livewire: rejected due to poor interactivity for reorder/config workflows.

**Consequences**

- Builder features are testable with `Livewire::test()`.
- UI state changes round-trip through PHP services and policies.

---

## ADR-015: Single schema representation between visual builder and JSON editor

**Context**

The assignment requires a JSON editor with two-way synchronization and forbids maintaining unrelated schema formats.

**Decision**

The JSON editor displays and accepts the compiled schema shape produced by `FormService::compileSchema()`. Valid JSON edits are normalized by `FormSchemaValidator` and applied through `FormStructureApplyService`.

**Reasoning**

- Reuses the same schema used for publishing and public submissions.
- Prevents drift between visual builder state and persisted JSON.
- Invalid JSON is rejected before any structure mutation.

**Alternatives considered**

- Separate builder-only DTO/schema: rejected due to duplication.
- Direct DB editing from JSON without validation: rejected as unsafe.

**Consequences**

- JSON apply replaces sections/fields transactionally (similar to AI/import apply).
- Section `id` values in JSON are informational; apply recreates structure from titles/fields.
- AI `validated_output` uses the same canonical section/field shape validated by `AIOutputValidator`.

---

## ADR-016: Server-side validation remains authoritative

**Context**

The builder UI collects submissions in preview/public pages and must not reimplement validation rules client-side.

**Decision**

Preview and public Livewire forms submit through `SubmissionService`, which validates against the published compiled schema. Draft preview renders from `compileSchema()` but blocks persistence until publish.

**Reasoning**

- Keeps one validation implementation aligned with Phase 4 public API behavior.
- Prevents UI/backend rule drift for required fields, types, and option constraints.

**Alternatives considered**

- Client-only validation in Livewire views: rejected.
- Separate Livewire validation layer: rejected as duplicate logic.

**Consequences**

- Public web form at `/f/{slug}` and API submission endpoint share validation semantics.
- Draft preview displays schema but does not accept persisted submissions.

---

## ADR-017: Asynchronous AI generation using Laravel queue

**Context**

The assignment requires AI generation not to block HTTP requests on long-running provider calls.

**Decision**

`POST /api/forms/{form}/ai/generate` and `POST /api/forms/{form}/ai/edit` create an `ai_jobs` record in `pending` status and dispatch `GenerateAIFormJob`. The API returns `202 Accepted` immediately. Queue workers call `AIFormGenerationService::processJob()`.

**Reasoning**

- Prevents web request timeouts during provider latency.
- Reuses Laravel's existing database queue infrastructure.
- Keeps domain AI state in `ai_jobs` separate from Laravel's `jobs` table.

**Alternatives considered**

- Synchronous processing in controller: rejected (Phase 5 behavior).
- Storing AI workflow only in Laravel queue payloads: rejected (poor API inspectability).

**Consequences**

- Clients and Livewire UI poll `GET /api/forms/{form}/ai/jobs/{aiJob}` for completion.
- `php artisan queue:work` must run in environments using the database queue driver.

---

## ADR-018: FastAPI as isolated AI service

**Context**

The assignment requires a separate Python FastAPI service consumed by Laravel over REST.

**Decision**

Add `ai-service/` with `POST /generate-form`. Laravel's `HttpAIProvider` calls FastAPI when `AI_PROVIDER_DRIVER=http`. FastAPI keeps provider-specific logic isolated behind its own provider abstraction (mock by default).

**Reasoning**

- Separates AI/provider concerns from Laravel domain logic.
- Allows independent scaling and provider swaps without changing form builder services.
- Laravel retains auth, validation, persistence, and apply behavior.

**Alternatives considered**

- Embedding provider SDKs directly in Laravel: rejected for assignment architecture.
- gRPC between services: rejected as unnecessary complexity.

**Consequences**

- Local/dev requires running FastAPI (`uvicorn`) plus a queue worker for end-to-end AI flows.
- Tests use `Http::fake()` or the in-process `MockAIProvider`.

---

## ADR-019: AI proposes changes; explicit user apply required

**Context**

AI generation and editing must not silently mutate production form structure.

**Decision**

AI jobs store proposed structure in `validated_output`. Form sections/fields change only when the owner calls apply (`AIFormApplyService` → `FormStructureApplyService`). Livewire shows Apply/Discard actions for completed jobs.

**Reasoning**

- Matches staged import workflow (ADR-010) and explicit commit semantics.
- Gives owners a review step before replacing structure.

**Alternatives considered**

- Auto-apply on successful AI completion: rejected as too risky.

**Consequences**

- Edit jobs receive current compiled schema as context but do not write it back until apply.
- Failed/invalid AI output never touches `sections` / `fields`.

---

## ADR-020: Draft revision autosave with client-side recovery

**Context**

The visual builder edits normalized `sections` / `fields` as draft state while `forms.schema` holds the published snapshot (ADR-001, ADR-015). Users need automatic draft persistence, protection against stale overwrites, and recovery after browser refresh without introducing a second schema format.

**Decision**

- Track draft saves with `draft_revision` (monotonic integer) and `draft_saved_at` on `forms`.
- Route autosave through `FormDraftAutosaveService`, which updates draft tables/metadata only and rejects requests when `expected draft_revision` does not match the locked row.
- Store in-progress browser state in `localStorage` for recovery; server remains authoritative after successful autosave.
- Use relaxed validation for draft field saves; keep strict validation on publish.

**Reasoning**

- Reuses existing normalized draft storage instead of a parallel JSON draft column.
- Optimistic revision checking is simpler than full CRDT/merge for this assignment scope.
- Client recovery covers the gap between keystrokes and debounced server persistence.

**Alternatives considered**

- Separate `draft_schema` JSON column: rejected to avoid competing with `compileSchema()` (ADR-015).
- Autosave on every keystroke without debounce: rejected to reduce database load.
- Silent last-write-wins without revision checks: rejected for multi-tab safety.

**Consequences**

- Autosave never publishes or mutates `forms.version` / published `forms.schema`.
- Stale autosave returns `422` with a `draft_revision` error.
- Browser recovery is best-effort; PHPUnit covers server restore/discard paths.

---

## ADR-021: Deterministic Form Health scoring

**Context**

Form owners benefit from proactive feedback on structure, validation, and usability before publishing. The score must be explainable, deterministic, and safe to run against draft state without affecting published schema behavior.

**Decision**

- Introduce `FormHealthService` to analyze normalized `sections` / `fields` and return a 0–100 score with categories, severities, issues, and suggestions.
- Calculate scores on demand; do **not** persist scores in the database.
- Use rule-based deductions only — **no AI** for scoring.
- Expose read-only access via `GET /api/forms/{form}/health` and a Livewire builder panel.

**Reasoning**

- Dynamic calculation prevents stale scores as draft structure changes (consistent with Phase 9 draft architecture).
- Deterministic rules are testable and explainable to users.
- Read-only analysis avoids accidental mutation of form data or published schema.

**Alternatives considered**

- Persisting scores in `forms.settings` or a dedicated table: rejected because draft edits would immediately stale stored values.
- AI-generated quality feedback: rejected for non-determinism and unnecessary coupling to the AI subsystem.

**Consequences**

- Health reflects current draft structure, not published `forms.schema`.
- Info-level suggestions minimally affect score; errors and warnings drive deductions.
- Large forms are analyzed synchronously in-process (no queue required for typical form sizes).

---

## ADR-022: Runtime database aggregation for submission insights

**Context**

Form owners need analytics on submissions (volume trends, field response rates, option distributions) without exposing raw answers or introducing synchronization complexity from cached statistics.

**Decision**

- Calculate insights on demand in `SubmissionInsightService` using SQL/Eloquent aggregation over normalized `submissions` and `submission_answers`.
- Do **not** introduce a denormalized statistics/analytics table in Phase 11.
- Return aggregated metrics and deterministic recommendations only; do not persist insight output.

**Reasoning**

- Keeps the initial architecture simple and always consistent with source data.
- Avoids cache invalidation/sync problems when new submissions arrive or form structure changes.
- Database aggregation (COUNT, GROUP BY, conditional SUM, MIN/MAX/AVG) scales better than loading all rows into PHP for typical form sizes.

**Alternatives considered**

- Denormalized stats table updated on each submission: deferred until measured performance requires it.
- AI-generated insights: rejected for non-determinism and unnecessary coupling.

**Consequences**

- Insights are read-only and scoped by `FormPolicy::view`.
- Checkbox distributions use MySQL `JSON_CONTAINS` per configured option.
- A future optimization phase may add materialized statistics or indexes if profiling shows bottlenecks.

---

## ADR-023: Register rate limiters in AppServiceProvider

**Context**

Laravel 11 configures middleware via `bootstrap/app.php` using `Application::configure()->withMiddleware()`. The project initially registered named rate limiters (`RateLimiter::for()`) inside that callback. During HTTP bootstrap, the callback runs before the application container sets the facade root, causing:

```
RuntimeException: A facade root has not been set.
```

**Decision**

Register all named rate limiters in `App\Providers\AppServiceProvider::configureRateLimiting()` during provider `boot()`. Keep `bootstrap/app.php` responsible for routing, middleware aliases, and API exception rendering only.

**Reasoning**

- Provider boot runs after the container and facades are available.
- Preserves existing limiter names, limits, keys, and route `throttle:*` middleware without behavioral change.
- Follows Laravel 11's separation between application configuration (`bootstrap/app.php`) and service registration (providers).

**Alternatives considered**

- Disabling rate limiting: rejected (security regression).
- Inline limit definitions in route files: rejected (duplication, harder to audit).
- Deferring limiter registration to a custom bootstrap callback: rejected in favor of standard provider boot.

**Consequences**

- `bootstrap/app.php` must not call `RateLimiter::for()`.
- Security documentation references `AppServiceProvider` as the rate limiter source of truth.
- Regression tests assert application bootstrap and limiter registration.

---

## Part D — Assignment differentiators

The assignment requires at least three meaningful improvements beyond core CRUD. This project implements:

| Differentiator | Implementation | Documentation |
|----------------|----------------|---------------|
| **Form Health scoring** | `FormHealthService` — deterministic 0–100 score with actionable issue codes (missing validation, empty sections, etc.) | ADR-021, [evaluator-checklist.md](evaluator-checklist.md) |
| **Submission insights** | `SubmissionInsightService` — overview, trends, per-field analytics, recommendations without denormalized tables | ADR-022, `/forms/{id}/insights` |
| **Draft autosave + recovery** | `FormDraftAutosaveService` + Livewire revision conflict handling + browser `localStorage` recovery | ADR-020, `FormDraftAutosaveTest` |

Additional differentiators documented elsewhere:

- **Explicit AI/import apply workflows** — staged proposals; owner must apply before structure changes (ADR-010, ADR-019)
- **Gemini + mock AI provider architecture** — opt-in real LLM with deterministic mock/CI default (ADR-006, [ai-architecture.md](ai-architecture.md))
- **Field validation metadata model** — `FieldValidationRules` ties builder UI, health checks, and public submission enforcement

---

## Assumptions

- Single-owner forms (no teams/roles) for assignment scope
- Session authentication (Breeze) for web + API; no Sanctum tokens
- MySQL in production-like setups; SQLite acceptable for quick local starts
- Mock AI provider is the default; Gemini and FastAPI HTTP providers are opt-in
- Published `forms.schema` is the public validation contract
- Phone numbers are modeled as `text` fields until a dedicated `phone` type is added
- File upload fields are not implemented in v1; assignment gap documented in [known-limitations.md](known-limitations.md)

---

## Next two weeks (if continuing beyond assignment)

1. **Submission management** — list/search submissions, CSV export using `field_key` + `schema_snapshot`
2. **Field type parity** — dedicated `phone` and `file` types with config (accepted types, max size) and public upload handling
3. **Deploy Phase 12.9** — hosted demo, production env, queue worker, `APP_DEBUG=false`, live URL in README
4. **Import UX** — in-builder preview/type correction before commit (API preview exists; UI mapping could expand)
5. **AI job cancellation** — endpoint to cancel in-flight jobs safely
