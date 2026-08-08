<?php

namespace App\Livewire\Forms;

use App\Models\Form;
use App\Services\FormService;
use App\Services\SubmissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class FormPreview extends Component
{
    public Form $form;

    /** @var array<string, mixed> */
    public array $schema = [];

    /** @var array<string, mixed> */
    public array $answers = [];

    public ?string $statusMessage = null;

    public string $statusType = 'success';

    public bool $isDraftPreview = false;

    public function mount(Form $form, FormService $formService): void
    {
        $this->authorize('view', $form);
        $this->form = $form;
        $this->isDraftPreview = $form->status !== 'published' || $form->schema === null;
        $this->schema = $this->isDraftPreview
            ? $formService->compileSchema($form)
            : ($form->schema ?? $formService->compileSchema($form));
    }

    public function submit(SubmissionService $submissionService): void
    {
        if ($this->isDraftPreview) {
            $this->statusMessage = 'Draft preview only. Publish the form to accept submissions.';
            $this->statusType = 'error';

            return;
        }

        try {
            $submissionService->submit(
                $this->form->fresh(),
                $this->answers,
                request()->ip(),
                request()->userAgent(),
            );

            $this->answers = [];
            $this->statusMessage = 'Submission received successfully.';
            $this->statusType = 'success';
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            $this->statusMessage = 'Please fix the validation errors below.';
            $this->statusType = 'error';
        }
    }

    public function render(): View
    {
        return view('livewire.forms.form-preview');
    }
}
