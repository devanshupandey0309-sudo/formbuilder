# AI Form Builder FastAPI Service

Small Python service that generates structured form definitions for the Laravel application.

## Architecture

```
Laravel (auth, jobs, validation, persistence)
   |
   | REST POST /generate-form
   v
FastAPI (prompt handling, provider abstraction)
   |
   v
AI Provider (mock by default; real provider via env)
```

Laravel remains authoritative for authorization, schema validation (`AIOutputValidator`), job lifecycle (`ai_jobs`), and explicit apply.

## Run locally

```bash
cd ai-service
python -m venv .venv
.venv\Scripts\activate   # Windows
pip install -r requirements.txt
copy .env.example .env
uvicorn app.main:app --reload --port 8001
```

Health check: `GET http://127.0.0.1:8001/health`

## Endpoint

`POST /generate-form`

```json
{
  "prompt": "Create an employee onboarding form",
  "current_schema": null,
  "operation": "create"
}
```

Edit example:

```json
{
  "prompt": "Add an emergency contact section",
  "current_schema": { "...": "..." },
  "operation": "edit"
}
```

## Docker

```bash
docker build -t ai-form-builder-service .
docker run --rm -p 8001:8001 ai-form-builder-service
```

## Tests

```bash
pytest
```

## Environment

See `.env.example`. Do not commit real provider API keys.
