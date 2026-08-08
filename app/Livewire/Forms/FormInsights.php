<?php

namespace App\Livewire\Forms;

use App\Models\Form;
use App\Services\SubmissionInsightService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.builder')]
class FormInsights extends Component
{
    public Form $form;

    /** @var array<string, mixed> */
    public array $insights = [];

    public function mount(Form $form, SubmissionInsightService $submissionInsightService): void
    {
        $this->authorize('view', $form);
        $this->form = $form;
        $this->insights = $submissionInsightService->getInsights($form);
    }

    public function render(): View
    {
        return view('livewire.forms.form-insights');
    }
}
