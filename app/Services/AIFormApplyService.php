<?php

namespace App\Services;

use App\Models\AIJob;
use App\Models\Form;
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

        if ($aiJob->status !== AIJob::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'ai_job' => ['Only completed AI jobs can be applied.'],
            ]);
        }

        if ($aiJob->wasApplied()) {
            throw ValidationException::withMessages([
                'ai_job' => ['This AI job has already been applied.'],
            ]);
        }

        if (empty($aiJob->validated_output)) {
            throw ValidationException::withMessages([
                'ai_job' => ['The AI job does not contain validated output to apply.'],
            ]);
        }

        /** @var array<string, mixed> $output */
        $output = $aiJob->validated_output;

        return DB::transaction(function () use ($form, $aiJob, $output) {
            $form = $this->structureApplyService->apply($form, $output);

            $aiJob->update([
                'applied_at' => now(),
            ]);

            return $form;
        });
    }
}
