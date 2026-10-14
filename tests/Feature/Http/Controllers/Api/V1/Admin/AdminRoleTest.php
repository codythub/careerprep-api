<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Admin;

use App\Models\AdminAuditLog;
use App\Models\Payment;
use App\Models\User;
use App\UserRole;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminRoleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_auth_responses_include_the_role(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Candidate',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonPath('data.role', 'candidate');

        User::factory()->admin()->create(['email' => 'admin@example.com', 'password' => 'password123']);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/user')->assertOk()->assertJsonPath('data.role', 'admin');
    }

    public function test_registration_always_creates_a_candidate_and_no_route_changes_a_role(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ])->assertCreated()->assertJsonPath('data.role', 'candidate');

        $candidate = User::query()->sole();
        $admin = User::factory()->admin()->create();

        $this->actingAs($candidate, 'sanctum')
            ->patchJson('/api/v1/profile', ['name' => 'Mallory', 'role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'candidate');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/profile', ['name' => 'Ops', 'role' => 'candidate'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $this->assertSame(UserRole::Candidate, $candidate->refresh()->role);
        $this->assertSame(UserRole::Admin, $admin->refresh()->role);

        // No HTTP route exists whose purpose is to change a role.
        $roleRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'role') || str_contains($route->uri(), 'promote'));
        $this->assertCount(0, $roleRoutes);
    }

    public function test_create_admin_creates_a_new_admin_account_with_a_hidden_password(): void
    {
        $this->artisan('careerprep:create-admin', ['email' => 'New.Admin@Example.com', '--name' => ' Site Admin '])
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse-9')
            ->expectsQuestion('Confirm password', 'correct-horse-9')
            ->expectsOutput('Admin account new.admin@example.com created.')
            ->assertSuccessful();

        $admin = User::query()->sole();
        $this->assertSame('Site Admin', $admin->name);
        $this->assertTrue($admin->isAdmin());
        $this->assertNotSame('correct-horse-9', $admin->password);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'new.admin@example.com', 'password' => 'correct-horse-9'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/admin/stats')->assertOk();
    }

    public function test_create_admin_asks_for_the_name_when_not_given(): void
    {
        $this->artisan('careerprep:create-admin', ['email' => 'ops@example.com'])
            ->expectsQuestion('Name', 'Ops Admin')
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse-9')
            ->expectsQuestion('Confirm password', 'correct-horse-9')
            ->assertSuccessful();

        $this->assertSame('Ops Admin', User::query()->sole()->name);
    }

    public function test_create_admin_refuses_an_existing_email_and_never_promotes(): void
    {
        $candidate = User::factory()->create(['email' => 'ada@example.com']);

        $this->artisan('careerprep:create-admin', ['email' => 'ADA@example.com', '--name' => 'Ada'])
            ->expectsOutput('An account with email ada@example.com already exists. Admin accounts must use a new email address.')
            ->assertFailed();

        $this->assertSame(UserRole::Candidate, $candidate->refresh()->role);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_create_admin_rejects_weak_or_mismatched_passwords_and_bad_input(): void
    {
        $this->artisan('careerprep:create-admin', ['email' => 'ops@example.com', '--name' => 'Ops'])
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse-9')
            ->expectsQuestion('Confirm password', 'different-horse-9')
            ->expectsOutput('The password field confirmation does not match.')
            ->expectsOutput('No account was created.')
            ->assertFailed();

        $this->artisan('careerprep:create-admin', ['email' => 'ops@example.com'])
            ->expectsQuestion('Name', '')
            ->expectsQuestion('Password (at least 8 characters)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->expectsOutput('The name field is required.')
            ->expectsOutput('The password field must be at least 8 characters.')
            ->assertFailed();

        $this->artisan('careerprep:create-admin', ['email' => 'not-an-email'])
            ->expectsOutput('not-an-email is not a valid email address.')
            ->assertFailed();

        $this->artisan('careerprep:create-admin', ['email' => 'ops@example.com', '--name' => 'Ops', '--no-interaction' => true])
            ->expectsOutput('Run this command interactively: the password is entered at a hidden prompt.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_make_admin_command_no_longer_exists(): void
    {
        $this->assertArrayNotHasKey('careerprep:make-admin', $this->app[Kernel::class]->all());
    }

    public function test_remove_admin_signs_out_and_deletes_the_admin_but_keeps_audit_attribution(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ops@example.com']);
        $token = $admin->createToken('careerprep-web')->plainTextToken;
        $payment = Payment::factory()->verified()->create();
        $this->withToken($token)
            ->postJson('/api/v1/admin/payments/'.$payment->id.'/mark-refunded', ['note' => 'Refunded'])
            ->assertOk();

        $this->artisan('careerprep:remove-admin', ['email' => 'ops@example.com'])
            ->expectsConfirmation('Delete admin account ops@example.com and sign it out everywhere?', 'yes')
            ->expectsOutput('Admin account ops@example.com deleted. Its audit log entries keep the email address.')
            ->assertSuccessful();

        $this->assertModelMissing($admin);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/user')->assertUnauthorized();

        $log = AdminAuditLog::query()->sole();
        $this->assertNull($log->actor_id);
        $this->assertSame('ops@example.com', $log->actor_email);
    }

    public function test_remove_admin_never_touches_candidates_or_admins_with_candidate_data(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $legacyAdmin = User::factory()->create(['email' => 'legacy@example.com']);
        Payment::factory()->for($legacyAdmin)->create();
        $legacyAdmin->forceFill(['role' => UserRole::Admin])->save();

        $this->artisan('careerprep:remove-admin', ['email' => 'ada@example.com', '--force' => true])
            ->expectsOutput('ada@example.com is a candidate account. remove-admin only deletes admin accounts.')
            ->assertFailed();

        $this->artisan('careerprep:remove-admin', ['email' => 'legacy@example.com', '--force' => true])
            ->expectsOutput('legacy@example.com has candidate data (resumes, payments or reviews). Deleting it would delete that data; resolve it first.')
            ->assertFailed();

        $this->artisan('careerprep:remove-admin', ['email' => 'nobody@example.com'])
            ->expectsOutput('No account found with email nobody@example.com.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 2);
    }

    public function test_remove_admin_changes_nothing_when_not_confirmed(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ops@example.com']);

        $this->artisan('careerprep:remove-admin', ['email' => 'ops@example.com'])
            ->expectsConfirmation('Delete admin account ops@example.com and sign it out everywhere?', 'no')
            ->expectsOutput('Nothing was changed.')
            ->assertFailed();

        $this->assertModelExists($admin);
    }

    public function test_role_is_rechecked_on_every_request(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('careerprep-web')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/stats')->assertOk();

        $admin->forceFill(['role' => UserRole::Candidate])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/admin/stats')->assertForbidden();
    }
}
