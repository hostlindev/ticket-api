<?php

use App\Enums\UserRole;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lists systems with ordered developers wrapped in data for admin', function (): void {
    $admin = User::factory()->admin()->create([
        'public_id' => 'usr_ada',
        'name' => 'Ada Lovelace',
        'email' => 'admin@ticket.dev',
        'specialty' => 'Plataforma',
    ]);
    $tim = User::factory()->developer()->create([
        'public_id' => 'usr_tim',
        'name' => 'Tim Berners-Lee',
        'email' => 'tim@ticket.dev',
        'specialty' => 'Frontend',
    ]);

    $kroma = System::factory()->create([
        'id' => 'app-kromashop',
        'name' => 'KromaShop B2B',
        'domain' => 'b2b.kromashop.io',
        'lead_user_id' => $tim->id,
    ]);
    $nexa = System::factory()->create([
        'id' => 'app-nexaportal',
        'name' => 'NexaPortal Clientes',
        'domain' => 'clientes.nexa.io',
        'lead_user_id' => $admin->id,
    ]);

    $nexa->developers()->attach([$tim->id, $admin->id]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/systems');

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                [
                    'id' => 'app-kromashop',
                    'name' => 'KromaShop B2B',
                    'domain' => 'b2b.kromashop.io',
                    'developers' => [],
                ],
                [
                    'id' => 'app-nexaportal',
                    'name' => 'NexaPortal Clientes',
                    'domain' => 'clientes.nexa.io',
                    'developers' => [
                        [
                            'id' => 'usr_ada',
                            'name' => 'Ada Lovelace',
                            'email' => 'admin@ticket.dev',
                            'specialty' => 'Plataforma',
                        ],
                        [
                            'id' => 'usr_tim',
                            'name' => 'Tim Berners-Lee',
                            'email' => 'tim@ticket.dev',
                            'specialty' => 'Frontend',
                        ],
                    ],
                ],
            ],
        ]);
});

it('lists all team members ordered by name wrapped in data for admin', function (): void {
    $admin = User::factory()->admin()->create([
        'public_id' => 'usr_ada',
        'name' => 'Ada Lovelace',
        'email' => 'admin@ticket.dev',
        'specialty' => 'Plataforma',
    ]);
    User::factory()->agent()->create([
        'public_id' => 'usr_margaret',
        'name' => 'Margaret Hamilton',
        'email' => 'margaret@ticket.dev',
        'specialty' => 'QA',
    ]);
    User::factory()->developer()->create([
        'public_id' => 'usr_grace',
        'name' => 'Grace Hopper',
        'email' => 'grace@ticket.dev',
        'specialty' => 'Backend',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/developers');

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                [
                    'id' => 'usr_ada',
                    'name' => 'Ada Lovelace',
                    'email' => 'admin@ticket.dev',
                    'specialty' => 'Plataforma',
                ],
                [
                    'id' => 'usr_grace',
                    'name' => 'Grace Hopper',
                    'email' => 'grace@ticket.dev',
                    'specialty' => 'Backend',
                ],
                [
                    'id' => 'usr_margaret',
                    'name' => 'Margaret Hamilton',
                    'email' => 'margaret@ticket.dev',
                    'specialty' => 'QA',
                ],
            ],
        ]);
});

it('syncs system developers by public_id for admin and persists changes', function (): void {
    $admin = User::factory()->admin()->create(['public_id' => 'usr_ada']);
    $oldDev = User::factory()->developer()->create(['public_id' => 'usr_old', 'name' => 'Old Dev']);
    $grace = User::factory()->developer()->create([
        'public_id' => 'usr_grace',
        'name' => 'Grace Hopper',
        'email' => 'grace@ticket.dev',
        'specialty' => 'Backend',
    ]);
    $tim = User::factory()->developer()->create([
        'public_id' => 'usr_tim',
        'name' => 'Tim Berners-Lee',
        'email' => 'tim@ticket.dev',
        'specialty' => 'Frontend',
    ]);

    $system = System::factory()->create([
        'id' => 'app-velocepay',
        'name' => 'VelocePay Checkout',
        'domain' => 'checkout.veloce.io',
        'lead_user_id' => $admin->id,
    ]);
    $system->developers()->attach($oldDev->id);

    Sanctum::actingAs($admin);

    $response = $this->putJson('/api/systems/app-velocepay/developers', [
        'developerIds' => ['usr_tim', 'usr_grace', 'usr_tim'],
    ]);

    $response->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => 'app-velocepay',
                'name' => 'VelocePay Checkout',
                'domain' => 'checkout.veloce.io',
                'developers' => [
                    [
                        'id' => 'usr_grace',
                        'name' => 'Grace Hopper',
                        'email' => 'grace@ticket.dev',
                        'specialty' => 'Backend',
                    ],
                    [
                        'id' => 'usr_tim',
                        'name' => 'Tim Berners-Lee',
                        'email' => 'tim@ticket.dev',
                        'specialty' => 'Frontend',
                    ],
                ],
            ],
        ]);

    $this->assertDatabaseHas('system_user', [
        'system_id' => 'app-velocepay',
        'user_id' => $grace->id,
    ]);
    $this->assertDatabaseHas('system_user', [
        'system_id' => 'app-velocepay',
        'user_id' => $tim->id,
    ]);
    $this->assertDatabaseMissing('system_user', [
        'system_id' => 'app-velocepay',
        'user_id' => $oldDev->id,
    ]);
});

