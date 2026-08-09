# Known Limitations

This document consolidates intentional design decisions, assignment-scale trade-offs, and future production improvements from Phases 12.1–12.7.

The project is **feature-complete for evaluation** — limitations below are documented honestly, not as blockers.

---

## Intentional design decisions

| Decision | Rationale |
|----------|-----------|
| Mock AI provider as default | Deterministic tests; no real LLM required for assignment |
| Explicit AI apply | Owner reviews before structure replacement |
| Explicit import commit | Same staged workflow as AI |
| Published schema as validation source | Draft edits must not change public contract |
| Single-owner authorization | Assignment scope; no teams/roles |
| Session auth for API | Breeze stack; no Sanctum/token layer |
| Schema snapshot on submissions | Historical integrity when form changes |
| No auto-publish on AI/import | Owner controls go-live |

---

## Assignment-scale trade-offs

| Limitation | Impact | Future improvement |
|------------|--------|-------------------|
| Livewire AI polling (~2s) | Not real-time push | WebSockets or SSE |
| No server-side AI cancel | Jobs run to completion/failure | Cancel endpoint + job interruption |
| Import files loaded in memory | Fine for 5 MB limit | Streaming parser |
| Checkbox insights loads rows into PHP | Fine for moderate volume | DB-native JSON aggregation |
| No response caching | Simpler correctness | Cache compiled schema with invalidation |
| Builder reloads full structure after mutations | Extra queries | Partial Livewire updates |
| `QUEUE_CONNECTION=sync` in tests | Not production-like | Use database queue in CI |
| Form ID enumeration returns 403 | Information leak minor | Return 404 for non-owner |
| Parallel tests require ParaTest | Not installed | Optional dev dependency |

---

## Not implemented (by design or scope)

| Item | Status |
|------|--------|
| Real LLM / OpenAI integration | Gemini provider available opt-in; mock default |
| Dedicated `phone` field type | Phone captured as `text` (e.g. `phone_number` key) |
| `file` upload field type | Not implemented |
| Field helper text / default value UI | Placeholder supported; helper/default not implemented |
| Regex validation for text fields | Not implemented (`min_length`/`max_length` only) |
| CSV / JSON file import | DOCX and XLSX only |
| Submission search | Not implemented (insights aggregate only) |
| CSV submission export | Not implemented |
| WebSocket AI job updates | Polling only |
| Multi-tenant / teams | Single owner per form |
| Sanctum / API tokens | Session auth |
| Denormalized insights table | Runtime aggregation |
| AI job cancellation API | Not built |

---

## Deferred to Phase 12.9

| Item | Notes |
|------|-------|
| Live demo URL | Will be deployed and documented in README |
| Production hosting configuration | Platform TBD |
| Production environment variables | Real `APP_URL`, queue worker, etc. |
| Demo account (if required by assignment) | Needs manual verification against PDF |

---

## Fixed in recent phases (not limitations)

These were addressed and should **not** be listed as open issues:

- Security/authorization hardening (Phase 12.1)
- API response consistency (Phase 12.2)
- Public form UX (Phase 12.3)
- Builder UX polish (Phase 12.4)
- AI queue hardening, apply idempotency (Phase 12.5)
- Insights N+1 batching, performance indexes (Phase 12.6)
- Regression test coverage (Phase 12.7)

---

## Security caveats (honest)

Security controls were implemented and covered by regression tests. Known caveats:

1. Public forms are intentionally unauthenticated
2. Form ID may return 403 instead of 404 for non-owners
3. Models use `$fillable` — relies on service-layer discipline
4. Rate limits are per-IP/user — not a full DDoS solution

See [security.md](security.md) for full details.
