<?php

use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

describe('project lead API key endpoints authentication and authorization', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED for unauthenticated guests', function (string $method, string $uri): void {
        System::factory()->create(['id' => 'app-sai']);

        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    })->with([
        'GET /api/systems/lead' => ['GET', '/api/systems/lead'],
        'GET /api/systems/{system}/api-key' => ['GET', '/api/systems/app-sai/api-key'],
        'POST /api/systems/{system}/api-key' => ['POST', '/api/systems/app-sai/api-key'],
    ]);

    it('forbids users who are not the system lead_user_id from viewing or rotating the API key', function (string $method, string $uri, string $role): void {
        $lead = User::factory()->developer()->create();
        $otherUser = User::factory()->create(['role' => $role]);

        $system = System::factory()->create([
            'id' => 'app-sai',
            'lead_user_id' => $lead->id,
            'api_key' => 'sk_sys_original_01',
        ]);
        $system->developers()->attach($otherUser->id);

        Sanctum::actingAs($otherUser);

        $this->json($method, $uri)
            ->assertForbidden()
            ->assertJsonPath('code', 'ERR_FORBIDDEN');

        expect($system->fresh()->api_key)->toBe('sk_sys_original_01');
    })->with([
        'assigned non-lead developer GET' => ['GET', '/api/systems/app-sai/api-key', 'developer'],
        'assigned non-lead developer POST' => ['POST', '/api/systems/app-sai/api-key', 'developer'],
        'non-lead agent GET' => ['GET', '/api/systems/app-sai/api-key', 'agent'],
        'non-lead agent POST' => ['POST', '/api/systems/app-sai/api-key', 'agent'],
        'non-lead admin GET' => ['GET', '/api/systems/app-sai/api-key', 'admin'],
        'non-lead admin POST' => ['POST', '/api/systems/app-sai/api-key', 'admin'],
    ]);

    it('enforces manageApiKey strictly for the assigned lead_user_id in SystemPolicy', function (): void {
        $lead = User::factory()->developer()->create();
        $admin = User::factory()->admin()->create();
        $system = System::factory()->create(['lead_user_id' => $lead->id]);

        expect(Gate::forUser($lead)->allows('manageApiKey', $system))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('manageApiKey', $system))->toBeFalse();
    });
});

describe('coordinator led systems and API key management', function (): void {
    it('lists only active systems coordinated by the authenticated user with their apiKey', function (): void {
        $lead = User::factory()->developer()->create();
        $otherLead = User::factory()->developer()->create();

        System::factory()->create([
            'id' => 'app-sai',
            'name' => 'SAI',
            'domain' => 'sai.ticket.dev',
            'lead_user_id' => $lead->id,
            'api_key' => 'sk_sys_sai_key',
        ]);
        System::factory()->create([
            'id' => 'app-dmp',
            'name' => 'DMP',
            'domain' => 'dmp.ticket.dev',
            'lead_user_id' => $lead->id,
            'api_key' => 'sk_sys_dmp_key',
        ]);
        $deletedLed = System::factory()->create([
            'id' => 'app-archived',
            'name' => 'Archived',
            'lead_user_id' => $lead->id,
        ]);
        $deletedLed->delete();

        $otherSystem = System::factory()->create([
            'id' => 'app-gensadi',
            'name' => 'GENSADI',
            'lead_user_id' => $otherLead->id,
            'api_key' => 'sk_sys_other_key',
        ]);
        $otherSystem->developers()->attach($lead->id);

        Sanctum::actingAs($lead);

        $response = $this->getJson('/api/systems/lead');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 'app-dmp')
            ->assertJsonPath('data.0.apiKey', 'sk_sys_dmp_key')
            ->assertJsonPath('data.1.id', 'app-sai')
            ->assertJsonPath('data.1.apiKey', 'sk_sys_sai_key');
    });

    it('allows the project coordinator to view the current system api_key', function (): void {
        $lead = User::factory()->developer()->create();
        System::factory()->create([
            'id' => 'app-sai',
            'name' => 'SAI',
            'domain' => 'sai.ticket.dev',
            'lead_user_id' => $lead->id,
            'api_key' => 'sk_sys_existing_01',
        ]);

        Sanctum::actingAs($lead);

        $this->getJson('/api/systems/app-sai/api-key')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'systemId' => 'app-sai',
                    'systemName' => 'SAI',
                    'domain' => 'sai.ticket.dev',
                    'apiKey' => 'sk_sys_existing_01',
                ],
            ]);
    });

    it('rotates the system api_key for its coordinator, invalidates the previous key, and enables the new key', function (): void {
        $lead = User::factory()->developer()->create();
        $system = System::factory()->create([
            'id' => 'app-sai',
            'name' => 'SAI',
            'domain' => 'sai.ticket.dev',
            'lead_user_id' => $lead->id,
            'api_key' => 'sk_sys_old_key_01',
        ]);

        Sanctum::actingAs($lead);

        $rotateResponse = $this->postJson('/api/systems/app-sai/api-key');

        $rotateResponse->assertOk()
            ->assertJsonPath('data.systemId', 'app-sai')
            ->assertJsonPath('data.systemName', 'SAI')
            ->assertJsonPath('data.domain', 'sai.ticket.dev');

        $newKey = $rotateResponse->json('data.apiKey');

        expect($newKey)->toBeString()
            ->toStartWith('sk_sys_')
            ->not->toBe('sk_sys_old_key_01')
            ->and(strlen((string) $newKey))->toBe(39)
            ->and($system->fresh()->api_key)->toBe($newKey);

        $this->withHeaders(['X-System-Key' => 'sk_sys_old_key_01'])
            ->postJson('/api/tickets', [
                'source' => 'pixibot',
                'issue_type' => 'bug',
                'description' => 'Intento con clave rotada debe fallar.',
                'module_route' => '/inicio',
                'reporter' => [
                    'id' => '1',
                    'name' => 'Usuario Uno',
                    'email' => 'u1@ticket.dev',
                ],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'ERR_FORBIDDEN');

        $this->withHeaders(['X-System-Key' => (string) $newKey])
            ->postJson('/api/tickets', [
                'source' => 'pixibot',
                'issue_type' => 'bug',
                'description' => 'Reporte valido utilizando la nueva clave rotada.',
                'module_route' => '/inicio',
                'reporter' => [
                    'id' => '1',
                    'name' => 'Usuario Uno',
                    'email' => 'u1@ticket.dev',
                ],
            ])
            ->assertCreated();
    });

    it('returns 404 ERR_NOT_FOUND for unknown or soft-deleted systems on api-key endpoints', function (): void {
        $lead = User::factory()->developer()->create();
        $deleted = System::factory()->create([
            'id' => 'app-deleted',
            'lead_user_id' => $lead->id,
        ]);
        $deleted->delete();

        Sanctum::actingAs($lead);

        $this->getJson('/api/systems/app-unknown/api-key')
            ->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND');

        $this->postJson('/api/systems/app-deleted/api-key')
            ->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });
});
