<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Admin;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

    public function test_role_cannot_be_set_through_registration_or_profile(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
        ])->assertCreated()->assertJsonPath('data.role', 'candidate');

        $user = User::query()->sole();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/profile', ['name' => 'Mallory', 'role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'candidate');

        $this->assertSame(UserRole::Candidate, $user->refresh()->role);
    }

    public function test_make_admin_command_grants_and_revokes_admin(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com']);
        $token = $user->createToken('careerprep-web')->plainTextToken;

        $this->artisan('careerprep:make-admin', ['email' => ' OPS@example.com '])
            ->expectsOutput('ops@example.com now has the admin role.')
            ->assertSuccessful();

        $this->assertTrue($user->refresh()->isAdmin());
        $this->withToken($token)->getJson('/api/v1/admin/stats')->assertOk();

        $this->artisan('careerprep:make-admin', ['email' => 'ops@example.com'])
            ->expectsOutput('ops@example.com already has the admin role.')
            ->assertSuccessful();

        $this->artisan('careerprep:make-admin', ['email' => 'ops@example.com', '--revoke' => true])
            ->expectsOutput('ops@example.com now has the candidate role.')
            ->assertSuccessful();

        $this->assertSame(UserRole::Candidate, $user->refresh()->role);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_make_admin_command_creates_a_new_admin_account_with_a_hidden_password(): void
    {
        $this->artisan('careerprep:make-admin', ['email' => 'New.Admin@Example.com'])
            ->expectsConfirmation('No user found with email new.admin@example.com. Create a new admin account?', 'yes')
            ->expectsQuestion('Name', ' Site Admin ')
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse-9')
            ->expectsQuestion('Confirm password', 'correct-horse-9')
            ->expectsOutput('new.admin@example.com created with the admin role.')
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('Site Admin', $user->name);
        $this->assertTrue($user->isAdmin());
        $this->assertNotSame('correct-horse-9', $user->password);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'new.admin@example.com', 'password' => 'correct-horse-9'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/admin/stats')->assertOk();
    }

    public function test_make_admin_command_creates_nothing_when_declined(): void
    {
        $this->artisan('careerprep:make-admin', ['email' => 'nobody@example.com'])
            ->expectsConfirmation('No user found with email nobody@example.com. Create a new admin account?', 'no')
            ->expectsOutput('No account was created.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_make_admin_command_rejects_mismatched_or_weak_passwords(): void
    {
        $this->artisan('careerprep:make-admin', ['email' => 'ops@example.com'])
            ->expectsConfirmation('No user found with email ops@example.com. Create a new admin account?', 'yes')
            ->expectsQuestion('Name', 'Ops')
            ->expectsQuestion('Password (at least 8 characters)', 'correct-horse-9')
            ->expectsQuestion('Confirm password', 'different-horse-9')
            ->expectsOutput('The password field confirmation does not match.')
            ->expectsOutput('No account was created.')
            ->assertFailed();

        $this->artisan('careerprep:make-admin', ['email' => 'ops@example.com'])
            ->expectsConfirmation('No user found with email ops@example.com. Create a new admin account?', 'yes')
            ->expectsQuestion('Name', '')
            ->expectsQuestion('Password (at least 8 characters)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->expectsOutput('The name field is required.')
            ->expectsOutput('The password field must be at least 8 characters.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_make_admin_command_never_creates_accounts_non_interactively_or_on_revoke(): void
    {
        $this->artisan('careerprep:make-admin', ['email' => 'not-an-email'])
            ->expectsOutput('not-an-email is not a valid email address.')
            ->assertFailed();

        $this->artisan('careerprep:make-admin', ['email' => 'nobody@example.com', '--revoke' => true])
            ->expectsOutput('No user found with email nobody@example.com.')
            ->assertFailed();

        $this->artisan('careerprep:make-admin', ['email' => 'nobody@example.com', '--no-interaction' => true])
            ->expectsOutput('No user found with email nobody@example.com. Run this command interactively to create the account.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
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
