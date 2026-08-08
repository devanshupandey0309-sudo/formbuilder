<?php

namespace Tests\Feature\Import;

use App\Models\Form;
use App\Models\FormImport;
use App\Models\User;
use App\Services\FormImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\ImportFixtureBuilder;
use Tests\TestCase;

class FormImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_supported_xlsx_file(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $path = ImportFixtureBuilder::createXlsx([
            ['Section', 'Field', 'Type', 'Required', 'Options'],
            ['Personal', 'Full Name', 'text', 'yes', ''],
            ['Personal', 'Email', 'email', 'yes', ''],
        ]);

        $response = $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.form_import.status', 'preview_ready');

        @unlink($path);
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $form = Form::factory()->create();
        $path = ImportFixtureBuilder::createXlsx([
            ['Section', 'Field', 'Type', 'Required', 'Options'],
            ['Personal', 'Full Name', 'text', 'yes', ''],
        ]);

        $this->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ])->assertUnauthorized();

        @unlink($path);
    }

    public function test_another_users_form_is_rejected(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();
        $path = ImportFixtureBuilder::createXlsx([
            ['Section', 'Field', 'Type', 'Required', 'Options'],
            ['Personal', 'Full Name', 'text', 'yes', ''],
        ]);

        $this->actingAs($otherUser)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ])->assertForbidden();

        @unlink($path);
    }

    public function test_unsupported_file_is_rejected(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertUnprocessable();
    }

    public function test_invalid_mime_type_is_rejected(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => UploadedFile::fake()->create('employees.xlsx', 10, 'text/plain'),
        ])->assertUnprocessable();
    }

    public function test_oversized_file_is_rejected(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => UploadedFile::fake()->create(
                'employees.xlsx',
                FormImportService::MAX_FILE_SIZE_KB + 1,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ),
        ])->assertUnprocessable();
    }

    public function test_valid_xlsx_is_parsed_with_sections_and_fields(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $path = ImportFixtureBuilder::createXlsx([
            ['Section', 'Field', 'Type', 'Required', 'Options'],
            ['Personal', 'Full Name', 'text', 'yes', ''],
            ['Personal', 'Email', 'email', 'yes', ''],
            ['Employment', 'Department', 'select', 'no', 'HR,IT,Finance'],
        ]);

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ])->assertCreated();

        $import = FormImport::query()->first();

        $this->assertSame('preview_ready', $import->status);
        $this->assertCount(2, $import->preview_data['sections']);
        $this->assertSame('full_name', $import->preview_data['sections'][0]['fields'][0]['key']);
        $this->assertSame('department', $import->preview_data['sections'][1]['fields'][0]['key']);
        $this->assertSame(['HR', 'IT', 'Finance'], $import->preview_data['sections'][1]['fields'][0]['config']['options']);

        @unlink($path);
    }

    public function test_invalid_xlsx_field_type_fails_import(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $path = ImportFixtureBuilder::createXlsx([
            ['Section', 'Field', 'Type', 'Required', 'Options'],
            ['Personal', 'Upload', 'file_upload', 'no', ''],
        ]);

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ])->assertUnprocessable();

        $this->assertSame('failed', FormImport::query()->value('status'));

        @unlink($path);
    }

    public function test_malformed_xlsx_is_handled(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $path = ImportFixtureBuilder::createXlsx([
            ['Wrong', 'Headers', 'Here', 'Now', 'Bad'],
        ]);

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ])->assertUnprocessable();

        @unlink($path);
    }

    public function test_valid_docx_is_parsed(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $path = ImportFixtureBuilder::createDocx('Personal Information', [
            ['Field', 'Type', 'Required', 'Options'],
            ['Full Name', 'text', 'yes', ''],
            ['Email', 'email', 'yes', ''],
        ]);

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true,
            ),
        ])->assertCreated();

        $import = FormImport::query()->first();

        $this->assertSame('preview_ready', $import->status);
        $this->assertSame('Personal Information', $import->preview_data['sections'][0]['title']);
        $this->assertSame('email', $import->preview_data['sections'][0]['fields'][1]['key']);

        @unlink($path);
    }

    public function test_invalid_docx_is_handled(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $path = ImportFixtureBuilder::createDocx('Empty Section', [
            ['Field', 'Type', 'Required', 'Options'],
        ]);

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true,
            ),
        ])->assertUnprocessable();

        @unlink($path);
    }

    public function test_preview_does_not_modify_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['status' => 'published', 'schema' => ['version' => 1]]);
        $section = $form->sections()->create(['title' => 'Existing', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'existing_field',
            'label' => 'Existing Field',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $path = ImportFixtureBuilder::createXlsx([
            ['Section', 'Field', 'Type', 'Required', 'Options'],
            ['Personal', 'Full Name', 'text', 'yes', ''],
        ]);

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ]);

        $import = FormImport::query()->first();

        $this->actingAs($user)->getJson("/api/forms/{$form->id}/imports/{$import->id}/preview")
            ->assertOk();

        $this->assertDatabaseHas('fields', ['key' => 'existing_field']);
        $this->assertSame('published', $form->fresh()->status);

        @unlink($path);
    }

    public function test_failed_import_cannot_be_committed(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();

        $import = FormImport::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'source_type' => 'xlsx',
            'original_filename' => 'bad.xlsx',
            'file_path' => 'form-imports/missing.xlsx',
            'status' => 'failed',
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/imports/{$import->id}/commit")
            ->assertUnprocessable();
    }

    public function test_valid_import_can_be_committed(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create(['status' => 'published', 'schema' => ['version' => 1]]);
        $path = ImportFixtureBuilder::createXlsx([
            ['Section', 'Field', 'Type', 'Required', 'Options'],
            ['Personal', 'Full Name', 'text', 'yes', ''],
            ['Personal', 'Email', 'email', 'yes', ''],
        ]);

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ]);

        $import = FormImport::query()->first();

        $response = $this->actingAs($user)->postJson("/api/forms/{$form->id}/imports/{$import->id}/commit");

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $form->refresh();
        $import->refresh();

        $this->assertSame('draft', $form->status);
        $this->assertNull($form->schema);
        $this->assertSame('committed', $import->status);
        $this->assertDatabaseHas('fields', ['key' => 'full_name']);
        $this->assertDatabaseHas('fields', ['key' => 'email']);

        @unlink($path);
    }

    public function test_commit_is_transactional(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $section = $form->sections()->create(['title' => 'Existing', 'sort_order' => 0]);
        $form->fields()->create([
            'section_id' => $section->id,
            'key' => 'existing_field',
            'label' => 'Existing Field',
            'type' => 'text',
            'sort_order' => 0,
        ]);

        $import = FormImport::create([
            'user_id' => $user->id,
            'form_id' => $form->id,
            'source_type' => 'xlsx',
            'original_filename' => 'employees.xlsx',
            'file_path' => 'form-imports/test.xlsx',
            'status' => 'preview_ready',
            'preview_data' => null,
        ]);

        $this->actingAs($user)->postJson("/api/forms/{$form->id}/imports/{$import->id}/commit")
            ->assertUnprocessable();

        $this->assertDatabaseHas('fields', ['key' => 'existing_field']);
        $this->assertSame('preview_ready', $import->fresh()->status);
    }

    public function test_user_cannot_access_another_users_import(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $form = Form::factory()->for($owner)->create();

        $import = FormImport::create([
            'user_id' => $owner->id,
            'form_id' => $form->id,
            'source_type' => 'xlsx',
            'original_filename' => 'employees.xlsx',
            'file_path' => 'form-imports/test.xlsx',
            'status' => 'preview_ready',
            'preview_data' => ['title' => 'Imported Form', 'sections' => []],
        ]);

        $this->actingAs($otherUser)->getJson("/api/forms/{$form->id}/imports/{$import->id}")
            ->assertForbidden();
    }

    public function test_import_endpoints_return_404_when_import_does_not_belong_to_form(): void
    {
        $user = User::factory()->create();
        $formA = Form::factory()->for($user)->create(['title' => 'Form A']);
        $formB = Form::factory()->for($user)->create(['title' => 'Form B']);

        $import = FormImport::create([
            'user_id' => $user->id,
            'form_id' => $formA->id,
            'source_type' => 'xlsx',
            'original_filename' => 'employees.xlsx',
            'file_path' => 'form-imports/test.xlsx',
            'status' => 'preview_ready',
            'preview_data' => [
                'title' => 'Secret Imported Form',
                'sections' => [
                    [
                        'title' => 'Sensitive Section',
                        'fields' => [
                            [
                                'key' => 'secret_field',
                                'label' => 'Secret Field',
                                'type' => 'text',
                                'is_required' => true,
                                'config' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $showResponse = $this->actingAs($user)->getJson("/api/forms/{$formB->id}/imports/{$import->id}");
        $showResponse->assertNotFound();
        $this->assertImportDataIsNotLeaked($showResponse, 'Secret Imported Form', 'secret_field');

        $previewResponse = $this->actingAs($user)->getJson("/api/forms/{$formB->id}/imports/{$import->id}/preview");
        $previewResponse->assertNotFound();
        $this->assertImportDataIsNotLeaked($previewResponse, 'Secret Imported Form', 'secret_field');

        $commitResponse = $this->actingAs($user)->postJson("/api/forms/{$formB->id}/imports/{$import->id}/commit");
        $commitResponse->assertNotFound();
        $this->assertImportDataIsNotLeaked($commitResponse, 'Secret Imported Form', 'secret_field');

        $this->assertSame('preview_ready', $import->fresh()->status);
        $this->assertSame(0, $formB->sections()->count());
        $this->assertDatabaseMissing('fields', ['key' => 'secret_field']);
    }

    /**
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>  $response
     */
    private function assertImportDataIsNotLeaked(
        \Illuminate\Testing\TestResponse $response,
        string $title,
        string $fieldKey,
    ): void {
        $response
            ->assertJsonMissingPath('data.form_import')
            ->assertJsonMissingPath('data.preview');

        $content = (string) $response->getContent();

        $this->assertStringNotContainsString($title, $content);
        $this->assertStringNotContainsString($fieldKey, $content);
        $this->assertStringNotContainsString('file_path', $content);
        $this->assertStringNotContainsString('form-imports/', $content);
    }

    public function test_api_response_does_not_expose_file_path(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $path = ImportFixtureBuilder::createXlsx([
            ['Section', 'Field', 'Type', 'Required', 'Options'],
            ['Personal', 'Full Name', 'text', 'yes', ''],
        ]);

        $storeResponse = $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ]);

        $storeResponse
            ->assertCreated()
            ->assertJsonMissingPath('data.form_import.file_path');

        $import = FormImport::query()->first();

        $this->assertNotNull($import->file_path);

        $this->actingAs($user)->getJson("/api/forms/{$form->id}/imports/{$import->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.form_import.file_path');

        $this->actingAs($user)->getJson("/api/forms/{$form->id}/imports/{$import->id}/preview")
            ->assertOk()
            ->assertJsonMissingPath('data.form_import.file_path');

        @unlink($path);
    }

    public function test_failed_import_response_does_not_expose_file_path(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->for($user)->create();
        $path = ImportFixtureBuilder::createXlsx([
            ['Section', 'Field', 'Type', 'Required', 'Options'],
            ['Personal', 'Upload', 'file_upload', 'no', ''],
        ]);

        $this->actingAs($user)->post('/api/forms/'.$form->id.'/imports', [
            'file' => new UploadedFile(
                $path,
                'employees.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ])
            ->assertUnprocessable()
            ->assertJsonMissingPath('data.form_import.file_path');

        $this->assertNotNull(FormImport::query()->value('file_path'));

        @unlink($path);
    }
}
