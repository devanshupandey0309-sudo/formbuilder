<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFormRequest;
use App\Http\Requests\UpdateFormRequest;
use App\Models\Form;
use App\Services\FormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormController extends Controller
{
    public function __construct(
        private readonly FormService $formService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Form::class);

        $forms = Form::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get();

        return $this->apiSuccess('Forms retrieved successfully.', $forms);
    }

    public function store(StoreFormRequest $request): JsonResponse
    {
        $this->authorize('create', Form::class);

        $form = $this->formService->createForm($request->user(), $request->validated());

        return $this->apiSuccess('Form created successfully.', $form, 201);
    }

    public function show(Form $form): JsonResponse
    {
        $this->authorize('view', $form);

        return $this->apiSuccess('Form retrieved successfully.', $form);
    }

    public function update(UpdateFormRequest $request, Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        $form = $this->formService->updateForm($form, $request->validated());

        return $this->apiSuccess('Form updated successfully.', $form);
    }

    public function destroy(Form $form): JsonResponse
    {
        $this->authorize('delete', $form);

        $this->formService->deleteForm($form);

        return $this->apiSuccess('Form deleted successfully.');
    }

    public function publish(Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        $form = $this->formService->publishForm($form);

        return $this->apiSuccess('Form published successfully.', $form);
    }

    public function unpublish(Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        $form = $this->formService->unpublishForm($form);

        return $this->apiSuccess('Form unpublished successfully.', $form);
    }
}
