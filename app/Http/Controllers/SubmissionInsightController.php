<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Services\SubmissionInsightService;
use Illuminate\Http\JsonResponse;

class SubmissionInsightController extends Controller
{
    public function show(Form $form, SubmissionInsightService $submissionInsightService): JsonResponse
    {
        $this->authorize('view', $form);

        return response()->json([
            'success' => true,
            'message' => 'Submission insights retrieved successfully.',
            'data' => $submissionInsightService->getInsights($form),
        ]);
    }
}
