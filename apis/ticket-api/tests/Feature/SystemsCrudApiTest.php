<?php

use App\Enums\AppHealth;
use App\Enums\TicketSeverity;
use App\Enums\UserRole;
use App\Models\Reporter;
use App\Models\System;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

describe('authentication and authorization', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED for unauthenticated guests on system CRUD endpoints', function (string $method, string $uri): void {
        System::factory()->create(['id' => 'app-nexaportal']);

        $response = $this->json($method, $uri);

        $response->assertUnauthorized()
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED')
            ->assertJsonStructure(['code', 'message', 'errors']);
    })->with([
        'GET /systems/{system}' => ['GET', '/api/systems/app-nexaportal'],
        'PUT /systems/{system}' => ['PUT', '/api/systems/app-nexaportal'],
        'PATCH /systems/{system}' => ['PATCH', '/api/systems/app-nexaportal'],
        'DELETE /systems/{system}' => ['DELETE', '/api/systems/app-nexaportal'],
    ]);

    it('returns 403 ERR_FORBIDDEN for non-admin roles on system CRUD endpoints', function (UserRole $role, string $method, string $uri, array $payload): void {
        $user = User::factory()->create(['role' => $role]);
        System::factory()->create([
            'id' => 'app-nexaportal',
            'lead_user_id' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->json($method, $uri, $payload);

        $response->assertForbidden()
            ->assertJsonPath('code', 'ERR_FORBIDDEN')
            ->assertJsonStructure(['code', 'message', 'errors']);
    })->with([
        'agent GET /systems/{system}' => [UserRole::Agent, 'GET', '/api/systems/app-nexaportal', []],
        'developer GET /systems/{system}' => [UserRole::Developer, 'GET', '/api/systems/app-nexaportal', []],
        'agent PUT /systems/{system}' => [UserRole::Agent, 'PUT', '/api/systems/app-nexaportal', ['name' => 'Updated']],
        'developer PUT /systems/{system}' => [UserRole::Developer, 'PUT', '/api/systems/app-nexaportal', ['name' => 'Updated']],
        'agent PATCH /systems/{system}' => [UserRole::Agent, 'PATCH', '/api/systems/app-nexaportal', ['name' => 'Updated']],
        'developer PATCH /systems/{system}' => [UserRole::Developer, 'PATCH', '/api/systems/app-nexaportal', ['name' => 'Updated']],
        'agent DELETE /systems/{system}' => [UserRole::Agent, 'DELETE', '/api/systems/app-nexaportal', []],
        'developer DELETE /systems/{system}' => [UserRole::Developer, 'DELETE', '/api/systems/app-nexaportal', []],
    ]);

    it('enforces admin-only permissions in SystemPolicy for view, update, and delete', function (UserRole $role, bool $expected): void {
        $user = User::factory()->create(['role' => $role]);
        $system = System::factory()->create(['lead_user_id' => $user->id]);

        $gate = Gate::forUser($user);

        expect($gate->allows('view', $system))->toBe($expected)
            ->and($gate->allows('update', $system))->toBe($expected)
            ->and($gate->allows('delete', $system))->toBe($expected);
    })->with([
        'admin' => [UserRole::Admin, true],
        'agent' => [UserRole::Agent, false],
        'developer' => [UserRole::Developer, false],
    ]);
});

