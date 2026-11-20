<?php

namespace Tests\Feature\Api\V1;

use App\Http\Controllers\Api\V1\Controller as ApiController;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_health_endpoint_returns_the_agreed_json_structure(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('meta.api_version', 'v1');
    }

    public function test_api_routes_are_registered_under_api_v1(): void
    {
        $route = Route::getRoutes()->getByName('v1.health');

        $this->assertNotNull($route);
        $this->assertSame('api/v1/health', $route->uri());
        $this->assertContains('GET', $route->methods());
    }

    public function test_unknown_api_route_returns_the_agreed_error_structure(): void
    {
        $this->getJson('/api/v1/this-route-does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.message', 'The requested API resource was not found.')
            ->assertJsonPath('meta.api_version', 'v1');
    }

    public function test_api_validation_failures_use_the_agreed_error_structure(): void
    {
        Route::middleware('api')->post('/api/v1/_test-validation', function () {
            throw ValidationException::withMessages([
                'email' => ['The email field is required.'],
            ]);
        })->name('api.foundation.test-validation');

        $this->postJson('/api/v1/_test-validation', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'The given data was invalid.')
            ->assertJsonPath('error.details.email.0', 'The email field is required.')
            ->assertJsonPath('meta.api_version', 'v1');
    }

    public function test_api_exception_responses_use_the_agreed_error_structure(): void
    {
        $cases = [
            'unauthenticated' => [
                fn () => throw new AuthenticationException,
                401,
                'UNAUTHENTICATED',
            ],
            'forbidden' => [
                fn () => throw new AuthorizationException,
                403,
                'FORBIDDEN',
            ],
            'model-not-found' => [
                fn () => throw new ModelNotFoundException,
                404,
                'NOT_FOUND',
            ],
            'not-found' => [
                fn () => throw new NotFoundHttpException,
                404,
                'NOT_FOUND',
            ],
            'csrf-mismatch' => [
                fn () => throw new TokenMismatchException,
                419,
                'CSRF_TOKEN_MISMATCH',
            ],
            'validation' => [
                fn () => throw ValidationException::withMessages(['field' => ['Invalid.']]),
                422,
                'VALIDATION_FAILED',
            ],
            'throttled' => [
                fn () => throw new ThrottleRequestsException,
                429,
                'TOO_MANY_REQUESTS',
            ],
            'server-error' => [
                fn () => throw new RuntimeException('Test exception'),
                500,
                'INTERNAL_SERVER_ERROR',
            ],
        ];

        foreach ($cases as $key => [$thrower, $status, $code]) {
            Route::middleware('api')->get("/api/v1/_test-exception/{$key}", $thrower)
                ->name("api.foundation.test-exception.{$key}");
        }

        foreach ($cases as $key => [, $status, $code]) {
            $this->getJson("/api/v1/_test-exception/{$key}")
                ->assertStatus($status)
                ->assertJsonPath('success', false)
                ->assertJsonPath('error.code', $code)
                ->assertJsonPath('meta.api_version', 'v1');
        }
    }

    public function test_web_routes_retain_their_existing_html_behaviour(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Laravel');

        $this->get('/web-route-that-does-not-exist')
            ->assertNotFound()
            ->assertDontSee('"success":false');
    }

    public function test_paginated_helper_uses_the_agreed_structure(): void
    {
        $paginator = new LengthAwarePaginator([
            ['id' => 1],
            ['id' => 2],
        ], 12, 2, 1);

        $payload = ApiController::paginated($paginator)->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame([['id' => 1], ['id' => 2]], $payload['data']);
        $this->assertSame('v1', $payload['meta']['api_version']);
        $this->assertSame(1, $payload['meta']['pagination']['current_page']);
        $this->assertSame(2, $payload['meta']['pagination']['per_page']);
        $this->assertSame(12, $payload['meta']['pagination']['total']);
        $this->assertSame(6, $payload['meta']['pagination']['last_page']);
        $this->assertArrayHasKey('first', $payload['links']);
        $this->assertArrayHasKey('last', $payload['links']);
        $this->assertArrayHasKey('prev', $payload['links']);
        $this->assertArrayHasKey('next', $payload['links']);
    }
}
