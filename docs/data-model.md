# Data Model

This document reflects the **actual** database schema from migration files in `database/migrations/`.

## Entity relationship overview

```
users
  ├── forms (1:N)
  │     ├── sections (1:N)
  │     │     └── fields (1:N)
  │     ├── fields (1:N, denormalized form_id)
  │     ├── submissions (1:N)
  │     │     └── submission_answers (1:N)
  │     ├── ai_jobs (1:N)
  │     └── form_imports (1:N)
  └── ai_jobs (1:N, user_id)
```

## users

Standard Laravel Breeze auth table (`0001_01_01_000000_create_users_table.php`).

| Column | Notes |
|--------|-------|
| id | Primary key |
| name, email, password | Auth credentials |
| email_verified_at | Required for builder routes (`verified` middleware) |

## forms

Migration: `2026_08_08_064452_create_forms_table.php`, `2026_08_08_200000_add_draft_tracking_to_forms_table.php`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK → users | cascade delete |
| title | string | |
| slug | string | **unique** — public URL identifier |
| description | text nullable | |
| status | string | default `draft`; `published` when live |
| schema | json nullable | **published compiled schema snapshot** |
| settings | json nullable | |
| version | unsigned int | incremented on publish |
| draft_revision | unsigned int | autosave optimistic locking |
| draft_saved_at | timestamp nullable | last successful autosave |
| published_at | timestamp nullable | |
| deleted_at | timestamp | soft deletes |
| timestamps | | |

**Indexes:**
- unique `slug`
- `(user_id, status)`
- `(user_id, updated_at)`

**Relationships:** `user`, `sections`, `fields`, `submissions`, `aiJobs`, `formImports`

## sections

Migration: `2026_08_08_064453_create_sections_table.php`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| form_id | FK → forms | cascade delete |
| title | string | |
| description | text nullable | |
| sort_order | unsigned int | default 0; ordering within form |
| settings | json nullable | |
| timestamps | | |

**Indexes:** `(form_id, sort_order)`

## fields

Migration: `2026_08_08_064454_create_fields_table.php`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| form_id | FK → forms | cascade delete |
| section_id | FK → sections | cascade delete |
| key | string | stable identifier for answers |
| label | string | |
| type | string | see `FieldService::SUPPORTED_TYPES` |
| sort_order | unsigned int | ordering within section |
| config | json nullable | placeholder, options, etc. |
| validation | json nullable | |
| is_required | boolean | default false |
| timestamps | | |

**Indexes / constraints:**
- unique `(form_id, key)` — globally unique keys per form
- `(section_id, sort_order)`
- `(form_id, type)`

## submissions

Migration: `2026_08_08_064455_create_submissions_table.php`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| form_id | FK → forms | |
| form_version | unsigned int | version at submit time |
| schema_snapshot | json nullable | exact published schema used |
| status | string | default `completed` |
| ip_address | string nullable | |
| user_agent | text nullable | |
| metadata | json nullable | |
| submitted_at | timestamp nullable | |
| timestamps | | |

**Indexes:**
- `(form_id, submitted_at)` — insights trend/overview queries
- `(form_id, form_version)`

## submission_answers

Migration: `2026_08_08_064456_create_submission_answers_table.php`, `2026_08_08_210000_add_performance_indexes.php`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| submission_id | FK → submissions | cascade delete |
| field_id | FK → fields nullable | nullOnDelete — survives field deletion |
| field_key | string | stable answer key |
| field_label | string nullable | snapshot label |
| value_text | text nullable | scalar values |
| value_json | json nullable | checkbox arrays |
| timestamps | | |

**Indexes / constraints:**
- unique `(submission_id, field_key)`
- `field_id`
- **`field_key`** — added Phase 12.6 for insights aggregation joins

## ai_jobs

Migration: `2026_08_08_064457_create_ai_jobs_table.php`, `2026_08_08_170000_add_applied_at_to_ai_jobs_table.php`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK → users | cascade delete |
| form_id | FK → forms nullable | nullOnDelete |
| type | string | `generate` or `edit` |
| status | string | `pending`, `processing`, `completed`, `failed` |
| prompt | text | |
| input | json nullable | edit context, etc. |
| raw_output | json nullable | provider response |
| validated_output | json nullable | validated proposed schema |
| error_message | text nullable | |
| attempt_count | unsigned int | |
| max_attempts | unsigned int | default 3 |
| laravel_job_id | string nullable | |
| started_at | timestamp nullable | |
| completed_at | timestamp nullable | |
| **applied_at** | timestamp nullable | set on successful apply (Phase 12.5) |
| timestamps | | |

**Indexes:**
- `(user_id, status)`
- `(form_id, type, status)`

Note: `applied_at` is not indexed — not used in WHERE clauses.

## form_imports

Migration: `2026_08_08_064458_create_form_imports_table.php`, `2026_08_08_210000_add_performance_indexes.php`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK → users | |
| form_id | FK → forms nullable | |
| source_type | string | `docx` or `xlsx` |
| original_filename | string | |
| file_path | string | **not exposed via API** |
| status | string | `pending`, `processing`, `preview_ready`, `committed`, `failed` |
| detected_structure | json nullable | |
| field_candidates | json nullable | |
| ambiguities | json nullable | |
| mapping | json nullable | |
| preview_data | json nullable | validated preview |
| error_message | text nullable | |
| ai_job_id | FK nullable | |
| timestamps | | |

**Indexes:**
- `(user_id, status)`
- **`(form_id, status)`** — added Phase 12.6

## Laravel infrastructure tables

- `jobs`, `cache`, `sessions` — standard Laravel 11 tables
- Queue driver default: `database` (see `.env.example`)

## Phase 12.6 performance indexes

Migration `2026_08_08_210000_add_performance_indexes.php`:

1. `submission_answers.field_key` — speeds insights field-key aggregation
2. `form_imports (form_id, status)` — speeds form-scoped import queries

See [performance.md](performance.md) for query optimization details.
