from fastapi import FastAPI, HTTPException

from app.models import GenerateFormRequest
from app.service import generate_form, validate_form_definition

app = FastAPI(title="AI Form Builder Service", version="1.0.0")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/generate-form")
def generate_form_endpoint(request: GenerateFormRequest) -> dict:
    try:
        payload = generate_form(request)
        return validate_form_definition(payload)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    except Exception as exc:
        raise HTTPException(status_code=503, detail="AI service unavailable.") from exc
