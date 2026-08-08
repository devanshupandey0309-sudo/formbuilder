from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def test_health_endpoint():
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


def test_generate_form_from_scratch():
    response = client.post(
        "/generate-form",
        json={
            "prompt": "Create an employee onboarding form",
            "current_schema": None,
            "operation": "create",
        },
    )

    assert response.status_code == 200
    payload = response.json()
    assert payload["title"] == "Employee Onboarding Form"
    assert len(payload["sections"]) >= 1
    assert payload["sections"][0]["fields"][0]["key"] == "full_name"


def test_edit_form_adds_emergency_contact_section():
    base_schema = {
        "title": "Employee Form",
        "description": None,
        "sections": [
            {
                "title": "Personal",
                "description": None,
                "fields": [
                    {
                        "key": "phone",
                        "label": "Phone Number",
                        "type": "text",
                        "required": False,
                        "config": {},
                    }
                ],
            }
        ],
    }

    response = client.post(
        "/generate-form",
        json={
            "prompt": "Add an emergency contact section",
            "current_schema": base_schema,
            "operation": "edit",
        },
    )

    assert response.status_code == 200
    payload = response.json()
    assert any(section["title"] == "Emergency Contact" for section in payload["sections"])


def test_edit_form_makes_phone_required():
    base_schema = {
        "title": "Employee Form",
        "description": None,
        "sections": [
            {
                "title": "Personal",
                "description": None,
                "fields": [
                    {
                        "key": "phone",
                        "label": "Phone Number",
                        "type": "text",
                        "required": False,
                        "config": {},
                    }
                ],
            }
        ],
    }

    response = client.post(
        "/generate-form",
        json={
            "prompt": "Make phone number required",
            "current_schema": base_schema,
            "operation": "edit",
        },
    )

    assert response.status_code == 200
    phone_field = response.json()["sections"][0]["fields"][0]
    assert phone_field["required"] is True
