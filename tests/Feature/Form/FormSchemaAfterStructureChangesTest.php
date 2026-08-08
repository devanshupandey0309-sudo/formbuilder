<?php

namespace Tests\Feature\Form;

use App\Models\Form;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormSchemaAfterStructureChangesTest extends TestCase
{
    use RefreshDatabase;

    private FormService $formService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formService = app(FormService::class);
    }

    public function test_compiled_schema_reflects_section_order(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $second = $form->sections()->create(['title' => 'Second', 'sort_order' => 2]);
        $first = $form->sections()->create(['title' => 'First', 'sort_order' => 1]);

        $this->seedField($form, $second, 'second_field');
        $this->seedField($form, $first, 'first_field');

        $schema = $this->formService->compileSchema($form->fresh());

        $this->assertSame(['First', 'Second'], array_column($schema['sections'], 'title'));
    }

    public function test_compiled_schema_reflects_field_order(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);

        $this->seedField($form, $section, 'last_field', 3);
        $this->seedField($form, $section, 'first_field', 1);

        $schema = $this->formService->compileSchema($form->fresh());

        $this->assertSame(['first_field', 'last_field'], array_column($schema['sections'][0]['fields'], 'key'));
    }

    public function test_compiled_schema_contains_field_metadata(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Contact', 'sort_order' => 0]);

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
        $this->assertSame('Email Address', $field['label']);
        $this->assertTrue($field['required']);
        $this->assertSame(['placeholder' => 'you@example.com'], $field['config']);
        $this->assertSame(['format' => 'email'], $field['validation']);
    }

    public function test_section_or_field_modification_clears_cached_schema(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create([
            'schema' => ['version' => 1, 'sections' => []],
        ]);
        $section = $form->sections()->create(['title' => 'Details', 'sort_order' => 0]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/sections/{$section->id}/fields", [
            'key' => 'name',
            'type' => 'text',
            'label' => 'Name',
        ])->assertCreated();

        $this->assertNull($form->fresh()->schema);
    }

    private function seedField(Form $form, $section, string $key, int $sortOrder = 0): void
    {
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'type' => 'text',
            'sort_order' => $sortOrder,
        ]);
    }
}
