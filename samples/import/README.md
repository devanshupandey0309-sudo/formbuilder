# Import sample files

Use these files to test DOCX/XLSX import locally or via API:

| File | Description |
|------|-------------|
| `employee-registration.xlsx` | Spreadsheet with Personal Information and Employment Details sections |
| `employee-registration.docx` | Word document with a heading table for the same field set |

Upload via `POST /api/forms/{form}/imports` (authenticated) or through the builder import flow.

Regenerate with:

```bash
php scripts/generate-import-samples.php
```
