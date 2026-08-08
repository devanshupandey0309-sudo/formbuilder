<?php

namespace App\Livewire\Forms;

use App\Models\Form;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class FormIndex extends Component
{
    public string $title = '';

    public string $description = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Form::class);
    }

    public function createForm(FormService $formService): void
    {
        $this->authorize('create', Form::class);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $form = $formService->createForm(auth()->user(), $validated);

        $this->redirectRoute('forms.builder', $form, navigate: true);
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $forms = Form::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get();

        return view('livewire.forms.form-index', [
            'forms' => $forms,
        ]);
    }
}
