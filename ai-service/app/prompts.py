DEFAULT_FORM = {
    "title": "Employee Onboarding Form",
    "description": "Employee onboarding information",
    "sections": [
        {
            "title": "Personal Information",
            "description": None,
            "fields": [
                {
                    "key": "full_name",
                    "label": "Full Name",
                    "type": "text",
                    "required": True,
                    "config": {"placeholder": "Enter your full name"},
                },
                {
                    "key": "email",
                    "label": "Email",
                    "type": "email",
                    "required": True,
                    "config": {},
                },
                {
                    "key": "phone",
                    "label": "Phone Number",
                    "type": "text",
                    "required": False,
                    "config": {},
                },
            ],
        },
        {
            "title": "Employment Details",
            "description": "Department and start date",
            "fields": [
                {
                    "key": "department",
                    "label": "Department",
                    "type": "select",
                    "required": True,
                    "config": {"options": ["Engineering", "Sales", "HR"]},
                },
                {
                    "key": "joining_date",
                    "label": "Joining Date",
                    "type": "date",
                    "required": True,
                    "config": {},
                },
            ],
        },
    ],
}
