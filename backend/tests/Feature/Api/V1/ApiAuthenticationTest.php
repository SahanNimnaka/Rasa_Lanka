<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    private const ORIGIN = 'http://localhost:5173';

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

        $this->withHeader('Origin', self::ORIGIN);
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_registration_creates_a_user_and_establishes_the_session(): void
    {
        $response = $this->registerUser();

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Test User')
            ->assertJsonPath('data.email', 'user@example.com')
            ->assertJsonPath('meta.api_version', 'v1');

        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'user@example.com',
        ]);
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data'));
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->registerUser()->assertStatus(201);

        $response = $this->registerUser([
            'name' => 'Another User',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.email.0', 'The email has already been taken.')
            ->assertJsonPath('meta.api_version', 'v1');

        $this->assertSame(1, User::query()->count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $response = $this->registerUser([
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('meta.api_version', 'v1');

        $this->assertArrayHasKey('email', $response->json('error.details'));
        $this->assertSame(0, User::query()->count());
    }

    public function test_missing_password_confirmation_is_rejected(): void
    {
        $payload = $this->registrationPayload();
        unset($payload['password_confirmation']);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath(
                'error.details.password_confirmation.0',
                'The password confirmation field is required.'
            )
            ->assertJsonPath('meta.api_version', 'v1');

        $this->assertSame(0, User::query()->count());
    }

    public function test_successful_login_returns_a_consistent_response_and_authenticates(): void
    {
        $user = $this->createUser();

        $response = $this->loginUser();

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'user@example.com')
            ->assertJsonPath('meta.api_version', 'v1');

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    public function test_invalid_credentials_return_a_generic_authentication_error(): void
    {
        $this->createUser();

        $response = $this->loginUser([
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'The provided credentials are incorrect.')
            ->assertJsonPath('meta.api_version', 'v1');

        $this->assertArrayNotHasKey('details', $response->json('error'));
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_login_is_rate_limited(): void
    {
        $this->createUser();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->loginUser([
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $response = $this->loginUser([
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')
            ->assertJsonPath('meta.api_version', 'v1');
    }

    public function test_authenticated_user_can_be_retrieved(): void
    {
        $response = $this->registerUser();
        $this->withSessionCookie($this->sessionId());

        $me = $this->getJson('/api/v1/auth/me');

        $me->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $response->json('data.id'))
            ->assertJsonPath('data.email', 'user@example.com')
            ->assertJsonPath('meta.api_version', 'v1');

        $this->assertArrayNotHasKey('password', $me->json('data'));
        $this->assertArrayNotHasKey('remember_token', $me->json('data'));
    }

    public function test_unauthenticated_user_receives_401_from_me(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('meta.api_version', 'v1');
    }

    public function test_successful_logout_returns_a_consistent_response(): void
    {
        $this->registerUser();
        $sessionId = $this->sessionId();
        $csrfToken = $this->csrfToken();
        $this->withSessionCookie($sessionId);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.api_version', 'v1');

        $this->assertNull($response->json('data'));
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertNotSame($sessionId, $this->sessionId());
        $this->assertNotSame($csrfToken, $this->csrfToken());
    }

    public function test_session_is_invalidated_after_logout(): void
    {
        $this->registerUser();
        $oldSessionId = $this->sessionId();
        $this->withSessionCookie($oldSessionId);

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $newSessionId = $this->sessionId();
        $this->assertNotSame($oldSessionId, $newSessionId);
        $this->assertNull($this->app->make('session')->driver()->get(Auth::guard('web')->getName()));

        $this->withSessionCookie($newSessionId);
        Auth::forgetGuards();

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_password_is_hashed_and_never_returned(): void
    {
        $response = $this->registerUser();

        $user = User::query()->where('email', 'user@example.com')->firstOrFail();

        $this->assertNotSame('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));

        $payload = json_encode($response->json());
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('password', $payload);
        $this->assertStringNotContainsString($user->password, $payload);
        $this->assertSame(
            ['id', 'name', 'email', 'created_at', 'updated_at'],
            array_keys($response->json('data'))
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function registerUser(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', $this->registrationPayload($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function loginUser(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', array_merge([
            'email' => 'user@example.com',
            'password' => 'password123',
        ], $overrides));
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);
    }

    private function withSessionCookie(string $sessionId): void
    {
        $this->withCookie((string) config('session.cookie'), $sessionId);
    }

    private function sessionId(): string
    {
        return (string) $this->app->make('session')->driver()->getId();
    }

    private function csrfToken(): string
    {
        return (string) $this->app->make('session')->driver()->token();
    }
}
