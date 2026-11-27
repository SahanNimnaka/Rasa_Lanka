<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Annotation\AnnotationReconciliationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class AnnotationReconciliationBoundaryTest extends TestCase
{
    private const BOUNDARY_URI = '/api/testing/annotation-reconciliation-boundary';

    private const BOUNDARY_NAME = 'testing.annotation-reconcile-boundary';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array',
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])
            ->assertExitCode(0);

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->registerTestOnlyBoundaryRoute();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        $response = $this->postJson(self::BOUNDARY_URI, ['actor_id' => 999]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('meta.api_version', 'v1');
    }

    public function test_authenticated_ordinary_user_is_rejected_with_403(): void
    {
        $user = $this->createUser(null);
        $this->actingAs($user, 'web');

        $response = $this->postJson(self::BOUNDARY_URI);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('meta.api_version', 'v1');
    }

    public function test_authenticated_annotation_owner_is_rejected_with_403(): void
    {
        $owner = $this->createUser(User::ROLE_ANNOTATOR);
        $this->actingAs($owner, 'web');

        $response = $this->postJson(self::BOUNDARY_URI);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('meta.api_version', 'v1');
    }

    public function test_authenticated_research_admin_passes_authorization_boundary(): void
    {
        $admin = $this->createUser(User::ROLE_RESEARCH_ADMIN);
        $this->actingAs($admin, 'web');

        $response = $this->postJson(self::BOUNDARY_URI);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('authenticated_user_id', $admin->id);
    }

    public function test_real_web_session_login_passes_reconciliation_boundary_without_spoofing(): void
    {
        $spy = Mockery::spy(AnnotationReconciliationService::class);
        $this->app->instance(AnnotationReconciliationService::class, $spy);

        $admin = $this->createUser(User::ROLE_RESEARCH_ADMIN, 'admin-session@example.com');
        $other = $this->createUser(User::ROLE_RESEARCH_ADMIN, 'other-admin@example.com');

        $loginResponse = $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', [
                'email' => $admin->email,
                'password' => 'password123',
            ]);

        $loginResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $admin->id);

        $sessionId = (string) $this->app['session']->driver()->getId();
        $this->withCookie((string) config('session.cookie'), $sessionId);
        Auth::forgetGuards();

        $response = $this->postJson(self::BOUNDARY_URI, [
            'actor_id' => $other->id,
            'actor_user_id' => $other->id,
            'user_id' => $other->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('authenticated_user_id', $admin->id);
        $this->assertNotSame($other->id, $response->json('authenticated_user_id'));

        $spy->shouldNotHaveReceived('reportRecord');
        $spy->shouldNotHaveReceived('reportAll');
        $spy->shouldNotHaveReceived('reconcileRecord');
        $spy->shouldNotHaveReceived('reconcileAll');
        $spy->shouldNotHaveReceived('scanAll');
    }

    public function test_client_supplied_actor_id_cannot_change_authenticated_principal(): void
    {
        $admin = $this->createUser(User::ROLE_RESEARCH_ADMIN);
        $other = $this->createUser(User::ROLE_RESEARCH_ADMIN, 'other@example.com');
        $this->actingAs($admin, 'web');

        $response = $this->postJson(self::BOUNDARY_URI, [
            'actor_id' => $other->id,
            'actor_user_id' => $other->id,
            'user_id' => $other->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('authenticated_user_id', $admin->id);

        $this->assertNotSame($other->id, $response->json('authenticated_user_id'));
        $this->assertSame($admin->id, $this->app['request']->user()->id);
    }

    public function test_client_supplied_user_id_cannot_impersonate_another_administrator(): void
    {
        $admin = $this->createUser(User::ROLE_RESEARCH_ADMIN);
        $targetAdmin = $this->createUser(User::ROLE_RESEARCH_ADMIN, 'target-admin@example.com');
        $this->actingAs($admin, 'web');

        $response = $this->postJson(self::BOUNDARY_URI, [
            'user_id' => $targetAdmin->id,
            'actor_user_id' => $targetAdmin->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('authenticated_user_id', $admin->id);
        $this->assertSame($admin->id, Auth::guard('web')->id());
    }

    public function test_client_supplied_role_claim_cannot_grant_administrator_access(): void
    {
        $user = $this->createUser(User::ROLE_ANNOTATOR);
        $this->actingAs($user, 'web');

        $response = $this->postJson(self::BOUNDARY_URI, [
            'role' => User::ROLE_RESEARCH_ADMIN,
            'is_admin' => true,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_no_production_reconciliation_route_or_endpoint_exists(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $reconciliationRoutes = $routes->filter(function ($route): bool {
            $uri = strtolower($route->uri());
            $name = strtolower((string) $route->getName());
            $action = strtolower((string) $route->getActionName());

            return str_contains($uri, 'reconcile')
                || str_contains($name, 'reconcile')
                || str_contains($action, 'reconciliation');
        })->filter(function ($route): bool {
            return $route->uri() !== ltrim(self::BOUNDARY_URI, '/');
        });

        $this->assertCount(
            0,
            $reconciliationRoutes,
            'Production/test-registered reconciliation routes found: '.$reconciliationRoutes->map(fn ($r) => $r->uri())->implode(', ')
        );

        $this->assertFalse(Route::has('api.v1.annotation-reconciliation.report'));
        $this->assertFalse(Route::has('api.v1.annotation-reconciliation.apply'));
    }

    public function test_no_reconciliation_service_execution_occurs_during_boundary_tests(): void
    {
        $spy = Mockery::spy(AnnotationReconciliationService::class);
        $this->app->instance(AnnotationReconciliationService::class, $spy);

        $admin = $this->createUser(User::ROLE_RESEARCH_ADMIN);
        $this->actingAs($admin, 'web');

        $this->postJson(self::BOUNDARY_URI, ['actor_id' => 123])->assertOk();
        $this->postJson(self::BOUNDARY_URI)->assertOk();

        $spy->shouldNotHaveReceived('reportRecord');
        $spy->shouldNotHaveReceived('reportAll');
        $spy->shouldNotHaveReceived('reconcileRecord');
        $spy->shouldNotHaveReceived('reconcileAll');
        $spy->shouldNotHaveReceived('scanAll');
    }

    public function test_ordinary_annotation_owner_lifecycle_permissions_are_unchanged(): void
    {
        $annotator = $this->createUser(User::ROLE_ANNOTATOR);
        $reviewer = $this->createUser(User::ROLE_REVIEWER);
        $admin = $this->createUser(User::ROLE_RESEARCH_ADMIN);
        $ordinary = $this->createUser(null, 'ordinary@example.com');
        $record = (object) ['created_by_user_id' => $annotator->id];

        $this->assertTrue(Gate::forUser($annotator)->allows('annotation.access'));
        $this->assertTrue(Gate::forUser($annotator)->allows('annotation.validate'));
        $this->assertFalse(Gate::forUser($annotator)->allows('annotation.approve', $record));
        $this->assertFalse(Gate::forUser($annotator)->allows('annotation.export-canonical'));

        $this->assertTrue(Gate::forUser($reviewer)->allows('annotation.approve', $record));
        $this->assertFalse(Gate::forUser($reviewer)->allows('annotation.approve', (object) ['created_by_user_id' => $reviewer->id]));
        $this->assertFalse(Gate::forUser($reviewer)->allows('annotation.export-canonical'));
        $this->assertFalse(Gate::forUser($reviewer)->allows('annotation.reconcile'));

        $this->assertTrue(Gate::forUser($admin)->allows('annotation.approve', $record));
        $this->assertTrue(Gate::forUser($admin)->allows('annotation.export-canonical'));
        $this->assertTrue(Gate::forUser($admin)->allows('annotation.reconcile'));

        $this->assertFalse(Gate::forUser($ordinary)->allows('annotation.access'));
        $this->assertFalse(Gate::forUser($ordinary)->allows('annotation.validate'));
        $this->assertFalse(Gate::forUser($ordinary)->allows('annotation.approve', $record));
        $this->assertFalse(Gate::forUser($ordinary)->allows('annotation.export-canonical'));
        $this->assertFalse(Gate::forUser($ordinary)->allows('annotation.reconcile'));
    }

    private function registerTestOnlyBoundaryRoute(): void
    {
        Route::middleware(['auth:sanctum', 'can:annotation.reconcile'])
            ->post(self::BOUNDARY_URI, function (Request $request) {
                return response()->json([
                    'ok' => true,
                    'authenticated_user_id' => $request->user()->id,
                ]);
            })
            ->name(self::BOUNDARY_NAME);
    }

    private function createUser(?string $role, string $email = 'user@example.com'): User
    {
        static $sequence = 1;

        return User::query()->forceCreate([
            'name' => 'Test User '.$sequence++,
            'email' => $email === 'user@example.com' ? "user{$sequence}@example.com" : $email,
            'password' => bcrypt('password123'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
