<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Service URL
    |--------------------------------------------------------------------------
    |
    | Base URL for the external FastAPI AI service consumed by Laravel.
    |
    */

    'service_url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),

    'connect_timeout' => (int) env('AI_SERVICE_CONNECT_TIMEOUT', 5),

    'timeout' => (int) env('AI_SERVICE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Provider Driver
    |--------------------------------------------------------------------------
    |
    | "http" calls the FastAPI service. "mock" uses the in-process mock provider
    | (default for tests and local demos without FastAPI running). "gemini" calls
    | Google Gemini server-side when GEMINI_API_KEY is configured.
    |
    */

    'driver' => env('AI_PROVIDER_DRIVER', 'mock'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
    ],

];
