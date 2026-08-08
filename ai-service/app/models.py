from pydantic import BaseModel, Field
from typing import Any, Literal


class GenerateFormRequest(BaseModel):
    prompt: str = Field(min_length=1)
    current_schema: dict[str, Any] | None = None
    operation: Literal["create", "edit", "generate"] = "create"


class FieldDefinition(BaseModel):
    key: str
    label: str
    type: str
    required: bool = False
    config: dict[str, Any] = Field(default_factory=dict)


class SectionDefinition(BaseModel):
    title: str
    description: str | None = None
    fields: list[FieldDefinition]


class FormDefinition(BaseModel):
    title: str
    description: str | None = None
    sections: list[SectionDefinition]
