<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Services\FormHealthService;
use Illuminate\Http\JsonResponse;

class FormHealthController extends Controller
{
    public function show(Form $form, FormHealthService $formHealthService): JsonResponse
    {
        $this->authorize('view', $form);

        return $this->apiSuccess('Form health retrieved successfully.', $formHealthService->analyze($form));
    }
}
