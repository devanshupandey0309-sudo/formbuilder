<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['ai.driver' => 'mock']);

$provider = app(App\Contracts\AIProvider::class);
$validator = app(App\Services\AI\AIOutputValidator::class);

$prompts = [
    '1' => 'create a form with fields employee name, email, phone number, date of birth',
    '2' => 'Create a customer registration form with name, email, phone, country and age.',
    '3' => 'Create an employee onboarding form with employee name, department, joining date, manager email and emergency contact.',
];

foreach ($prompts as $id => $prompt) {
    $validated = $validator->validate($provider->generateForm($prompt));
    $fields = collect($validated['sections'])
        ->flatMap(fn (array $section) => $section['fields'])
        ->map(fn (array $field) => [
            'label' => $field['label'],
            'key' => $field['key'],
            'type' => $field['type'],
            'validation' => $field['validation'] ?? null,
        ])
        ->values()
        ->all();

    echo "Prompt {$id}: {$prompt}\n";

    foreach ($fields as $field) {
        echo sprintf(
            "  - %s (%s, %s) validation=%s\n",
            $field['label'],
            $field['key'],
            $field['type'],
            json_encode($field['validation']),
        );
    }

    echo "\n";
}