describe('GET /api/systems/{system}', function (): void {
    it('returns system details with lead and developers ordered by name wrapped in data', function (): void {
        $this->travelTo('2026-11-15 12:00:00');

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
        $grace = User::factory()->developer()->create([
            'public_id' => 'usr_grace',
            'name' => 'Grace Hopper',
            'email' => 'grace@ticket.dev',
            'specialty' => 'Backend',
        ]);

        $system = System::factory()->create([
            'id' => 'app-nexaportal',
            'name' => 'NexaPortal Clientes',
            'domain' => 'clientes.nexa.io',
            'lead_user_id' => $admin->id,
            'health' => AppHealth::Degraded,
            'uptime' => 98.45,
        ]);
        $system->developers()->attach([$tim->id, $grace->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/systems/app-nexaportal');

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => 'app-nexaportal',
                    'name' => 'NexaPortal Clientes',
                    'domain' => 'clientes.nexa.io',
                    'health' => 'degraded',
                    'uptime' => 98.45,
                    'lead' => [
                        'id' => 'usr_ada',
                        'name' => 'Ada Lovelace',
                        'email' => 'admin@ticket.dev',
                        'specialty' => 'Plataforma',
                    ],
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
                    'createdAt' => '2026-11-15T12:00:00.000000Z',
                    'updatedAt' => '2026-11-15T12:00:00.000000Z',
                ],
            ]);
    });

    it('returns null lead when system has no assigned lead', function (): void {
        $admin = User::factory()->admin()->create();
        System::factory()->create([
            'id' => 'app-unassigned',
            'lead_user_id' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/systems/app-unassigned');

        $response->assertOk()
            ->assertJsonPath('data.id', 'app-unassigned')
            ->assertJsonPath('data.lead', null)
            ->assertJsonPath('data.developers', []);
    });

    it('returns 404 ERR_NOT_FOUND for unknown or soft-deleted system', function (): void {
        $admin = User::factory()->admin()->create();
        $deletedSystem = System::factory()->create(['id' => 'app-archived']);
        $deletedSystem->delete();

        Sanctum::actingAs($admin);

        $this->getJson('/api/systems/app-unknown')
            ->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND')
            ->assertJsonStructure(['code', 'message', 'errors']);

        $this->getJson('/api/systems/app-archived')
            ->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND')
            ->assertJsonStructure(['code', 'message', 'errors']);
    });
});

describe('PUT and PATCH /api/systems/{system}', function (): void {
    it('updates name, domain, leadId, health, and uptime via PUT and persists changes', function (): void {
        $admin = User::factory()->admin()->create(['public_id' => 'usr_ada']);
        $newLead = User::factory()->developer()->create([
            'public_id' => 'usr_grace',
            'name' => 'Grace Hopper',
            'email' => 'grace@ticket.dev',
            'specialty' => 'Backend',
        ]);
        $dev = User::factory()->developer()->create([
            'public_id' => 'usr_tim',
            'name' => 'Tim Berners-Lee',
            'email' => 'tim@ticket.dev',
            'specialty' => 'Frontend',
        ]);

        $system = System::factory()->create([
            'id' => 'app-velocepay',
            'name' => 'VelocePay Legacy',
            'domain' => 'legacy.veloce.io',
            'lead_user_id' => $admin->id,
            'health' => AppHealth::Operational,
            'uptime' => 99.90,
        ]);
        $system->developers()->attach($dev->id);

        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/systems/app-velocepay', [
            'name' => 'VelocePay Checkout',
            'domain' => 'checkout.veloce.io',
            'leadId' => 'usr_grace',
            'health' => AppHealth::Alert->value,
            'uptime' => 93.75,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', 'app-velocepay')
            ->assertJsonPath('data.name', 'VelocePay Checkout')
            ->assertJsonPath('data.domain', 'checkout.veloce.io')
            ->assertJsonPath('data.health', 'alert')
            ->assertJsonPath('data.uptime', 93.75)
            ->assertJsonPath('data.lead', [
                'id' => 'usr_grace',
                'name' => 'Grace Hopper',
                'email' => 'grace@ticket.dev',
                'specialty' => 'Backend',
            ])
            ->assertJsonPath('data.developers.0.id', 'usr_tim');

        $this->assertDatabaseHas('systems', [
            'id' => 'app-velocepay',
            'name' => 'VelocePay Checkout',
            'domain' => 'checkout.veloce.io',
            'lead_user_id' => $newLead->id,
            'health' => AppHealth::Alert->value,
            'uptime' => 93.75,
        ]);
    });

    it('supports partial updates and clearing leadId with null via PATCH', function (): void {
        $admin = User::factory()->admin()->create();
        $system = System::factory()->create([
            'id' => 'app-kromashop',
            'name' => 'KromaShop B2B',
            'domain' => 'b2b.kromashop.io',
            'lead_user_id' => $admin->id,
            'health' => AppHealth::Operational,
            'uptime' => 99.50,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/systems/app-kromashop', [
            'leadId' => null,
            'health' => AppHealth::Degraded->value,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', 'app-kromashop')
            ->assertJsonPath('data.name', 'KromaShop B2B')
            ->assertJsonPath('data.domain', 'b2b.kromashop.io')
            ->assertJsonPath('data.health', 'degraded')
            ->assertJsonPath('data.uptime', 99.5)
            ->assertJsonPath('data.lead', null);

        $system->refresh();
        expect($system->lead_user_id)->toBeNull()
            ->and($system->health)->toBe(AppHealth::Degraded);
    });

    it('allows keeping the system own name and domain on update', function (): void {
        $admin = User::factory()->admin()->create(['public_id' => 'usr_ada']);
        System::factory()->create([
            'id' => 'app-pulsehr',
            'name' => 'PulseHR Nomina',
            'domain' => 'app.pulsehr.io',
            'lead_user_id' => $admin->id,
            'health' => AppHealth::Operational,
            'uptime' => 99.90,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/systems/app-pulsehr', [
            'name' => 'PulseHR Nomina',
            'domain' => 'app.pulsehr.io',
            'leadId' => 'usr_ada',
            'health' => AppHealth::Degraded->value,
            'uptime' => 97.25,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'PulseHR Nomina')
            ->assertJsonPath('data.domain', 'app.pulsehr.io')
            ->assertJsonPath('data.health', 'degraded')
            ->assertJsonPath('data.uptime', 97.25);
    });

    it('returns 422 ERR_VALIDATION with flat errors when colliding with another system or given invalid fields', function (array $payload, string $expectedErrorKey): void {
        $admin = User::factory()->admin()->create();
        System::factory()->create([
            'id' => 'app-existing',
            'name' => 'Existing System',
            'domain' => 'existing.example.io',
        ]);
        $target = System::factory()->create([
            'id' => 'app-target',
            'name' => 'Target System',
            'domain' => 'target.example.io',
            'health' => AppHealth::Operational,
            'uptime' => 99.90,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/systems/app-target', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['code', 'message', 'errors' => [$expectedErrorKey]]);

        $errors = $response->json('errors');
        expect($errors)->toBeArray()
            ->and($errors[$expectedErrorKey] ?? null)->toBeString()->not->toBeEmpty();

        $target->refresh();
        expect($target->name)->toBe('Target System')
            ->and($target->domain)->toBe('target.example.io')
            ->and($target->health)->toBe(AppHealth::Operational);
    })->with([
        'duplicate name' => [['name' => 'Existing System'], 'name'],
        'duplicate domain' => [['domain' => 'existing.example.io'], 'domain'],
        'unknown leadId' => [['leadId' => 'usr_nonexistent'], 'leadId'],
        'invalid health' => [['health' => 'offline'], 'health'],
        'uptime below 0' => [['uptime' => -0.1], 'uptime'],
        'uptime above 100' => [['uptime' => 100.01], 'uptime'],
        'non-numeric uptime' => [['uptime' => 'ninety-nine'], 'uptime'],
        'empty name' => [['name' => ''], 'name'],
        'empty domain' => [['domain' => ''], 'domain'],
    ]);

    it('returns 404 ERR_NOT_FOUND when updating an unknown or soft-deleted system', function (): void {
        $admin = User::factory()->admin()->create();
        $deleted = System::factory()->create(['id' => 'app-deleted']);
        $deleted->delete();

        Sanctum::actingAs($admin);

        $this->putJson('/api/systems/app-unknown', ['name' => 'Ghost'])
            ->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND')
            ->assertJsonStructure(['code', 'message', 'errors']);

        $this->patchJson('/api/systems/app-deleted', ['name' => 'Revived'])
            ->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND')
            ->assertJsonStructure(['code', 'message', 'errors']);
    });
});

describe('DELETE /api/systems/{system}', function (): void {
    it('soft-deletes system, cascades soft-delete to its tickets, detaches pivot rows while keeping users intact, excludes from downstream endpoints, and avoids INC code collision', function (): void {
        $admin = User::factory()->admin()->create([
            'public_id' => 'usr_ada',
            'name' => 'Ada Lovelace',
        ]);
        $developer = User::factory()->developer()->create([
            'public_id' => 'usr_grace',
            'name' => 'Grace Hopper',
        ]);
        $reporter = Reporter::factory()->create();

        $doomedSystem = System::factory()->create([
            'id' => 'app-doomed',
            'name' => 'Doomed Portal',
            'domain' => 'doomed.example.io',
            'lead_user_id' => $admin->id,
        ]);
        $survivingSystem = System::factory()->create([
            'id' => 'app-surviving',
            'name' => 'Surviving Portal',
            'domain' => 'surviving.example.io',
            'lead_user_id' => $admin->id,
            'api_key' => 'sk_sys_surviving_01',
        ]);

        $doomedSystem->developers()->attach([$admin->id, $developer->id]);
        $survivingSystem->developers()->attach([$admin->id, $developer->id]);

        $doomedTicketOne = Ticket::factory()->for($doomedSystem)->for($reporter)->open()->create([
            'code' => 'INC-2050',
            'severity' => TicketSeverity::P1,
        ]);
        $doomedTicketTwo = Ticket::factory()->for($doomedSystem)->for($reporter)->inProgress()->create([
            'code' => 'INC-2099',
            'severity' => TicketSeverity::P2,
        ]);
        $survivingTicket = Ticket::factory()->for($survivingSystem)->for($reporter)->open()->create([
            'code' => 'INC-2040',
            'severity' => TicketSeverity::P3,
        ]);

        Sanctum::actingAs($admin);

        $deleteResponse = $this->deleteJson('/api/systems/app-doomed');

        $deleteResponse->assertNoContent();

        $this->assertSoftDeleted('systems', ['id' => 'app-doomed']);
        $this->assertNotSoftDeleted('systems', ['id' => 'app-surviving']);

        $this->assertSoftDeleted('tickets', ['id' => $doomedTicketOne->id]);
        $this->assertSoftDeleted('tickets', ['id' => $doomedTicketTwo->id]);
        $this->assertNotSoftDeleted('tickets', ['id' => $survivingTicket->id]);

        $this->assertDatabaseMissing('system_user', ['system_id' => 'app-doomed']);
        $this->assertDatabaseHas('system_user', [
            'system_id' => 'app-surviving',
            'user_id' => $developer->id,
        ]);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('users', ['id' => $developer->id]);

        $this->getJson('/api/systems/app-doomed')
            ->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND');

        $this->getJson('/api/tickets/INC-2050')
            ->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND');

        $this->getJson('/api/systems')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'app-surviving');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.assignedSystems', ['Surviving Portal']);

        $dashboardResponse = $this->getJson('/api/dashboard');
        $dashboardResponse->assertOk()
            ->assertJsonCount(1, 'data.apps')
            ->assertJsonPath('data.apps.0.id', 'app-surviving')
            ->assertJsonCount(1, 'data.userIncidents')
            ->assertJsonPath('data.userIncidents.0.id', 'INC-2040');

        $this->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', 'INC-2040')
            ->assertJsonPath('data.meta.total', 1);

        $this->getJson('/api/tickets/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.criticalP1', 0)
            ->assertJsonPath('data.inProgress', 1)
            ->assertJsonPath('data.systems', ['Surviving Portal']);

        $widgetResponse = $this->withHeaders([
            'X-System-Key' => 'sk_sys_surviving_01',
        ])->postJson('/api/tickets', [
            'description' => 'New incident after deleting another system.',
            'issueType' => 'slow',
            'reporterName' => 'Elena Navarro',
            'reporterEmail' => 'elena@example.io',
        ]);

        $widgetResponse->assertCreated()
            ->assertJsonPath('data.id', 'INC-2100')
            ->assertJsonPath('data.system', 'Surviving Portal');
    });

    it('returns 404 ERR_NOT_FOUND when deleting an unknown or already soft-deleted system', function (): void {
        $admin = User::factory()->admin()->create();
        $deleted = System::factory()->create(['id' => 'app-already-deleted']);
        $deleted->delete();

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/systems/app-unknown')
            ->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND');

        $this->deleteJson('/api/systems/app-already-deleted')
            ->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });
});
