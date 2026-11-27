<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Annotation\Exceptions\AnnotationLifecycleAuthorizationException;
use App\Services\Annotation\Reconciliation\TrustedReconciliationPrincipalFactory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrustedReconciliationPrincipalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
    }

    public function test_factory_accepts_research_admin_and_exposes_user_id(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_RESEARCH_ADMIN]);
        $principal = (new TrustedReconciliationPrincipalFactory)->make($admin);

        $this->assertSame($admin->id, $principal->userId());
    }

    public function test_factory_rejects_ordinary_user_and_annotator(): void
    {
        $factory = new TrustedReconciliationPrincipalFactory;
        $user = User::factory()->create(['role' => 'user']);
        $annotator = User::factory()->create(['role' => User::ROLE_ANNOTATOR]);

        foreach ([$user, $annotator] as $candidate) {
            try {
                $factory->make($candidate);
                $this->fail('Expected rejection for role '.$candidate->role);
            } catch (AnnotationLifecycleAuthorizationException $e) {
                $this->assertStringContainsString('research_admin', strtolower($e->getMessage()));
            }
        }
    }
}
