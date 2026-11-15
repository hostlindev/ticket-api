<?php

use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('authenticates valid user without remember and issues 8-hour token', function (): void {
    $this->travelTo('2026-11-15 12:00:00');

    $user = User::factory()->admin()->create([
        'public_id' => 'usr_ada',
        'name' => 'Ada Lovelace',
        'email' => 'admin@ticket.dev',
        'password' => Hash::make('Ticket123!'),
        'department' => 'Frontend & Web Platforms',
        'avatar_url' => 'https://example.test/ada.png',
    ]);

    $zeta = System::factory()->create(['id' => 'app-zeta', 'name' => 'Zeta Portal']);
    $alpha = System::factory()->create(['id' => 'app-alpha', 'name' => 'Alpha Core']);
    $user->systems()->attach([$zeta->id, $alpha->id]);

    $expectedExpiresAt = now()->addHours(8);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'admin@ticket.dev',
        'password' => 'Ticket123!',
        'remember' => false,
    ]);

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                'accessToken' => $response->json('data.accessToken'),
                'expiresAt' => $expectedExpiresAt->getTimestampMs(),
                'user' => [
                    'id' => 'usr_ada',
                    'name' => 'Ada Lovelace',
                    'email' => 'admin@ticket.dev',
                    'role' => 'admin',
                    'department' => 'Frontend & Web Platforms',
                    'avatarUrl' => 'https://example.test/ada.png',
                    'assignedSystems' => ['Alpha Core', 'Zeta Portal'],
                ],
            ],
        ])
        ->assertJsonMissingPath('data.user.data');

    expect($response->json('data.accessToken'))->toBeString()->not->toBeEmpty();

    $token = PersonalAccessToken::query()->first();
    expect($token)->not->toBeNull()
        ->and($token->tokenable_id)->toBe($user->id)
        ->and($token->expires_at?->toISOString())->toBe($expectedExpiresAt->toISOString());
});

it('authenticates valid user with remember enabled and issues 30-day token', function (): void {
    $this->travelTo('2026-11-15 12:00:00');

    $user = User::factory()->developer()->create([
        'email' => 'dev@ticket.dev',
        'password' => Hash::make('Ticket123!'),
    ]);

    $expectedExpiresAt = now()->addDays(30);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'dev@ticket.dev',
        'password' => 'Ticket123!',
        'remember' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.expiresAt', $expectedExpiresAt->getTimestampMs())
        ->assertJsonMissingPath('data.user.data');

    $token = PersonalAccessToken::query()->where('tokenable_id', $user->id)->first();
    expect($token)->not->toBeNull()
        ->and($token->expires_at?->toISOString())->toBe($expectedExpiresAt->toISOString());
});

it('returns ERR_AUTH_INVALID_CREDENTIALS on wrong password or unknown email', function (string $email, string $password): void {
    User::factory()->create([
        'email' => 'admin@ticket.dev',
        'password' => Hash::make('Ticket123!'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => $email,
        'password' => $password,
    ]);

    $response->assertUnauthorized()
        ->assertJson([
            'code' => 'ERR_AUTH_INVALID_CREDENTIALS',
        ])
        ->assertJsonStructure(['code', 'message', 'errors']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'wrong password' => ['admin@ticket.dev', 'WrongPass123!'],
    'unknown email' => ['unknown@ticket.dev', 'Ticket123!'],
]);

it('returns ERR_VALIDATION with flat error strings when login payload is invalid', function (): void {
    $response = $this->postJson('/api/auth/login', [
        'email' => 'not-an-email',
        'password' => 'short',
    ]);

    $response->assertUnprocessable()
        ->assertJson([
            'code' => 'ERR_VALIDATION',
        ])
        ->assertJsonStructure(['code', 'message', 'errors' => ['email', 'password']]);

    expect($response->json('errors.email'))->toBeString()
        ->and($response->json('errors.password'))->toBeString();
});

it('returns authenticated user profile wrapped in data on /auth/me', function (): void {
    $user = User::factory()->agent()->create([
        'public_id' => 'usr_margaret',
        'name' => 'Margaret Hamilton',
        'email' => 'margaret@ticket.dev',
        'department' => 'QA & Reliability',
        'avatar_url' => null,
    ]);
    $system = System::factory()->create(['id' => 'app-nexaportal', 'name' => 'NexaPortal Clientes']);
    $user->systems()->attach($system->id);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/auth/me');

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => 'usr_margaret',
                'name' => 'Margaret Hamilton',
                'email' => 'margaret@ticket.dev',
                'role' => 'agent',
                'department' => 'QA & Reliability',
                'avatarUrl' => null,
                'assignedSystems' => ['NexaPortal Clientes'],
            ],
        ]);
});

it('returns ERR_UNAUTHENTICATED on /auth/me and /auth/logout when unauthenticated', function (string $method, string $uri): void {
    $response = $this->json($method, $uri);

    $response->assertUnauthorized()
        ->assertJson([
            'code' => 'ERR_UNAUTHENTICATED',
        ])
        ->assertJsonStructure(['code', 'message', 'errors']);
})->with([
    'me' => ['GET', '/api/auth/me'],
    'logout' => ['POST', '/api/auth/logout'],
]);

it('revokes current personal access token on /auth/logout', function (): void {
    $user = User::factory()->create();
    $plainTextToken = $user->createToken('auth-token', ['*'], now()->addHours(8))->plainTextToken;

    $logoutResponse = $this->withToken($plainTextToken)
        ->postJson('/api/auth/logout');

    $logoutResponse->assertNoContent();
    $this->assertDatabaseCount('personal_access_tokens', 0);

    $this->app['auth']->forgetGuards();

    $meResponse = $this->withToken($plainTextToken)
        ->getJson('/api/auth/me');

    $meResponse->assertUnauthorized()
        ->assertJson([
            'code' => 'ERR_UNAUTHENTICATED',
        ]);
});

it('returns ERR_RATE_LIMITED when exceeding login throttle limit', function (): void {
    $payload = [
        'email' => 'ratelimit@ticket.dev',
        'password' => 'WrongPass123!',
    ];

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson('/api/auth/login', $payload)
            ->assertUnauthorized();
    }

    $response = $this->postJson('/api/auth/login', $payload);

    $response->assertTooManyRequests()
        ->assertJson([
            'code' => 'ERR_RATE_LIMITED',
        ])
        ->assertJsonStructure(['code', 'message', 'errors'])
        ->assertHeader('Retry-After');
});