it('clears system developers when developerIds is an empty array', function (): void {
    $admin = User::factory()->admin()->create();
    $dev = User::factory()->developer()->create();
    $system = System::factory()->create([
        'id' => 'app-nexaportal',
        'lead_user_id' => $admin->id,
    ]);
    $system->developers()->attach($dev->id);

    Sanctum::actingAs($admin);

    $response = $this->putJson('/api/systems/app-nexaportal/developers', [
        'developerIds' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.developers', []);

    $this->assertDatabaseMissing('system_user', [
        'system_id' => 'app-nexaportal',
        'user_id' => $dev->id,
    ]);
});

it('forbids non-admin roles on systems and developers endpoints', function (UserRole $role, string $method, string $uri, array $payload): void {
    $user = User::factory()->create(['role' => $role]);
    System::factory()->create([
        'id' => 'app-nexaportal',
        'lead_user_id' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $response = $this->json($method, $uri, $payload);

    $response->assertForbidden()
        ->assertJson([
            'code' => 'ERR_FORBIDDEN',
        ])
        ->assertJsonStructure(['code', 'message', 'errors']);
})->with([
    'agent GET /systems' => [UserRole::Agent, 'GET', '/api/systems', []],
    'developer GET /systems' => [UserRole::Developer, 'GET', '/api/systems', []],
    'agent GET /developers' => [UserRole::Agent, 'GET', '/api/developers', []],
    'developer GET /developers' => [UserRole::Developer, 'GET', '/api/developers', []],
    'agent PUT /systems/{system}/developers' => [UserRole::Agent, 'PUT', '/api/systems/app-nexaportal/developers', ['developerIds' => []]],
    'developer PUT /systems/{system}/developers' => [UserRole::Developer, 'PUT', '/api/systems/app-nexaportal/developers', ['developerIds' => []]],
]);

it('rejects unauthenticated guests with ERR_UNAUTHENTICATED on assignments endpoints', function (string $method, string $uri): void {
    System::factory()->create(['id' => 'app-nexaportal']);

    $response = $this->json($method, $uri);

    $response->assertUnauthorized()
        ->assertJson([
            'code' => 'ERR_UNAUTHENTICATED',
        ])
        ->assertJsonStructure(['code', 'message', 'errors']);
})->with([
    'GET /systems' => ['GET', '/api/systems'],
    'GET /developers' => ['GET', '/api/developers'],
    'PUT /systems/{system}/developers' => ['PUT', '/api/systems/app-nexaportal/developers'],
]);

it('returns ERR_VALIDATION when developerIds is missing or contains unknown public_id', function (array $payload, string $expectedErrorKey): void {
    $admin = User::factory()->admin()->create();
    System::factory()->create([
        'id' => 'app-nexaportal',
        'lead_user_id' => $admin->id,
    ]);

    Sanctum::actingAs($admin);

    $response = $this->putJson('/api/systems/app-nexaportal/developers', $payload);

    $response->assertUnprocessable()
        ->assertJson([
            'code' => 'ERR_VALIDATION',
        ])
        ->assertJsonStructure(['code', 'message', 'errors' => [$expectedErrorKey]]);

    $errors = $response->json('errors');

    expect($errors)->toBeArray()
        ->and($errors[$expectedErrorKey] ?? null)->toBeString()->not->toBeEmpty();
})->with([
    'missing developerIds' => [[], 'developerIds'],
    'unknown public_id' => [['developerIds' => ['usr_nonexistent']], 'developerIds.0'],
]);

it('returns ERR_NOT_FOUND when syncing developers for an unknown system', function (): void {
    $admin = User::factory()->admin()->create(['public_id' => 'usr_ada']);

    Sanctum::actingAs($admin);

    $response = $this->putJson('/api/systems/app-unknown/developers', [
        'developerIds' => ['usr_ada'],
    ]);

    $response->assertNotFound()
        ->assertJson([
            'code' => 'ERR_NOT_FOUND',
        ])
        ->assertJsonStructure(['code', 'message', 'errors']);
});

it('enforces admin-only permissions in SystemPolicy', function (UserRole $role, bool $expected): void {
    $user = User::factory()->create(['role' => $role]);
    $system = System::factory()->create(['lead_user_id' => $user->id]);

    $gate = Gate::forUser($user);

    expect($gate->allows('viewAny', System::class))->toBe($expected)
        ->and($gate->allows('view', $system))->toBe($expected)
        ->and($gate->allows('syncDevelopers', $system))->toBe($expected);
})->with([
    'admin' => [UserRole::Admin, true],
    'agent' => [UserRole::Agent, false],
    'developer' => [UserRole::Developer, false],
]);
