<?php

namespace Tests\Feature\Regression;

use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_form_route_respects_configured_app_url(): void
    {
        config(['app.url' => 'https://demo.example.com']);
        URL::forceRootUrl('https://demo.example.com');
        URL::forceScheme('https');

        $form = Form::factory()->published()->create();

        $url = route('forms.public', $form->slug);

        $this->assertStringStartsWith('https://demo.example.com', $url);
        $this->assertStringContainsString('/f/'.$form->slug, $url);
    }

    public function test_env_example_contains_placeholders_only(): void
    {
        $contents = File::get(base_path('.env.example'));

        $this->assertStringNotContainsString('sk-', $contents);
        $this->assertStringNotContainsString('api_key=', $contents);
        $this->assertStringContainsString('APP_KEY=', $contents);
        $this->assertStringContainsString('AI_PROVIDER_DRIVER=mock', $contents);
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $contents);
    }

    public function test_application_code_does_not_contain_debug_helpers(): void
    {
        $paths = [
            base_path('app'),
            base_path('routes'),
        ];

        foreach ($paths as $path) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = File::get($file->getPathname());

                $this->assertDoesNotMatchRegularExpression(
                    '/\b(dd|dump|var_dump)\s*\(/',
                    $contents,
                    'Debug helper found in '.$file->getPathname(),
                );
            }
        }
    }

    public function test_ai_config_reads_from_environment_placeholders(): void
    {
        $this->assertSame(
            env('AI_PROVIDER_DRIVER', 'mock'),
            config('ai.driver'),
        );
    }
}
