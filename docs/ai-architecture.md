# AI Architecture

## Overview

AI form generation and editing convert natural-language prompts into structured form definitions compatible with the existing section/field architecture.

**Important:** the default assignment configuration uses a **deterministic mock provider**. A real LLM is **not** integrated or required. The HTTP provider + FastAPI sidecar architecture allows a future external AI service without changing Laravel domain logic.

## Request flow

```
1. Owner POST /api/forms/{form}/ai/generate|edit
2. AIFormGenerationService creates AIJob (status: pending)
3. GenerateAIFormJob dispatched to Laravel queue
4. HTTP 202 returned immediately (provider NOT called in controller)
5. Queue worker runs GenerateAIFormJob
6. AIFormGenerationService::processJob()
7. AIProvider generates raw output
8. AIResponseParser extracts JSON
9. AIOutputValidator validates/normalizes
10. AIJob → completed | failed
11. Client polls GET /api/forms/{form}/ai/jobs/{aiJob}
12. Owner POST .../apply to commit structure
```

## Components

| Component | Role |
|-----------|------|
| `AIFormController` | Queue endpoints, poll, apply |
| `AIFormGenerationService` | Job lifecycle, provider orchestration |
| `AIFormApplyService` | Idempotent apply to form structure |
| `GenerateAIFormJob` | Queue worker entry point; `ShouldBeUnique` |
| `AIProvider` contract | Provider abstraction |
| `MockAIProvider` | In-process deterministic provider (default) |
| `GeminiAIProvider` | Google Gemini structured JSON (opt-in; requires `GEMINI_API_KEY`) |
| `HttpAIProvider` | REST client to FastAPI sidecar |
| `FieldValidationRules` | Supported validation keys, normalization, submission enforcement |
| `AIResponseParser` | Extract JSON from raw/markdown/wrapped responses |
| `AIOutputValidator` | Schema validation (same rules as import/manual apply) |
| `AIPromptContract` | System prompt and output contract documentation |
| `FormStructureApplyService` | Transactional structure replacement |

## Provider drivers

Configured via `.env`:

```env
AI_PROVIDER_DRIVER=mock   # default for tests and local demos
AI_SERVICE_URL=http://127.0.0.1:8001
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
```

| Driver | Behavior |
|--------|----------|
| `mock` | In-process; no external service; deterministic output. When a prompt explicitly names fields (contains `field`/`fields`, `with` + multiple matches, or customer registration/employee onboarding phrases), returns only those mapped fields. Generic prompts still use the default onboarding fixture. |
| `gemini` | Calls Google Gemini `generateContent` server-side via Laravel HTTP client. Uses structured JSON output with `AIPromptContract::geminiResponseSchema()`. Requires `GEMINI_API_KEY`. Never exposed to frontend. Fails clearly if key missing — no silent fallback to mock. |
| `http` | Calls FastAPI `ai-service/` over REST |

FastAPI sidecar (`ai-service/`) has its own provider abstraction (mock by default). Laravel retains auth, validation, persistence, and apply.

## Why asynchronous?

AI generation must not block HTTP requests on provider latency. The assignment requires:

1. Create `ai_jobs` record
2. Dispatch queue job
3. Return **202 Accepted**
4. Process in background worker

`QUEUE_CONNECTION=sync` runs jobs inline (useful for tests) but is not recommended for production-like async behavior.

## AI job state machine

```
pending → processing → completed
                    ↘ failed
```

| Status | Meaning |
|--------|---------|
| pending | Queued; no provider call yet |
| processing | Worker owns job |
| completed | Validated output in `validated_output`; awaiting apply |
| failed | Terminal; safe error in `error_message` |

Terminal jobs (`completed`, `failed`) are not reprocessed. `GenerateAIFormJob` checks `isTerminal()` before processing.

## Retry classification

| Failure type | Behavior |
|--------------|----------|
| Transient network/infrastructure | `TransientAIServiceException` → retry (3 attempts, backoff 5/15/30s) |
| Validation failure | Immediate `failed`; no retry |
| FastAPI 422 | `PermanentAIServiceException`; no retry |
| Malformed/permanent provider output | Immediate `failed` |

## Apply idempotency

- Only `completed` jobs with `validated_output` can be applied
- `applied_at` timestamp set on first successful apply
- Duplicate apply rejected
- Apply sets form to draft, replaces sections/fields, clears stale schema
- Publish remains a separate explicit action

## Prompt contract

Defined in `App\Services\AI\AIPromptContract`:

- Single JSON object output
- Supported field types only: `text`, `textarea`, `number`, `email`, `date`, `select`, `radio`, `checkbox`
- snake_case globally unique field keys
- Edit operations return the **complete** updated schema
- Explicitly requested fields in a prompt take priority over generic fixture fields (mock provider only)
- Email and date fields receive default validation metadata during `AIOutputValidator` normalization (`format: email`, `format: Y-m-d`) when not supplied by the provider

