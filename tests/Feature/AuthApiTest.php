<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Image User',
            'email' => 'USER@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'user@example.com')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'user@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $token = PersonalAccessToken::query()->sole();
        $this->assertSame(config('auth.api_tokens.abilities'), $token->abilities);
        $this->assertNotNull($token->expires_at);
    }

    public function test_user_can_login_and_invalid_credentials_are_generic(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'Password123',
        ])->assertOk()->assertJsonStructure(['data' => ['user', 'token', 'token_type']]);

        $wrongPassword = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ]);
        $missingUser = $this->postJson('/api/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ]);

        $wrongPassword->assertUnprocessable()->assertJsonPath(
            'errors.email.0',
            'The provided credentials are incorrect.',
        );
        $missingUser->assertUnprocessable()->assertJsonPath(
            'errors.email.0',
            'The provided credentials are incorrect.',
        );
    }

    public function test_authenticated_user_can_be_returned_and_logged_out(): void
    {
        $user = User::factory()->create();
        $plainTextToken = $user->createToken('test')->plainTextToken;
        $tokenId = (int) str($plainTextToken)->before('|')->toString();

        $this->withToken($plainTextToken)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->withToken($plainTextToken)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertNull(PersonalAccessToken::query()->find($tokenId));
        Auth::forgetGuards();
        $this->withToken($plainTextToken)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_protected_endpoints_reject_unauthenticated_requests(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/logout')->assertUnauthorized();
        $this->getJson('/api/images')->assertUnauthorized();
        $this->postJson('/api/images')->assertUnauthorized();
        $this->getJson('/api/images/01K00000000000000000000000')->assertUnauthorized();
        $this->deleteJson('/api/images/01K00000000000000000000000')->assertUnauthorized();
    }

    public function test_token_abilities_are_enforced(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['user:read']);

        $this->getJson('/api/user')->assertOk();
        $this->postJson('/api/images')->assertForbidden();
        $this->postJson('/api/logout')->assertForbidden();
    }
}
