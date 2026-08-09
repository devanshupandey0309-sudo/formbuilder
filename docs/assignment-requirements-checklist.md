# Assignment Requirements Checklist

Maps documented assignment capabilities to implementation, tests, and documentation.

**Note:** The original assignment PDF is not stored in this repository. Requirements below are derived from implemented phases, `docs/decisions.md`, and development session specifications. Items not directly verifiable from the repo are marked **Needs manual verification against assignment PDF**.

| Requirement | Implementation | Tests | Manual verification | Status |
|-------------|----------------|-------|---------------------|--------|
| Laravel 11 application | `composer.lock` → v11.55.0 | Full suite | `php artisan --version` | Complete |
| PHP 8.3 | `composer.json` ^8.3 | Full suite | `php -v` | Complete |
| MySQL database | Migrations, Eloquent | Feature tests | DB connection | Complete |
| Livewire UI | `app/Livewire/Forms/*` | `FormBuilderTest`, `FormBuilderUxTest` | Builder walkthrough | Complete |
| User authentication | Laravel Breeze | `AuthenticationTest`, etc. | Register/login | Complete |
| Form CRUD | `FormController`, `FormService` | `FormCrudTest` | Create/edit/delete form | Complete |
| Section management | `SectionController`, `SectionService` | `SectionManagementTest` | Add/edit/delete/reorder | Complete |
| Field management | `FieldController`, `FieldService` | `FieldManagementTest` | Add/edit/delete/reorder/duplicate | Complete |
| Supported field types (8) | `FieldService::SUPPORTED_TYPES` | Public + field tests | Render each type | Complete |
| Schema compilation | `FormService::compileSchema()` | `FormServiceTest` | JSON tab matches builder | Complete |
| Publish / unpublish | `FormService::publishForm/unpublishForm` | `FormCrudTest`, builder regression | Publish flow | Complete |
| Republish after edit | Clears schema on structure change | `FormBuilderRegressionTest` | Edit published form | Complete |
| Public form by slug | `/f/{slug}`, public API | `PublicFormWebTest`, `PublicFormSubmissionTest` | Open public URL | Complete |
| Public submissions | `SubmissionService` | 18 submission tests | Submit form | Complete |
| Schema-driven validation | Published `forms.schema` | Submission tests | Invalid data rejected | Complete |
| Schema snapshot on submit | `submissions.schema_snapshot` | Submission tests | DB inspection | Complete |
| Unknown field rejection | `SubmissionService` | Submission tests | Inject extra field | Complete |
| Draft autosave | `FormDraftAutosaveService` | `FormDraftAutosaveTest` | Autosave indicator | Complete |
| Revision conflict handling | `draft_revision` | Autosave + regression tests | Two-tab conflict | Complete |
| Browser draft recovery | localStorage + Livewire | Partial (server-side) | Refresh mid-edit | Complete |
| JSON editor sync | `FormBuilder` JSON tab | `FormBuilderTest` | Apply JSON | Complete |
| AI natural-language generation | `AIFormController@generate` | `AIFormGenerationTest` | AI panel generate | Complete |
| AI edit existing form | `AIFormController@edit` | `AIFormEditTest` | Edit prompt | Complete |
| Async AI (202 + queue) | `GenerateAIFormJob` | `AIFormAsyncTest` | Queue worker required | Complete |
| AI output validation | `AIOutputValidator` | Unit + feature tests | Invalid type fails | Complete |
| Explicit AI apply | `AIFormApplyService` | AI tests + hardening | Apply button | Complete |
| AI job lifecycle tracking | `AIJob` model | `AIJobHardeningTest` | Poll job status | Complete |
| Provider abstraction | `AIProvider` contract | Mock + HTTP tests | `AI_PROVIDER_DRIVER` | Complete |
| Mock AI provider (default) | `MockAIProvider` | All AI tests | Works without FastAPI | Complete |
| FastAPI sidecar | `ai-service/` | FastAPI pytest (optional) | Run uvicorn | Complete |
| HTTP AI provider | `HttpAIProvider` | `AIFormAsyncTest` | Set driver=http | Complete |
| DOCX import | `DocxFormParser` | `FormImportTest` | Upload .docx | Complete |
| XLSX import | `XlsxFormParser` | `FormImportTest` | Upload .xlsx | Complete |
| Import preview before commit | Staged workflow | `FormImportTest` | Preview endpoint | Complete |
| Import explicit commit | `FormImportService::commit` | `FormImportTest` | Commit endpoint | Complete |
| REST API | 28 routes in `routes/api.php` | API tests | `route:list --path=api` | Complete |
| API response envelope | `ApiResponse`, `bootstrap/app.php` | `ApiResponseConsistencyTest` | Error shape | Complete |
| Authorization (single owner) | `FormPolicy` | `AuthorizationTest` | Cross-user access | Complete |
| Scoped nested binding | `scopeBindings()` | `AuthorizationTest` | Cross-form IDs | Complete |
| Rate limiting | `bootstrap/app.php` | `ApiRateLimitRegressionTest` | Rapid requests | Complete |
| Form Health Score | `FormHealthService` | `FormHealthServiceTest`, `FormHealthTest` | Health panel | Complete |
| Submission Insights | `SubmissionInsightService` | Unit + feature tests | Insights page | Complete |
| Drag-and-drop reorder | SortableJS + Livewire | Builder tests | Reorder in UI | Complete |
| Automated test suite | `tests/` (349 tests) | `php artisan test` | Run full suite | Complete |
| Production-safe error handling | Exception handlers | `ApiResponseConsistencyTest` | APP_DEBUG=false | Complete |
| No secrets in repository | `.env.example` placeholders | `ProductionSafetyTest` | Code review | Complete |
| README / documentation | `README.md`, `docs/*` | N/A | Read docs | Complete (Phase 12.8) |
| Gemini AI provider (opt-in) | `GeminiAIProvider` | `GeminiAIProviderTest` | Set `AI_PROVIDER_DRIVER=gemini` | Complete |
| Import sample files | `samples/import/` | `FormImportTest` + fixtures | Upload sample files | Complete |
| Dedicated phone field type | Not implemented | N/A | Use `text` for phone | **Gap** — text alias used |
| File upload field type | Not implemented | N/A | N/A | **Not implemented** |
| Submission search | Not implemented | N/A | N/A | **Not implemented** |
| CSV submission export | Not implemented | N/A | N/A | **Not implemented** |
| JSON file import | Not implemented | N/A | N/A | **Not implemented** (JSON editor only) |
| Real LLM integration | Not required | N/A | N/A | **Not implemented** (mock/HTTP architecture) |
| Live demo URL | Deferred | N/A | Public URL | **Deferred to Phase 12.9** |
| Deployment | Deferred | N/A | Hosted app | **Deferred to Phase 12.9** |

## Needs manual verification against assignment PDF

The following should be confirmed against the original assignment document:

- Exact field type list matches specification
- Import format requirements (DOCX/XLSX structure details)
- Any UI/UX requirements beyond implemented builder
- Specific API endpoint naming conventions
- Deployment platform requirements
- Demo account credentials requirement
- Any team/role requirements (implementation uses single-owner model)

## Documentation cross-reference

| Topic | Document |
|-------|----------|
| Architecture | [architecture.md](architecture.md) |
| API | [api.md](api.md) |
| Security | [security.md](security.md) |
| AI | [ai-architecture.md](ai-architecture.md) |
| Performance | [performance.md](performance.md) |
| Local setup | [local-development.md](local-development.md) |
| Manual checklist | [evaluator-checklist.md](evaluator-checklist.md) |
| Limitations | [known-limitations.md](known-limitations.md) |
| ADRs | [decisions.md](decisions.md) |