### Supported validation metadata

| Field type | Supported `validation` keys |
|------------|----------------------------|
| `email` | `format` (`email`), `min_length`, `max_length` |
| `date` | `format` (`Y-m-d`), `min`, `max` (YYYY-MM-DD) |
| `number` | `min`, `max` |
| `text`, `textarea` | `min_length`, `max_length` |
| `select`, `radio`, `checkbox` | options in `config.options` (not arbitrary validation keys) |

`required` is a separate field property (`is_required`), not duplicated inside `validation`.

## Gemini provider

Configured when `AI_PROVIDER_DRIVER=gemini`:

1. Set `GEMINI_API_KEY` in `.env` (never commit real keys)
2. Optionally set `GEMINI_MODEL` (default: `gemini-2.5-flash`)
3. Restart queue worker

`GeminiAIProvider` builds a controlled instruction prompt via `AIPromptContract::instructionPrompt()` containing supported field types, validation rules, JSON shape, and prompt-fidelity rules (do not invent fields). Raw Gemini JSON is always validated by `AIOutputValidator` before the job completes.

Error handling:

| Condition | Result |
|-----------|--------|
| Missing API key | `PermanentAIServiceException` → job failed |
| HTTP 401/403 | Permanent failure |
| HTTP 429 / 5xx / timeout | `TransientAIServiceException` → retry |
| Malformed JSON / empty response | Permanent failure |

PHPUnit tests mock HTTP — no real Gemini calls in CI.

## Mock provider behavior (not a real LLM)

`MockAIProvider` is deterministic test/demo infrastructure — it does **not** interpret arbitrary natural language like a real LLM.

| Prompt style | Mock behavior |
|--------------|---------------|
| Explicit field list (prompt contains `field`/`fields`, `with` + multiple matches, or customer registration/employee onboarding phrases) | Returns only the matched fields with preserved labels (e.g. Employee Name, Date of Birth) |
| Generic onboarding prompt (no explicit field list) | Returns the default Employee Onboarding fixture (includes Employment Details) |
| Edit prompt with known keywords | Applies targeted edits to the current schema (`applyMockEdit`) |
| Tests needing custom output | `MockAIProvider::fake([...])` |

Do not treat mock prompt matching as evidence of real AI intelligence. A future HTTP/LLM provider would need its own prompt handling upstream; Laravel still validates all output through `AIOutputValidator` before apply.

## Hallucinated field types

**Strategy: reject.** Unsupported types (e.g. `phone`, `rating`) fail validation with a clear error. They are never silently mapped or persisted.

## JSON extraction

`AIResponseParser` handles:

- Raw JSON objects
- Markdown-fenced JSON blocks
- Wrapper keys (`content`, `generated_form`, etc.)
- Empty/malformed responses → validation failure

## Livewire integration

`FormBuilder` AI panel:

- Dispatches generate/edit via API
- Polls job status every ~2 seconds while pending/processing
- Shows Apply/Discard for completed jobs
- Loading/disabled states during operations

## Running the full AI stack locally

**Terminal 1 — queue worker (required for async):**

```bash
php artisan queue:work
```

**Terminal 2 — Laravel:**

```bash
php artisan serve
```

**Optional — FastAPI (when using HTTP provider):**

```bash
cd ai-service
python -m venv .venv
.venv\Scripts\activate    # Windows
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8001
```

Set `AI_PROVIDER_DRIVER=http` and `QUEUE_CONNECTION=database`.

## Test coverage

| File | Coverage |
|------|----------|
| `tests/Feature/AI/AIFormGenerationTest.php` | Validation, apply, ownership |
| `tests/Feature/AI/AIFormAsyncTest.php` | 202, queue dispatch, state transitions |
| `tests/Feature/AI/AIFormEditTest.php` | Edit with current schema context |
| `tests/Feature/AI/AIJobHardeningTest.php` | Terminal states, idempotency, parser edge cases |
| `tests/Unit/AI/AIResponseParserTest.php` | JSON extraction |
| `tests/Unit/AI/AIOutputValidatorTest.php` | Type rejection, normalization |
| `tests/Unit/AI/GeminiAIProviderTest.php` | HTTP-faked Gemini success/failure, prompt contract |
| `tests/Feature/Regression/AIPromptFidelityRegressionTest.php` | Explicit-field prompt fidelity (3 QA prompts) |
| `tests/Feature/Builder/FormBuilderValidationTest.php` | Dynamic validation UI persistence |
| `tests/Feature/PublicForm/PublicFormValidationMetadataTest.php` | Backend enforcement of validation metadata |

No tests make real external LLM calls.

See also: [architecture.md](architecture.md), [api.md](api.md), [local-development.md](local-development.md).
