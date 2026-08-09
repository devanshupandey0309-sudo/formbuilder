<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use AuthorizesRequests;

    protected function apiSuccess(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success($message, $data, $status);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function apiError(string $message, int $status, ?array $data = null): JsonResponse
    {
        return ApiResponse::error($message, $status, $data);
    }
}
