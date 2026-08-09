# Phase 12.6 — Query / Index / Performance Audit

## 1. Executive Summary

The application is a single-tenant Laravel form builder with moderate relational complexity. Most hot paths already use appropriate foreign-key indexes and targeted eager loading. The main performance gap found was **insights aggregation**, which previously issued one SQL query per select/radio/checkbox/number field. Phase 12.6 batches those aggregations while preserving exact output.

No application-level response caching exists today. That is acceptable for the assignment scale and avoids stale-schema risk.

Changes made were **targeted** — no architecture rewrite, no API contract changes, no security weakening.

---

## 2. Existing Performance Architecture

| Area | Pattern |
|------|---------|
| Form structure | Normalized `forms` → `sections` → `fields`; compiled JSON stored on publish |
| Builder | Livewire + eager-loaded sections/fields via `loadForm()` |
| Public form | Single slug lookup; uses published `forms.schema` JSON snapshot |
| Submissions | Transactional insert; validation against in-memory schema snapshot |
| Insights | SQL aggregation for overview/trend; field analytics now batched |
| AI jobs | Async queue; polling reads single `ai_jobs` row by primary key |
| Imports | Queued parsing; transactional commit |

There is **no** `Cache::` usage in application code.

---

## 3. Database / Index Audit

### forms
| Item | Status |
|------|--------|
| PK | `id` |
| FK | `user_id` → users (indexed via FK) |
| Unique | `slug` |
| Indexes | `(user_id, status)`, `(user_id, updated_at)` |
| Common WHERE | `user_id`, `slug`, `status` |
| Verdict | **ACCEPTABLE** — slug unique covers public lookup; user listing covered |

### sections
| Item | Status |
|------|--------|
| PK | `id` |
| FK | `form_id` (indexed) |
| Indexes | `(form_id, sort_order)` |
| Verdict | **ACCEPTABLE** — supports ordered section load per form |

### fields
| Item | Status |
|------|--------|
| PK | `id` |
| FK | `form_id`, `section_id` (indexed) |
| Unique | `(form_id, key)` |
| Indexes | `(section_id, sort_order)`, `(form_id, type)` |
| Verdict | **ACCEPTABLE** — key uniqueness and ordered field load covered |

### submissions
| Item | Status |
|------|--------|
| PK | `id` |
| FK | `form_id` (indexed) |
| Indexes | `(form_id, submitted_at)`, `(form_id, form_version)` |
| Verdict | **ACCEPTABLE** — insights date filtering uses `form_id + submitted_at` |

### submission_answers
| Item | Status |
|------|--------|
| PK | `id` |
| FK | `submission_id`, `field_id` |
| Unique | `(submission_id, field_key)` |
| Indexes | `field_id`; **added** `field_key` |
| Verdict | **FIXED** — insights joins filter on `field_key` repeatedly |

### ai_jobs
| Item | Status |
|------|--------|
| PK | `id` |
| FK | `user_id`, `form_id` |
| Indexes | `(user_id, status)`, `(form_id, type, status)` |
| Verdict | **ACCEPTABLE** — polling uses PK lookup; scoped routes use PK + form_id check |

### form_imports
| Item | Status |
|------|--------|
| PK | `id` |
| FK | `user_id`, `form_id` |
| Indexes | `(user_id, status)`; **added** `(form_id, status)` |
| Verdict | **FIXED** — form-scoped import status queries benefit from composite index |

### users
Standard Laravel auth table. No custom hot-path queries beyond PK/email lookup.

---

## 4. N+1 Findings

| Path | Finding | Action |
|------|---------|--------|
| Form builder mount | Eager loads `sections.fields` | **NO ISSUE** |
| Form preview | Compiles schema once on mount | **NO ISSUE** |
| Form index | Simple list, no relations | **NO ISSUE** |
| Public form | Single form row, schema in JSON column | **NO ISSUE** |
| Insights field analytics | Was 1+ queries per select/radio/checkbox/number field | **FIXED** — batched |
| Form health | Explicit eager load with ordering | **NO ISSUE** |
| AI polling | Single `AIJob` find by ID | **NO ISSUE** |
| Submission write | Single `whereIn` for field models | **NO ISSUE** |
| Import commit | Transactional loops; acceptable for import size | **ACCEPTABLE** |

---

## 5. Form / Builder Findings

- `FormBuilder::loadForm()` eager-loads ordered sections and fields — correct pattern.
- `FormBuilder::findOwnedSection/Field()` uses in-memory collections — no extra queries.
- `runBuilderAction()` reloads form after mutations — intentional freshness for Livewire.
- **FIXED:** `FormService::compileSchema()` now reuses already-loaded relations instead of re-querying when structure is present in memory.
- Autosave / revision locking unchanged.

