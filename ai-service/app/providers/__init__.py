from abc import ABC, abstractmethod
import copy
import os

from app.prompts import DEFAULT_FORM


class BaseProvider(ABC):
    @abstractmethod
    def generate_form(self, prompt: str) -> dict:
        raise NotImplementedError

    @abstractmethod
    def edit_form(self, prompt: str, current_schema: dict) -> dict:
        raise NotImplementedError


class MockProvider(BaseProvider):
    def generate_form(self, prompt: str) -> dict:
        if not prompt.strip():
            raise ValueError("Prompt cannot be empty.")

        return copy.deepcopy(DEFAULT_FORM)

    def edit_form(self, prompt: str, current_schema: dict) -> dict:
        schema = copy.deepcopy(current_schema)
        lowered = prompt.lower()

        if "emergency contact" in lowered:
            schema.setdefault("sections", []).append(
                {
                    "title": "Emergency Contact",
                    "description": None,
                    "fields": [
                        {
                            "key": "emergency_contact_name",
                            "label": "Emergency Contact Name",
                            "type": "text",
                            "required": True,
                            "config": {},
                        },
                        {
                            "key": "emergency_contact_phone",
                            "label": "Emergency Contact Phone",
                            "type": "text",
                            "required": True,
                            "config": {},
                        },
                    ],
                }
            )

        if "phone" in lowered and "required" in lowered:
            for section in schema.get("sections", []):
                for field in section.get("fields", []):
                    key = field.get("key", "")
                    label = field.get("label", "").lower()
                    if key == "phone" or "phone" in label:
                        field["required"] = True

        if "date of birth" in lowered:
            schema["sections"][0]["fields"].append(
                {
                    "key": "date_of_birth",
                    "label": "Date of Birth",
                    "type": "date",
                    "required": True,
                    "config": {},
                }
            )

        if "consent" in lowered:
            schema.setdefault("sections", []).append(
                {
                    "title": "Consent",
                    "description": None,
                    "fields": [
                        {
                            "key": "consent",
                            "label": "I agree to the terms",
                            "type": "checkbox",
                            "required": True,
                            "config": {"options": ["I agree"]},
                        }
                    ],
                }
            )

        return schema


def get_provider() -> BaseProvider:
    driver = os.getenv("AI_PROVIDER", "mock").lower()

    if driver == "mock":
        return MockProvider()

    return MockProvider()
