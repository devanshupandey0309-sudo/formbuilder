<?php

namespace App\Services;

use App\Models\AIJob;
use App\Models\Form;
use App\Services\AIFormGenerationService;
use App\Services\FormStructureApplyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AIFormApplyService
{
    public function __construct(
        private readonly FormStructureApplyService $structureApplyService,
    ) {}

    public function apply(Form $form, AIJob $aiJob): Form
    {
        if ($aiJob->form_id !== $form->id) {
            abort(404);
        }

        if ($aiJob->status !== 'completed') {
            throw ValidationException::withMessages([
                'ai_job' => ['Only completed AI jobs can be applied.'],
            ]);
        }

        if (empty($aiJob->validated_output)) {
            throw ValidationException::withMessages([
                'ai_job' => ['The AI job does not contain validated output to apply.'],
            ]);
        }

        /** @var array<string, mixed> $output */
        $output = $aiJob->validated_output;

        return $this->structureApplyService->apply($form, $output);
    }
}
