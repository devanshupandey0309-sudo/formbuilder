# Evaluator Checklist

Practical manual verification checklist. Complete after [local-development.md](local-development.md) setup.

## Setup verification

- [ ] `composer install` succeeds
- [ ] `npm install` succeeds
- [ ] `.env` configured with database
- [ ] `php artisan migrate:fresh --seed` succeeds
- [ ] `php artisan serve` running
- [ ] `php artisan queue:work` running (for AI)
- [ ] `npm run build` or `npm run dev` running
- [ ] `php artisan test` — 349 tests pass

---

## A. Authentication

- [ ] Register new account
- [ ] Login
- [ ] Access `/forms` when authenticated
- [ ] Logout
- [ ] Protected routes redirect/deny unauthenticated users
- [ ] Unauthenticated API returns 401 JSON envelope

---

## B. Form builder

- [ ] Create form from `/forms`
- [ ] Open builder at `/forms/{id}/builder`
- [ ] Add section
- [ ] Add fields (text, email, select, etc.)
- [ ] Edit field label, key, type, required flag
- [ ] Email field shows email format / length validation controls
- [ ] Date field shows date format / min-max date controls
- [ ] Number field shows min/max controls
- [ ] Text/textarea fields show min/max length controls
- [ ] Changing field type clears incompatible validation rules
- [ ] Saved validation metadata persists after reload
- [ ] Reorder sections (drag-and-drop)
- [ ] Reorder fields within section
- [ ] Duplicate field (unique key generated)
- [ ] Delete field
- [ ] Edit section description
- [ ] Observe autosave indicator (Saving… / Saved)
- [ ] Trigger autosave conflict (two tabs) → Reload from server works
- [ ] JSON tab shows compiled schema
- [ ] Apply valid JSON updates structure
- [ ] Invalid JSON rejected without mutation

---

## C. Publishing

- [ ] Publish form from builder
- [ ] Public URL appears in builder
- [ ] Open public form at `/f/{slug}`
- [ ] Edit published form structure
- [ ] Republish warning/banner appears
- [ ] Republish restores live schema
- [ ] Unpublish returns form to draft
- [ ] Public URL returns 404 after unpublish

---

## D. Public form

- [ ] Published form renders all field types
- [ ] Section descriptions visible
- [ ] Required field validation works
- [ ] Invalid email/number/date rejected
- [ ] Email min/max length and date min/max range enforced when configured
- [ ] Number min/max and text length rules enforced when configured
- [ ] Invalid select/radio option rejected
- [ ] Unknown field injection rejected
- [ ] Successful submission shows success state
- [ ] Submit button hidden/disabled after success
- [ ] Submission appears in insights

---

## E. AI generation

- [ ] Open AI panel in builder
- [ ] Submit generate prompt (explicit fields example: `create a form with fields employee name, email, phone number, date of birth`)
- [ ] Customer registration prompt returns only name, email, phone, country, age (no employee onboarding fields)
- [ ] Employee onboarding explicit prompt returns only requested onboarding fields
- [ ] Generated proposal contains only requested fields (no unrelated Department/Joining Date unless requested)
- [ ] Applied email/date fields include validation metadata; form health does not warn for missing email/date validation
- [ ] Job shows pending/processing state
- [ ] Queue worker processes job → completed
- [ ] Review generated structure preview
- [ ] Apply generated form updates draft structure
- [ ] Duplicate apply rejected
- [ ] Failed job shows error; form unchanged
- [ ] Edit prompt modifies existing schema proposal

---

## F. Import

- [ ] Upload valid XLSX file via API or builder
- [ ] Import reaches `preview_ready`
- [ ] Preview shows validated structure
- [ ] Commit replaces form structure
- [ ] Form remains draft after commit
- [ ] Publish required before public access
- [ ] Malformed/unsupported file rejected
- [ ] `file_path` not visible in API response

---

## G. Security

- [ ] User A cannot access User B's form (403/404)
- [ ] Cross-form section/field IDs return 404
- [ ] Cross-form AI job returns 404
- [ ] Cross-form import returns 404 without data leak
- [ ] Draft/unpublished form not publicly accessible
- [ ] Public page does not expose owner email
- [ ] Health/insights denied for non-owner

---

## H. API error envelope

Verify JSON shape `{ success, message, data? }`:

- [ ] 401 — unauthenticated
- [ ] 403 — unauthorized (another user's form)
- [ ] 404 — unknown resource / scoped binding miss
- [ ] 422 — validation failure with `data.errors`
- [ ] 429 — rate limit (rapid AI or submit requests)

---

## I. Additional features

- [ ] Form Health panel shows score and recommendations
- [ ] Insights page shows overview, trend, field stats
- [ ] Owner preview at `/forms/{id}/preview`

---

## J. Optional Gemini provider

- [ ] Set `GEMINI_API_KEY` and `AI_PROVIDER_DRIVER=gemini` in `.env`
- [ ] Restart queue worker
- [ ] Run explicit-field prompts from section E
- [ ] Verify output passes validation and apply works
- [ ] Missing API key produces clear failed job (no silent mock fallback)

---

## Automated verification

```bash
php artisan test
npm run build
php artisan route:list --path=api   # expect 28 routes
```

Live demo URL will be provided after **Phase 12.9** deployment.
