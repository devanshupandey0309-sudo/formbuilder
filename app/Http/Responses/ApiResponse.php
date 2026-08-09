<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function error(string $message, int $status, ?array $data = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function validationErrors(
        array $errors,
        string $message = 'Validation failed.',
        int $status = 422,
    ): JsonResponse {
        return self::error($message, $status, ['errors' => $errors]);
    }
}