---

## 6. Public Form Findings

- **FIXED:** `getPublishedFormBySlug()` now filters `status = published` in SQL (still uses unique slug index).
- Published schema read from `forms.schema` JSON — no section/field N+1 on public render.
- Submit validates against in-memory schema snapshot; one batched field lookup for `field_id` FK.
- Livewire submit calls `$this->form->fresh()` — one extra query for correctness.

---

## 7. Submission Findings

- Wrapped in `DB::transaction()` — atomic.
- Validation uses compiled schema array in memory — no repeated DB reads.
- Answer inserts: one INSERT per answered field (expected).
- **NO ISSUE** for assignment scale.

---

## 8. Insights Findings

Before:
- Overview: 1 aggregated query ✓
- Trend: 1 grouped query ✓
- Response counts: 1 grouped query ✓
- Per-field select/radio: 1 query each ✗
- Per-checkbox-option: 1 query each ✗
- Per-number field: 1 query each ✗

After (**FIXED**):
- All select/radio distributions: **1 batched grouped query**
- All checkbox distributions: **1 fetch + PHP aggregation** (same counts as `JSON_CONTAINS` per option)
- All numeric summaries: **1 batched grouped query**

Business logic and response format unchanged — all existing unit tests pass.

---

## 9. AI / Queue Findings

- Controller returns 202 without provider call — unchanged.
- Polling: `AIJob::find($id)` — O(1) PK lookup.
- `GenerateAIFormJob` uses `ShouldBeUnique` + terminal-state guards — unchanged.
- Existing `(form_id, type, status)` index sufficient.
- **NO ISSUE**

---

## 10. Import Findings

- Parsing loads full spreadsheet/document via PhpSpreadsheet/PhpWord — acceptable for assignment file size limits.
- Commit is transactional.
- Added `(form_id, status)` index for scoped import listing/filtering.
- **ACCEPTABLE** — no parser rewrite.

---

## 11. Livewire Findings

| Component | Notes |
|-----------|-------|
| FormBuilder | AI polls every 2s while pending/processing — acceptable; single-row read |
| FormBuilder | Autosave debounced — intentional |
| FormBuilder | `refreshFormHealth()` runs after builder actions — acceptable cost |
| PublicForm | Minimal state; schema hydrated once on mount |
| FormInsights | Insights computed once on mount |

No UX/polling changes made — behavior preserved.

---

## 12. Changes Made

1. **`SubmissionInsightService`** — batched option, checkbox, and numeric aggregations.
2. **`FormService::ensureFormStructureLoaded()`** — skip redundant relation queries when structure already loaded.
3. **`SubmissionService::getPublishedFormBySlug()`** — filter published status in SQL.
4. **Migration `2026_08_08_210000_add_performance_indexes.php`**
   - `submission_answers.field_key`
   - `form_imports (form_id, status)`
5. **Tests `tests/Feature/Performance/QueryPerformanceTest.php`** — bounded query-count regression tests.

---

## 13. Changes Intentionally NOT Made

- No Redis/cache layer for compiled schemas (correctness > speculative speed).
- No change to AI polling interval.
- No change to import parser memory model.
- No composite index on `forms(status, slug)` — slug is already unique.
- No index on `ai_jobs.applied_at` — not used in WHERE clauses.
- No eager loading on form index API (forms returned without nested relations by design).

---

## 14. Remaining Performance Limitations

- Insights checkbox batching loads matching answer rows into PHP — fine for hundreds/thousands of submissions; at very large scale, consider DB-native JSON aggregation.
- Builder re-loads full form structure after each mutating action — correct for Livewire but not minimal-query.
- `compileSchema()` may still query when relations not pre-loaded (first load, API paths).
- Import parsers load entire files into memory.
- AI polling is client-driven (2s) — acceptable for demo; WebSockets not implemented.

---

## 15. Production Recommendations

1. Run `php artisan queue:work` (supervisor) for AI and imports.
2. Use `QUEUE_CONNECTION=database` or Redis — **not** `sync` in production.
3. Set `APP_URL` to public domain before deployment.
4. Run `php artisan config:cache` and `php artisan route:cache` in production.
5. Ensure `storage/` and `bootstrap/cache/` are writable.
6. MySQL/PostgreSQL recommended over SQLite at scale.
7. Monitor slow queries on insights endpoints if submission volume grows.

---

*Phase 12.6 audit — read-only review completed; targeted fixes applied and verified with 293 tests passing (919 assertions).*
