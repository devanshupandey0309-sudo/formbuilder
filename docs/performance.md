# Performance

This document summarizes Phase 12.6 query/index/performance findings. See also [phase-12.6-performance-audit.md](phase-12.6-performance-audit.md).

## Design philosophy

Optimizations are **targeted** for assignment scale. No speculative caching layer was added — correctness (especially published schema consistency) takes priority over premature optimization.

## Database indexing

Existing indexes from domain migrations plus Phase 12.6 additions:

| Table | Index | Purpose |
|-------|-------|---------|
| forms | unique `slug` | Public lookup |
| forms | `(user_id, status)` | Owner form listing |
| sections | `(form_id, sort_order)` | Ordered section load |
| fields | unique `(form_id, key)` | Key uniqueness |
| fields | `(section_id, sort_order)` | Ordered field load |
| submissions | `(form_id, submitted_at)` | Insights date queries |
| submission_answers | `field_key` | **Phase 12.6** — insights aggregation |
| form_imports | `(form_id, status)` | **Phase 12.6** — form-scoped imports |
| ai_jobs | `(form_id, type, status)` | Job listing/filtering |

## Eager loading

- `FormBuilder::loadForm()` eager-loads ordered `sections.fields`
- `FormHealthService` eager-loads structure for analysis
- Form index API returns forms without nested relations (by design)

## Insights batching (Phase 12.6)

Before: one SQL query per select/radio/checkbox/number field.

After: batched aggregations in `SubmissionInsightService`:

- All select/radio distributions → **1 grouped query**
- All numeric summaries → **1 grouped query**
- Checkbox distributions → **1 fetch + PHP aggregation** (same counts as prior per-option queries)

Output format unchanged. Verified by unit and regression tests.

## N+1 avoidance

| Path | Status |
|------|--------|
| Builder mount | Eager load — OK |
| Public form | Single form row + JSON schema — OK |
| Insights | Batched — fixed Phase 12.6 |
| AI polling | Single PK lookup — OK |
| Submission write | One batched field lookup — OK |

## Published schema lookup

`SubmissionService::getPublishedFormBySlug()` filters `status = published` in SQL (uses unique slug index).

## Relation reuse

`FormService::ensureFormStructureLoaded()` reuses already-loaded section/field relations when compiling schema, avoiding redundant queries when structure is in memory.

## Queue-based AI

AI generation is asynchronous — HTTP requests return 202 immediately. Provider latency does not block web workers.

## Client polling

Livewire builder polls AI job status every ~2 seconds while pending/processing. Acceptable for assignment/demo scale; WebSockets not implemented.

## Why caching was not added

- Published schema must stay consistent with republish workflow
- Insights are computed from live submission data
- Assignment scale does not justify cache invalidation complexity
- No `Cache::` usage in application code

## Known scale limitations (assignment trade-offs)

| Limitation | Notes |
|------------|-------|
| Import files loaded in memory | Acceptable for 5 MB limit |
| Checkbox analytics loads answer rows into PHP | Fine for hundreds/thousands of submissions |
| Livewire AI polling | 2s interval; not push-based |
| No response caching | Intentional |
| No WebSocket AI updates | Polling only |
| Builder reloads full structure after mutations | Correct for Livewire freshness |

These are documented trade-offs, not defects.

## Production recommendations (Phase 12.9 prep)

1. Run `php artisan queue:work` under supervisor
2. Use `QUEUE_CONNECTION=database` or Redis — not `sync`
3. Set `APP_URL` to public domain
4. Run `php artisan config:cache` and `route:cache`
5. MySQL/PostgreSQL recommended over SQLite at scale
6. Monitor slow queries on insights if submission volume grows

## Test coverage

`tests/Feature/Performance/QueryPerformanceTest.php` — bounded query-count regression tests.

Current suite: **293 tests, 919 assertions** (includes performance and regression tests).
