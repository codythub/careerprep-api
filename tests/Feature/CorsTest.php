<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CorsTest extends TestCase
{
    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $originalEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => [$env, $server]) {
            $this->restore($_ENV, $key, $env);
            $this->restore($_SERVER, $key, $server);
        }

        parent::tearDown();
    }

    public function test_exact_frontend_origins_are_allowed_and_normalised(): void
    {
        $this->loadCorsConfig(['FRONTEND_URL' => ' https://careerprep.ng/ , https://www.careerprep.ng']);

        $this->assertAllowed('https://careerprep.ng');
        $this->assertAllowed('https://www.careerprep.ng');
        $this->assertBlocked('https://evil.example');
        $this->assertBlocked('https://careerprep.ng.evil.example');
    }

    public function test_project_preview_deployments_are_allowed_outside_production(): void
    {
        $this->loadCorsConfig([
            'APP_ENV' => 'staging',
            'FRONTEND_URL' => 'https://staging.careerprep.ng',
            'VERCEL_PREVIEW_PROJECT' => 'careerprep-ng',
            'VERCEL_PREVIEW_SCOPE' => 'acme-team',
        ]);

        $this->assertAllowed('https://careerprep-ng-a1b2c3d4e-acme-team.vercel.app');
        $this->assertAllowed('https://careerprep-ng-git-feature-x-acme-team.vercel.app');
        $this->assertBlocked('https://other-app-a1b2c3d4e-acme-team.vercel.app');
        $this->assertBlocked('https://careerprep-ng-a1b2c3d4e-other-team.vercel.app');
        $this->assertBlocked('https://careerprep-ng-a1b2c3d4e-acme-team.vercel.app.evil.example');
        $this->assertBlocked('http://careerprep-ng-a1b2c3d4e-acme-team.vercel.app');
    }

    public function test_preview_deployments_are_never_allowed_in_production(): void
    {
        $this->loadCorsConfig([
            'APP_ENV' => 'production',
            'FRONTEND_URL' => 'https://careerprep.ng',
            'VERCEL_PREVIEW_PROJECT' => 'careerprep-ng',
            'VERCEL_PREVIEW_SCOPE' => 'acme-team',
        ]);

        $this->assertAllowed('https://careerprep.ng');
        $this->assertBlocked('https://careerprep-ng-a1b2c3d4e-acme-team.vercel.app');
    }

    public function test_no_origin_is_allowed_when_frontend_url_is_unset(): void
    {
        $this->loadCorsConfig(['FRONTEND_URL' => '']);

        $this->assertBlocked('http://localhost:3000');
    }

    /** @param array<string, string> $values */
    private function loadCorsConfig(array $values): void
    {
        $values += ['APP_ENV' => 'testing', 'VERCEL_PREVIEW_PROJECT' => '', 'VERCEL_PREVIEW_SCOPE' => ''];

        foreach ($values as $key => $value) {
            $this->originalEnv[$key] ??= [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        config()->set('cors', require config_path('cors.php'));
    }

    private function assertAllowed(string $origin): void
    {
        $this->preflight($origin)->assertHeader('Access-Control-Allow-Origin', $origin);
        $this->getJson('/api/v1/pricing', ['Origin' => $origin])
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    private function assertBlocked(string $origin): void
    {
        $response = $this->getJson('/api/v1/pricing', ['Origin' => $origin]);

        $this->assertNotSame($origin, $response->headers->get('Access-Control-Allow-Origin'), "{$origin} should be blocked.");
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    private function preflight(string $origin): TestResponse
    {
        return $this->call('OPTIONS', '/api/v1/profile', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
    }

    /** @param array<string, mixed> $store */
    private function restore(array &$store, string $key, mixed $value): void
    {
        if ($value === null) {
            unset($store[$key]);
        } else {
            $store[$key] = $value;
        }
    }
}
