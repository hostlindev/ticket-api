<?php

use App\Enums\IssueType;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Models\Reporter;
use App\Models\System;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Valid 1x1 transparent PNG bytes encoded as base64 for deterministic upload tests.
 */
const TEST_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

describe('authentication', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED for unauthenticated protected ticket endpoints', function (string $method, string $uri): void {
        $response = $this->json($method, $uri);

        $response->assertUnauthorized()
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED')
            ->assertJsonStructure(['code', 'message', 'errors']);
    })->with([
        'list tickets' => ['GET', '/api/tickets'],
        'tickets summary' => ['GET', '/api/tickets/summary'],
        'show ticket' => ['GET', '/api/tickets/INC-2001'],
        'update status' => ['PATCH', '/api/tickets/INC-2001/status'],
    ]);
});

describe('GET /api/tickets', function (): void {
    it('paginates tickets ordered by created_at DESC wrapped in a data envelope', function (): void {
        $user = User::factory()->developer()->create();
        Sanctum::actingAs($user);

        $system = System::factory()->create(['name' => 'NexaPortal Clientes']);
        $system->developers()->attach($user->id);
        $reporter = Reporter::factory()->create(['name' => 'Lucia Mendoza', 'email' => 'lucia@acme.test']);
        $assignee = User::factory()->agent()->create([
            'public_id' => 'usr_margaret',
            'name' => 'Margaret Hamilton',
            'email' => 'margaret@ticket.dev',
            'specialty' => 'QA',
        ]);

        $this->travelTo(now()->startOfSecond());

        $oldest = Ticket::factory()->for($system)->for($reporter)->create([
            'code' => 'INC-1001',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $middle = Ticket::factory()->for($system)->for($reporter)->create([
            'code' => 'INC-1002',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $newest = Ticket::factory()->for($system)->for($reporter)->create([
            'code' => 'INC-1003',
            'assignee_id' => $assignee->id,
            'issue_type' => IssueType::BlocksFlow,
            'severity' => TicketSeverity::P1,
            'status' => TicketStatus::Open,
            'sla_due_at' => now()->addMinutes(45),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create 9 more older tickets so total is 12 and pageSize=10 yields 2 pages.
        Ticket::factory()->count(9)->for($system)->for($reporter)->create([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $pageOne = $this->getJson('/api/tickets?page=1&pageSize=10');

        $pageOne->assertOk()
            ->assertJsonCount(10, 'data.items')
            ->assertJsonPath('data.meta', [
                'page' => 1,
                'pageSize' => 10,
                'total' => 12,
                'totalPages' => 2,
            ])
            ->assertJsonPath('data.items.0.id', $newest->code)
            ->assertJsonPath('data.items.0.issueType', 'blocks_flow')
            ->assertJsonPath('data.items.0.severity', 'P1')
            ->assertJsonPath('data.items.0.status', 'open')
            ->assertJsonPath('data.items.0.system', 'NexaPortal Clientes')
            ->assertJsonPath('data.items.0.reporter', [
                'id' => $reporter->id,
                'name' => 'Lucia Mendoza',
                'email' => 'lucia@acme.test',
            ])
            ->assertJsonPath('data.items.0.assignee', [
                'id' => 'usr_margaret',
                'name' => 'Margaret Hamilton',
                'email' => 'margaret@ticket.dev',
                'role' => 'QA',
            ])
            ->assertJsonPath('data.items.0.slaMinutesRemaining', 45)
            ->assertJsonPath('data.items.1.id', $middle->code)
            ->assertJsonPath('data.items.2.id', $oldest->code);

        $pageTwo = $this->getJson('/api/tickets?page=2&pageSize=10');

        $pageTwo->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.page', 2);
    });

    it('filters by search across code, description, system name, and reporter name or email', function (): void {
        Sanctum::actingAs(User::factory()->agent()->create());

        $nexa = System::factory()->create(['name' => 'NexaPortal Clientes']);
        $kroma = System::factory()->create(['name' => 'KromaShop B2B']);

        $lucia = Reporter::factory()->create(['name' => 'Lucia Mendoza', 'email' => 'lucia@acmecorp.es']);
        $carlos = Reporter::factory()->create(['name' => 'Carlos Paredes', 'email' => 'carlos@norte.com']);

        Ticket::factory()->for($nexa)->for($lucia)->create([
            'code' => 'INC-2001',
            'description' => 'Error al confirmar tarjeta corporativa 3DS.',
        ]);
        Ticket::factory()->for($kroma)->for($carlos)->create([
            'code' => 'INC-2002',
            'description' => 'Catálogo B2B tarda en cargar.',
        ]);

        $this->getJson('/api/tickets?search=INC-2002')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', 'INC-2002');

        $this->getJson('/api/tickets?search=corporativa')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', 'INC-2001');

        $this->getJson('/api/tickets?search=KromaShop')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', 'INC-2002');

        $this->getJson('/api/tickets?search=Lucia')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', 'INC-2001');

        $this->getJson('/api/tickets?search=carlos@norte.com')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', 'INC-2002');
    });

    it('filters by severity, status, system, and issueType and rejects the removed pending severity', function (): void {
        Sanctum::actingAs(User::factory()->admin()->create());

        $veloce = System::factory()->create(['name' => 'VelocePay Checkout']);
        $pulse = System::factory()->create(['name' => 'PulseHR Nomina']);
        $reporter = Reporter::factory()->create();

        $p4Ticket = Ticket::factory()->for($veloce)->for($reporter)->open()->create([
            'code' => 'INC-3001',
            'severity' => TicketSeverity::P4,
            'issue_type' => IssueType::Other,
        ]);

        $p1Ticket = Ticket::factory()->for($veloce)->for($reporter)->inProgress()->create([
            'code' => 'INC-3002',
            'severity' => TicketSeverity::P1,
            'issue_type' => IssueType::BlocksFlow,
        ]);

        $p3Ticket = Ticket::factory()->for($pulse)->for($reporter)->resolved()->create([
            'code' => 'INC-3003',
            'severity' => TicketSeverity::P3,
            'issue_type' => IssueType::Slow,
        ]);

        $p2Ticket = Ticket::factory()->for($veloce)->for($reporter)->inReview()->create([
            'code' => 'INC-3004',
            'severity' => TicketSeverity::P2,
            'issue_type' => IssueType::NotWorking,
        ]);

        $this->getJson('/api/tickets?severity=pending')
            ->assertUnprocessable();

        $this->getJson('/api/tickets?issueType=other')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.severity', 'P4');

        $this->getJson('/api/tickets?severity=P1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $p1Ticket->code);

        $this->getJson('/api/tickets?severity=P2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $p2Ticket->code);

        $this->getJson('/api/tickets?severity=P3')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $p3Ticket->code);

        $this->getJson('/api/tickets?severity=P4')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $p4Ticket->code);

        $this->getJson('/api/tickets?status=in_progress')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $p1Ticket->code);

        $this->getJson('/api/tickets?system=PulseHR+Nomina')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', 'INC-3003');

        $this->getJson('/api/tickets?issueType=blocks_flow')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $p1Ticket->code);

        $this->getJson('/api/tickets?severity=all&status=all&issueType=all')
            ->assertOk()
            ->assertJsonCount(4, 'data.items');
    });

    it('returns 422 ERR_VALIDATION when index query parameters are invalid', function (): void {
        Sanctum::actingAs(User::factory()->developer()->create());

        $response = $this->getJson('/api/tickets?pageSize=15&severity=P9');

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['code', 'message', 'errors' => ['pageSize', 'severity']]);
    });

    it('scopes ticket list to assigned systems for developers while keeping agent and admin global', function (): void {
        $assignedSystem = System::factory()->create(['name' => 'AssignedApp']);
        $unassignedSystem = System::factory()->create(['name' => 'UnassignedApp']);
        $reporter = Reporter::factory()->create();

        Ticket::factory()->for($assignedSystem)->for($reporter)->create(['code' => 'INC-4001']);
        Ticket::factory()->for($unassignedSystem)->for($reporter)->create(['code' => 'INC-4002']);

        $developer = User::factory()->developer()->create();
        $assignedSystem->developers()->attach($developer->id);

        Sanctum::actingAs($developer);

        $this->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', 'INC-4001');

        $this->getJson('/api/tickets?system=UnassignedApp')
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.meta.total', 0);

        Sanctum::actingAs(User::factory()->agent()->create());

        $this->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.total', 2);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.total', 2);
    });

    it('returns empty items when a developer has no assigned systems', function (): void {
        $system = System::factory()->create(['name' => 'UnassignedApp']);
        Ticket::factory()->for($system)->create(['code' => 'INC-4003']);

        Sanctum::actingAs(User::factory()->developer()->create());

        $this->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.meta.total', 0);
    });
});

describe('GET /api/tickets/summary', function (): void {
    it('returns accurate KPI counts and alphabetically sorted systems with tickets', function (): void {
        Sanctum::actingAs(User::factory()->admin()->create());

        $zeta = System::factory()->create(['name' => 'Zeta App']);
        $alpha = System::factory()->create(['name' => 'Alpha App']);
        System::factory()->create(['name' => 'Empty App']);
        $reporter = Reporter::factory()->create();

        // Active P1 open -> counts in total, criticalP1, inProgress
        Ticket::factory()->for($zeta)->for($reporter)->open()->withSeverity(TicketSeverity::P1)->create();
        // Active P1 in_review -> counts in total, criticalP1 (not inProgress per spec)
        Ticket::factory()->for($alpha)->for($reporter)->inReview()->withSeverity(TicketSeverity::P1)->create();
        // Resolved P1 -> counts in total, resolved (NOT criticalP1)
        Ticket::factory()->for($alpha)->for($reporter)->resolved()->withSeverity(TicketSeverity::P1)->create();
        // Active P2 in_progress -> counts in total, inProgress
        Ticket::factory()->for($alpha)->for($reporter)->inProgress()->withSeverity(TicketSeverity::P2)->create();
        // Closed P4 -> counts in total, resolved
        Ticket::factory()->for($zeta)->for($reporter)->closed()->withSeverity(TicketSeverity::P4)->create();

        $response = $this->getJson('/api/tickets/summary');

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    'total' => 5,
                    'criticalP1' => 2,
                    'lowPriority' => 0,
                    'inProgress' => 2,
                    'resolved' => 2,
                    'systems' => ['Alpha App', 'Zeta App'],
                ],
            ]);
    });

    it('scopes KPI counts and systems options to assigned systems for developers while keeping agent and admin global', function (): void {
        $assignedAlpha = System::factory()->create(['name' => 'Assigned Alpha']);
        $assignedEmpty = System::factory()->create(['name' => 'Assigned Empty']);
        $unassignedBeta = System::factory()->create(['name' => 'Unassigned Beta']);
        $reporter = Reporter::factory()->create();

        $developer = User::factory()->developer()->create();
        $developer->systems()->attach([$assignedAlpha->id, $assignedEmpty->id]);

        // Assigned system tickets: 1 active P1 open, 1 resolved P3
        Ticket::factory()->for($assignedAlpha)->for($reporter)->open()->withSeverity(TicketSeverity::P1)->create();
        Ticket::factory()->for($assignedAlpha)->for($reporter)->resolved()->withSeverity(TicketSeverity::P3)->create();

        // Unassigned system tickets: should be excluded for the developer
        Ticket::factory()->for($unassignedBeta)->for($reporter)->open()->withSeverity(TicketSeverity::P1)->create();
        Ticket::factory()->for($unassignedBeta)->for($reporter)->inProgress()->withSeverity(TicketSeverity::P2)->create();
        Ticket::factory()->for($unassignedBeta)->for($reporter)->closed()->withSeverity(TicketSeverity::P4)->create();

        Sanctum::actingAs($developer);

        $this->getJson('/api/tickets/summary')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'total' => 2,
                    'criticalP1' => 1,
                    'lowPriority' => 0,
                    'inProgress' => 1,
                    'resolved' => 1,
                    'systems' => ['Assigned Alpha'],
                ],
            ]);

        Sanctum::actingAs(User::factory()->agent()->create());

        $this->getJson('/api/tickets/summary')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'total' => 5,
                    'criticalP1' => 2,
                    'lowPriority' => 0,
                    'inProgress' => 3,
                    'resolved' => 2,
                    'systems' => ['Assigned Alpha', 'Unassigned Beta'],
                ],
            ]);
    });

    it('returns zeroed KPI counts and empty systems array when a developer has no assigned systems', function (): void {
        $system = System::factory()->create(['name' => 'Unassigned System']);
        Ticket::factory()->for($system)->open()->withSeverity(TicketSeverity::P1)->create();

        Sanctum::actingAs(User::factory()->developer()->create());

        $this->getJson('/api/tickets/summary')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'total' => 0,
                    'criticalP1' => 0,
                    'lowPriority' => 0,
                    'inProgress' => 0,
                    'resolved' => 0,
                    'systems' => [],
                ],
            ]);
    });
});

