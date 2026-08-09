<?php

namespace App\Livewire\Forms;

use App\Models\Form;
use App\Services\SubmissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class PublicForm extends Component
{
    public Form $form;

    /** @var array<string, mixed> */
    public array $schema = [];

    /** @var array<string, mixed> */
    public array $answers = [];

    public ?string $statusMessage = null;

    public string $statusType = 'success';

    public bool $submitted = false;

    public function mount(string $slug, SubmissionService $submissionService): void
    {
        try {
            $this->form = $submissionService->getPublishedFormBySlug($slug);
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (ValidationException) {
            abort(404);
        }

        $this->schema = $this->form->schema ?? [];
    }

    public function submit(SubmissionService $submissionService): void
    {
        try {
            $submissionService->submit(
                $this->form->fresh(),
                $this->answers,
                request()->ip(),
                request()->userAgent(),
            );

            $this->answers = [];
            $this->submitted = true;
            $this->statusMessage = 'Thank you! Your submission has been received.';
            $this->statusType = 'success';
        } catch (ValidationException $exception) {
            $this->submitted = false;

            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            $this->statusMessage = 'Please fix the validation errors below.';
            $this->statusType = 'error';
        }
    }

    public function render(): View
    {
        $title = ($this->schema['title'] ?? $this->form->title).' — '.config('app.name');

        return view('livewire.forms.public-form')
            ->layout('layouts.public')
            ->title($title);
    }
}
