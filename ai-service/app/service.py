import copy

from app.models import FormDefinition, GenerateFormRequest
from app.prompts import DEFAULT_FORM
from app.providers import get_provider


def generate_form(request: GenerateFormRequest) -> dict:
    provider = get_provider()
    operation = "edit" if request.operation in {"edit"} else "generate"

    if operation == "edit" and request.current_schema:
        return provider.edit_form(request.prompt, request.current_schema)

    return provider.generate_form(request.prompt)


def validate_form_definition(payload: dict) -> dict:
    validated = FormDefinition.model_validate(payload)
    return validated.model_dump()