describe('GET /api/tickets/{code}', function (): void {
    it('returns a single ticket DTO by code wrapped in a data envelope', function (): void {
        Sanctum::actingAs(User::factory()->agent()->create());

        $this->travelTo(now()->startOfSecond());
        $system = System::factory()->create(['name' => 'OrionCRM Ventas']);
        $reporter = Reporter::factory()->create([
            'id' => 'usr_8129',
            'name' => 'Diego Santamaria',
            'email' => 'diego@orion.com',
        ]);

        $ticket = Ticket::factory()->for($system)->for($reporter)->open()->create([
            'code' => 'INC-2040',
            'severity' => TicketSeverity::P2,
            'sla_due_at' => now()->addMinutes(120),
            'assignee_id' => null,
        ]);

        $response = $this->getJson('/api/tickets/'.$ticket->code);

        $response->assertOk()
            ->assertJsonPath('data.id', 'INC-2040')
            ->assertJsonPath('data.system', 'OrionCRM Ventas')
            ->assertJsonPath('data.reporter.id', 'usr_8129')
            ->assertJsonPath('data.assignee', null)
            ->assertJsonPath('data.slaMinutesRemaining', 120);
    });

    it('returns 404 ERR_NOT_FOUND when ticket code does not exist', function (): void {
        Sanctum::actingAs(User::factory()->developer()->create());

        $response = $this->getJson('/api/tickets/INC-999999');

        $response->assertNotFound()
            ->assertJsonPath('code', 'ERR_NOT_FOUND')
            ->assertJsonStructure(['code', 'message', 'errors']);
    });

    it('allows developers to view tickets in assigned systems and returns 403 ERR_FORBIDDEN for unassigned systems while agent and admin remain global', function (): void {
        $assignedSystem = System::factory()->create(['name' => 'Assigned System']);
        $unassignedSystem = System::factory()->create(['name' => 'Unassigned System']);
        $reporter = Reporter::factory()->create();

        $assignedTicket = Ticket::factory()->for($assignedSystem)->for($reporter)->create(['code' => 'INC-5001']);
        $unassignedTicket = Ticket::factory()->for($unassignedSystem)->for($reporter)->create(['code' => 'INC-5002']);

        $developer = User::factory()->developer()->create();
        $assignedSystem->developers()->attach($developer->id);

        Sanctum::actingAs($developer);

        $this->getJson('/api/tickets/'.$assignedTicket->code)
            ->assertOk()
            ->assertJsonPath('data.id', 'INC-5001');

        $this->getJson('/api/tickets/'.$unassignedTicket->code)
            ->assertForbidden()
            ->assertJsonPath('code', 'ERR_FORBIDDEN')
            ->assertJsonStructure(['code', 'message', 'errors']);

        Sanctum::actingAs(User::factory()->agent()->create());

        $this->getJson('/api/tickets/'.$unassignedTicket->code)
            ->assertOk()
            ->assertJsonPath('data.id', 'INC-5002');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/tickets/'.$unassignedTicket->code)
            ->assertOk()
            ->assertJsonPath('data.id', 'INC-5002');
    });
});

