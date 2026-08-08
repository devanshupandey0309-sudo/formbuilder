<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFormDraftRequest;
use App\Models\Form;
use App\Services\FormDraftAutosaveService;
use Illuminate\Http\JsonResponse;

class FormDraftController extends Controller
{
    public function store(
        StoreFormDraftRequest $request,
        Form $form,
        FormDraftAutosaveService $draftAutosaveService,
    ): JsonResponse {
        $validated = $request->validated();

        $form = $draftAutosaveService->autosave(
            $form,
            (int) $validated['draft_revision'],
            [
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'field_id' => $validated['field_id'] ?? null,
                'field_editor' => $validated['field_editor'] ?? null,
                'json_editor' => $validated['json_editor'] ?? null,
                'apply_json' => (bool) ($validated['apply_json'] ?? false),
            ],
        );

        return response()->json([
            'data' => [
                'id' => $form->id,
                'draft_revision' => $form->draft_revision,
                'draft_saved_at' => $form->draft_saved_at?->toIso8601String(),
            ],
        ]);
    }
}
