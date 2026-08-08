<?php

namespace Tests\Unit;

use App\Models\Form;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormServiceTest extends TestCase
{
    use RefreshDatabase;

    private FormService $formService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formService = app(FormService::class);
    }

    public function test_schema_compilation_returns_sections_in_position_order(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $secondSection = $form->sections()->create([
            'title' => 'Second Section',
            'sort_order' => 2,
        ]);

        $firstSection = $form->sections()->create([
            'title' => 'First Section',
            'sort_order' => 1,
        ]);

        $form->fields()->create([
            'section_id' => $firstSection->id,
            'key' => 'first_field',
            'label' => 'First Field',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $form->fields()->create([
            'section_id' => $secondSection->id,
            'key' => 'second_field',
            'label' => 'Second Field',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $schema = $this->formService->compileSchema($form->fresh());

        $this->assertSame(['First Section', 'Second Section'], array_column($schema['sections'], 'title'));
        $this->assertSame($firstSection->id, $schema['sections'][0]['id']);
        $this->assertSame($secondSection->id, $schema['sections'][1]['id']);
    }

    public function test_schema_compilation_returns_fields_in_position_order(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $section = $form->sections()->create([
            'title' => 'Details',
            'sort_order' => 0,
        ]);

        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'last_field',
            'label' => 'Last Field',
            'type' => 'text',
            'sort_order' => 3,
        ]);

        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'first_field',
            'label' => 'First Field',
            'type' => 'text',
            'sort_order' => 1,
        ]);

        $schema = $this->formService->compileSchema($form->fresh());

        $fieldKeys = array_column($schema['sections'][0]['fields'], 'key');

        $this->assertSame(['first_field', 'last_field'], $fieldKeys);
    }

    public function test_field_key_is_preserved_in_compiled_schema(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $section = $form->sections()->create([
            'title' => 'Contact',
            'sort_order' => 0,
        ]);

        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'email_address',
            'label' => 'Email Address',
            'type' => 'email',
            'sort_order' => 0,
            'is_required' => true,
            'config' => ['placeholder' => 'you@example.com'],
            'validation' => ['format' => 'email'],
        ]);

        $schema = $this->formService->compileSchema($form->fresh());
        $field = $schema['sections'][0]['fields'][0];

        $this->assertSame('email_address', $field['key']);
        $this->assertSame('email', $field['type']);
        $this->assertTrue($field['required']);
        $this->assertSame(['placeholder' => 'you@example.com'], $field['config']);
        $this->assertSame(['format' => 'email'], $field['validation']);
    }
}