describe('PATCH /api/tickets/{code}/status', function (): void {
    it('sets resolved_at and clears slaMinutesRemaining when resolving and clears resolved_at when reopening', function (): void {
        $user = User::factory()->developer()->create();
        Sanctum::actingAs($user);

        $this->travelTo(now()->startOfSecond());

        $system = System::factory()->create();
        $system->developers()->attach($user->id);

        $ticket = Ticket::factory()->for($system)->open()->create([
            'code' => 'INC-2050',
            'severity' => TicketSeverity::P1,
            'sla_due_at' => now()->addMinutes(50),
            'resolved_at' => null,
        ]);

        $resolveResponse = $this->patchJson('/api/tickets/INC-2050/status', [
            'status' => TicketStatus::Resolved->value,
        ]);

        $resolveResponse->assertOk()
            ->assertJsonPath('data.id', 'INC-2050')
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.slaMinutesRemaining', null);

        $ticket->refresh();
        expect($ticket->status)->toBe(TicketStatus::Resolved);
        expect($ticket->resolved_at)->not->toBeNull();

        $reopenResponse = $this->patchJson('/api/tickets/INC-2050/status', [
            'status' => TicketStatus::InReview->value,
        ]);

        $reopenResponse->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.slaMinutesRemaining', 50);

        $ticket->refresh();
        expect($ticket->status)->toBe(TicketStatus::InReview);
        expect($ticket->resolved_at)->toBeNull();
    });

    it('returns 422 ERR_VALIDATION when status is invalid', function (): void {
        Sanctum::actingAs(User::factory()->admin()->create());

        Ticket::factory()->create(['code' => 'INC-2051']);

        $response = $this->patchJson('/api/tickets/INC-2051/status', [
            'status' => 'archived',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['code', 'message', 'errors' => ['status']]);
    });

    it('allows developers to update status for assigned system tickets and returns 403 ERR_FORBIDDEN for unassigned systems while agent and admin remain global', function (): void {
        $assignedSystem = System::factory()->create(['name' => 'Assigned System']);
        $unassignedSystem = System::factory()->create(['name' => 'Unassigned System']);
        $reporter = Reporter::factory()->create();

        $assignedTicket = Ticket::factory()->for($assignedSystem)->for($reporter)->open()->create(['code' => 'INC-5003']);
        $unassignedTicket = Ticket::factory()->for($unassignedSystem)->for($reporter)->open()->create(['code' => 'INC-5004']);

        $developer = User::factory()->developer()->create();
        $assignedSystem->developers()->attach($developer->id);

        Sanctum::actingAs($developer);

        $this->patchJson('/api/tickets/'.$assignedTicket->code.'/status', [
            'status' => TicketStatus::Resolved->value,
        ])->assertOk()
            ->assertJsonPath('data.id', 'INC-5003')
            ->assertJsonPath('data.status', 'resolved');

        $this->patchJson('/api/tickets/'.$unassignedTicket->code.'/status', [
            'status' => TicketStatus::Resolved->value,
        ])->assertForbidden()
            ->assertJsonPath('code', 'ERR_FORBIDDEN')
            ->assertJsonStructure(['code', 'message', 'errors']);

        expect($unassignedTicket->fresh()?->status)->toBe(TicketStatus::Open);

        Sanctum::actingAs(User::factory()->agent()->create());

        $this->patchJson('/api/tickets/'.$unassignedTicket->code.'/status', [
            'status' => TicketStatus::InReview->value,
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_review');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson('/api/tickets/'.$unassignedTicket->code.'/status', [
            'status' => TicketStatus::Resolved->value,
        ])->assertOk()
            ->assertJsonPath('data.status', 'resolved');
    });
});

describe('POST /api/tickets', function (): void {
    it('creates a ticket via X-System-Key with heuristic severity, SLA, and multipart screenshot upload', function (): void {
        Storage::fake('public');
        $this->travelTo(now()->startOfSecond());

        $system = System::factory()->create([
            'id' => 'app-nexaportal',
            'name' => 'NexaPortal Clientes',
            'api_key' => 'sk_sys_test_key_01',
        ]);

        Ticket::factory()->for($system)->create(['code' => 'INC-2056']);

        $file = UploadedFile::fake()->createWithContent(
            'screenshot.png',
            (string) base64_decode(TEST_PNG_BASE64),
        );

        $response = $this->withHeaders([
            'X-System-Key' => 'sk_sys_test_key_01',
        ])->post('/api/tickets', [
            'description' => 'El botón de pago no responde.',
            'issueType' => 'blocks_flow',
            'reporterName' => 'Elena Navarro',
            'reporterEmail' => 'elena@finanzaspro.io',
            'reporterExternalId' => 'usr_ext_901',
            'browser' => 'Chrome 131 / macOS',
            'screenshot' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.id', 'INC-2057')
            ->assertJsonPath('data.issueType', 'blocks_flow')
            ->assertJsonPath('data.severity', 'P1')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.system', 'NexaPortal Clientes')
            ->assertJsonPath('data.reporter', [
                'id' => 'usr_ext_901',
                'name' => 'Elena Navarro',
                'email' => 'elena@finanzaspro.io',
            ])
            ->assertJsonPath('data.browser', 'Chrome 131 / macOS')
            ->assertJsonPath('data.slaMinutesRemaining', 60);

        $ticket = Ticket::query()->where('code', 'INC-2057')->firstOrFail();
        expect($ticket->screenshot_path)->toStartWith('screenshots/');
        Storage::disk('public')->assertExists((string) $ticket->screenshot_path);
        expect($response->json('data.screenshotUrl'))->toContain($ticket->screenshot_path);
    });

    it('creates a ticket with a base64 screenshot and updates an existing reporter by external id', function (): void {
        Storage::fake('public');
        $this->travelTo(now()->startOfSecond());

        $system = System::factory()->create([
            'domain' => 'checkout.veloce.io',
            'api_key' => 'sk_sys_veloce_01',
        ]);

        Reporter::factory()->create([
            'id' => 'usr_ext_500',
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);

        $response = $this->withHeaders([
            'X-System-Key' => 'sk_sys_veloce_01',
        ])->postJson('/api/tickets', [
            'description' => 'La tabla se desborda en móvil.',
            'issueType' => 'visual',
            'reporterName' => 'New Name',
            'reporterEmail' => 'new@example.com',
            'reporterExternalId' => 'usr_ext_500',
            'screenshot' => 'data:image/png;base64,'.TEST_PNG_BASE64,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.severity', 'P4')
            ->assertJsonPath('data.slaMinutesRemaining', 4320)
            ->assertJsonPath('data.reporter.name', 'New Name')
            ->assertJsonPath('data.reporter.email', 'new@example.com');

        $ticket = Ticket::query()->where('code', $response->json('data.id'))->firstOrFail();
        expect($ticket->system_id)->toBe($system->id);
        expect($ticket->screenshot_path)->toStartWith('screenshots/')->toEndWith('.png');
        Storage::disk('public')->assertExists((string) $ticket->screenshot_path);
    });

    it('resolves system by systemDomain when X-System-Key is omitted and generates a usr_ reporter id', function (): void {
        System::factory()->create([
            'name' => 'PulseHR Nomina',
            'domain' => 'app.pulsehr.io',
        ]);

        $response = $this->postJson('/api/tickets', [
            'description' => 'La búsqueda tarda 15 segundos.',
            'issueType' => 'slow',
            'reporterName' => 'Camila Herrera',
            'reporterEmail' => 'camila@talentcloud.io',
            'systemDomain' => 'app.pulsehr.io',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.system', 'PulseHR Nomina')
            ->assertJsonPath('data.severity', 'P3');

        expect($response->json('data.reporter.id'))->toStartWith('usr_');
    });

    it('returns 422 ERR_VALIDATION when widget payload is invalid', function (): void {
        System::factory()->create(['api_key' => 'sk_sys_valid']);

        $response = $this->withHeaders([
            'X-System-Key' => 'sk_sys_valid',
        ])->postJson('/api/tickets', [
            'description' => '',
            'issueType' => 'invalid_type',
            'reporterName' => '',
            'reporterEmail' => 'not-an-email',
            'screenshot' => 'not-a-valid-image',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure([
                'code',
                'message',
                'errors' => [
                    'description',
                    'issueType',
                    'reporterName',
                    'reporterEmail',
                    'screenshot',
                ],
            ]);
    });

    it('returns 403 ERR_FORBIDDEN when X-System-Key is invalid', function (): void {
        $response = $this->withHeaders([
            'X-System-Key' => 'sk_invalid_key',
        ])->postJson('/api/tickets', [
            'description' => 'Error crítico.',
            'issueType' => 'not_working',
            'reporterName' => 'Test User',
            'reporterEmail' => 'test@example.com',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('automatically assigns severity and SLA from issueType on POST /api/tickets', function (IssueType $issueType, TicketSeverity $expectedSeverity, int $expectedSlaMinutes): void {
        $this->travelTo(now()->startOfSecond());

        System::factory()->create([
            'api_key' => 'sk_sys_severity_map',
        ]);

        $response = $this->withHeaders([
            'X-System-Key' => 'sk_sys_severity_map',
        ])->postJson('/api/tickets', [
            'description' => 'Incidencia automatizada para validar severidad.',
            'issueType' => $issueType->value,
            'reporterName' => 'Test Reporter',
            'reporterEmail' => 'reporter@example.com',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.issueType', $issueType->value)
            ->assertJsonPath('data.severity', $expectedSeverity->value)
            ->assertJsonPath('data.slaMinutesRemaining', $expectedSlaMinutes);

        $ticket = Ticket::query()->where('code', $response->json('data.id'))->firstOrFail();
        expect($ticket->severity)->toBe($expectedSeverity);
    })->with([
        'blocks_flow -> P1' => [IssueType::BlocksFlow, TicketSeverity::P1, 60],
        'not_working -> P2' => [IssueType::NotWorking, TicketSeverity::P2, 240],
        'wrong_info -> P3' => [IssueType::WrongInfo, TicketSeverity::P3, 1440],
        'slow -> P3' => [IssueType::Slow, TicketSeverity::P3, 1440],
        'visual -> P4' => [IssueType::Visual, TicketSeverity::P4, 4320],
        'other -> P4' => [IssueType::Other, TicketSeverity::P4, 4320],
    ]);

    it('falls back to TicketSeverity::fromIssueType when creating a Ticket with null severity via Eloquent', function (IssueType $issueType, TicketSeverity $expectedSeverity): void {
        $system = System::factory()->create();
        $reporter = Reporter::factory()->create();

        $ticket = Ticket::create([
            'code' => 'INC-9001',
            'system_id' => $system->id,
            'reporter_id' => $reporter->id,
            'description' => 'Fallback severity check.',
            'issue_type' => $issueType,
            'severity' => null,
            'status' => TicketStatus::Open,
            'browser' => 'Chrome 131 / Linux',
            'ip_address' => '127.0.0.1',
        ]);

        expect($ticket->fresh()?->severity)->toBe($expectedSeverity);
    })->with([
        'blocks_flow -> P1' => [IssueType::BlocksFlow, TicketSeverity::P1],
        'not_working -> P2' => [IssueType::NotWorking, TicketSeverity::P2],
        'wrong_info -> P3' => [IssueType::WrongInfo, TicketSeverity::P3],
        'slow -> P3' => [IssueType::Slow, TicketSeverity::P3],
        'visual -> P4' => [IssueType::Visual, TicketSeverity::P4],
        'other -> P4' => [IssueType::Other, TicketSeverity::P4],
    ]);
});
