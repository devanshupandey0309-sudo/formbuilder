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
