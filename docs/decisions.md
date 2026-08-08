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
