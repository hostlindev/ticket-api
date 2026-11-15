<?php

use App\Enums\AppHealth;
use App\Enums\SlaStatus;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Models\Reporter;
use App\Models\System;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\travelBack;
use function Pest\Laravel\travelTo;

uses(LazilyRefreshDatabase::class);

afterEach(function (): void {
    Model::preventLazyLoading(false);
    travelBack();
});

it('returns 401 ERR_UNAUTHENTICATED when unauthenticated', function (): void {
    $response = getJson('/api/dashboard');

    $response->assertUnauthorized()
        ->assertExactJson([
            'code' => 'ERR_UNAUTHENTICATED',
            'message' => 'Unauthenticated.',
            'errors' => [],
        ]);
});

it('returns expected camelCase dashboard snapshot structure and default metrics when empty', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = getJson('/api/dashboard');

    $response->assertOk();

    expect($response->json())->toBe([
        'data' => [
            'apps' => [],
            'userIncidents' => [],
            'mttrHours' => 0,
            'slaCompliance' => 100,
            'resolvedSprint' => 0,
        ],
    ]);
});

it('aggregates app severity, status counts, and 7-day created/resolved series accurately without lazy loading', function (): void {
    travelTo('2026-11-15 12:00:00');
    Model::preventLazyLoading(true);

    $lead = User::factory()->create(['name' => 'Ada Lovelace']);
    $viewer = User::factory()->admin()->create();
    $reporter = Reporter::factory()->create();

    $alpha = System::factory()->create([
        'id' => 'app-alpha',
        'name' => 'Alpha Portal',
        'domain' => 'alpha.example.io',
        'lead_user_id' => $lead->id,
        'health' => AppHealth::Operational,
        'uptime' => 99.80,
    ]);

    System::factory()->create([
        'id' => 'app-zeta',
        'name' => 'Zeta Checkout',
        'domain' => 'zeta.example.io',
        'lead_user_id' => null,
        'health' => AppHealth::Alert,
        'uptime' => 94.82,
    ]);

    // Active classified tickets on Alpha (counted in severity + statuses)
    Ticket::factory()->for($alpha)->for($reporter)->create([
        'severity' => TicketSeverity::P1,
        'status' => TicketStatus::Open,
        'created_at' => now(), // day index 6
        'resolved_at' => null,
    ]);

    Ticket::factory()->for($alpha)->for($reporter)->create([
        'severity' => TicketSeverity::P2,
        'status' => TicketStatus::InProgress,
        'created_at' => now()->subDays(2), // day index 4
        'resolved_at' => null,
    ]);

    Ticket::factory()->for($alpha)->for($reporter)->create([
        'severity' => TicketSeverity::P3,
        'status' => TicketStatus::InReview,
        'created_at' => now()->subDays(6), // day index 0
        'resolved_at' => null,
    ]);

    // Resolved & Closed tickets on Alpha: NOT in severity, grouped into statuses.resolved
    Ticket::factory()->for($alpha)->for($reporter)->create([
        'severity' => TicketSeverity::P1,
        'status' => TicketStatus::Resolved,
        'created_at' => now()->subDays(3), // day index 3
        'resolved_at' => now()->subDays(1), // day index 5
    ]);

    Ticket::factory()->for($alpha)->for($reporter)->create([
        'severity' => TicketSeverity::P4,
        'status' => TicketStatus::Closed,
        'created_at' => now()->subDays(8), // outside 7-day created window
        'resolved_at' => now(), // day index 6
    ]);

    // Resolved ticket outside 7-day resolved window (still counts in statuses.resolved)
    Ticket::factory()->for($alpha)->for($reporter)->create([
        'severity' => TicketSeverity::P2,
        'status' => TicketStatus::Closed,
        'created_at' => now()->subDays(12),
        'resolved_at' => now()->subDays(9),
    ]);

    Sanctum::actingAs($viewer);

    $response = getJson('/api/dashboard');

    $response->assertOk()
        ->assertJsonMissingPath('data.apps.data');

    $apps = $response->json('data.apps');
    expect($apps)->toHaveCount(2);

    expect($apps[0])->toBe([
        'id' => 'app-alpha',
        'name' => 'Alpha Portal',
        'domain' => 'alpha.example.io',
        'lead' => 'Ada Lovelace',
        'health' => 'operational',
        'uptime' => '99.80%',
        'severity' => [
            'P1' => 1,
            'P2' => 1,
            'P3' => 1,
            'P4' => 0,
        ],
        'statuses' => [
            'open' => 1,
            'in_progress' => 1,
            'in_review' => 1,
            'resolved' => 3,
        ],
        'created' => [1, 0, 0, 1, 1, 0, 1],
        'resolved' => [0, 0, 0, 0, 0, 1, 1],
    ]);

    expect($apps[1])->toBe([
        'id' => 'app-zeta',
        'name' => 'Zeta Checkout',
        'domain' => 'zeta.example.io',
        'lead' => null,
        'health' => 'alert',
        'uptime' => '94.82%',
        'severity' => [
            'P1' => 0,
            'P2' => 0,
            'P3' => 0,
            'P4' => 0,
        ],
        'statuses' => [
            'open' => 0,
            'in_progress' => 0,
            'in_review' => 0,
            'resolved' => 0,
        ],
        'created' => [0, 0, 0, 0, 0, 0, 0],
        'resolved' => [0, 0, 0, 0, 0, 0, 0],
    ]);
});

it('scopes userIncidents to assigned systems, excludes resolved/closed tickets, and formats SLA and relative time', function (): void {
    travelTo('2026-11-15 12:00:00');

    $user = User::factory()->create();
    $reporter = Reporter::factory()->create();

    $assignedSystem = System::factory()->create([
        'id' => 'app-assigned',
        'name' => 'VelocePay Checkout',
        'domain' => 'checkout.veloce.io',
    ]);
    $unassignedSystem = System::factory()->create([
        'id' => 'app-other',
        'name' => 'Other System',
        'domain' => 'other.veloce.io',
    ]);

    $user->systems()->attach($assignedSystem->id);

    // P1 newest (45 min ago, overdue SLA) -> should appear 1st
    $longDescription = 'El botón de confirmar pago se queda en estado de carga indefinida al usar tarjeta corporativa 3DS.';
    Ticket::factory()->for($assignedSystem)->for($reporter)->create([
        'code' => 'INC-3001',
        'description' => $longDescription,
        'severity' => TicketSeverity::P1,
        'status' => TicketStatus::Open,
        'sla_due_at' => now()->subMinutes(10),
        'created_at' => now()->subMinutes(45),
    ]);

    // P1 older (3 hours ago, 18 min remaining) -> critical, 2nd
    Ticket::factory()->for($assignedSystem)->for($reporter)->create([
        'code' => 'INC-3002',
        'description' => 'Error 502 en checkout',
        'severity' => TicketSeverity::P1,
        'status' => TicketStatus::InProgress,
        'sla_due_at' => now()->addMinutes(18),
        'created_at' => now()->subHours(3),
    ]);

    // P2 (2 days ago, 135 min = 2 h 15 min remaining -> > 120 min so normal for P2) -> 3rd
    Ticket::factory()->for($assignedSystem)->for($reporter)->create([
        'code' => 'INC-3003',
        'description' => 'Latencia alta en confirmación',
        'severity' => TicketSeverity::P2,
        'status' => TicketStatus::InReview,
        'sla_due_at' => now()->addMinutes(135),
        'created_at' => now()->subDays(2),
    ]);

    // P3 (1 hour ago, 300 min = 5 h remaining -> <= 25% of 24h (360 min) so warning) -> 4th
    Ticket::factory()->for($assignedSystem)->for($reporter)->create([
        'code' => 'INC-3004',
        'description' => 'Moneda incorrecta en recibo',
        'severity' => TicketSeverity::P3,
        'status' => TicketStatus::Open,
        'sla_due_at' => now()->addHours(5),
        'created_at' => now()->subHour(),
    ]);

    // P4 (1 day ago, 28 h = 1 d 4 h remaining -> normal) -> 5th
    Ticket::factory()->for($assignedSystem)->for($reporter)->create([
        'code' => 'INC-3005',
        'description' => 'Alineación de botón secundaria',
        'severity' => TicketSeverity::P4,
        'status' => TicketStatus::Open,
        'sla_due_at' => now()->addHours(28),
        'created_at' => now()->subDay(),
    ]);

    // Excluded: resolved & closed on assigned system
    Ticket::factory()->for($assignedSystem)->for($reporter)->resolved()->create([
        'code' => 'INC-3098',
        'severity' => TicketSeverity::P1,
    ]);
    Ticket::factory()->for($assignedSystem)->for($reporter)->closed()->create([
        'code' => 'INC-3097',
        'severity' => TicketSeverity::P1,
    ]);

    // Excluded: active P1 on unassigned system
    Ticket::factory()->for($unassignedSystem)->for($reporter)->create([
        'code' => 'INC-3096',
        'severity' => TicketSeverity::P1,
        'status' => TicketStatus::Open,
    ]);

    Sanctum::actingAs($user);

    $response = getJson('/api/dashboard');

    $response->assertOk()
        ->assertJsonMissingPath('data.userIncidents.data');

    $incidents = $response->json('data.userIncidents');

    expect($incidents)->toHaveCount(5)
        ->and(array_column($incidents, 'id'))->toBe([
            'INC-3001',
            'INC-3002',
            'INC-3003',
            'INC-3004',
            'INC-3005',
        ]);

    expect($incidents[0])->toMatchArray([
        'id' => 'INC-3001',
        'systemName' => 'VelocePay Checkout',
        'systemCode' => 'checkout.veloce.io',
        'severity' => 'P1',
        'status' => 'open',
        'slaTime' => 'Vencido',
        'slaStatus' => SlaStatus::Critical->value,
        'createdAt' => 'Hace 45 min',
    ]);
    expect($incidents[0]['title'])->toEndWith('...');
    expect(mb_strlen($incidents[0]['title']))->toBeLessThanOrEqual(63);

    expect($incidents[1])->toMatchArray([
        'id' => 'INC-3002',
        'slaTime' => '18 min restantes',
        'slaStatus' => SlaStatus::Critical->value,
        'createdAt' => 'Hace 3 h',
    ]);

    expect($incidents[2])->toMatchArray([
        'id' => 'INC-3003',
        'slaTime' => '2 h 15 min restantes',
        'slaStatus' => SlaStatus::Normal->value,
        'createdAt' => 'Hace 2 d',
    ]);

    expect($incidents[3])->toMatchArray([
        'id' => 'INC-3004',
        'slaTime' => '5 h restantes',
        'slaStatus' => SlaStatus::Warning->value,
        'createdAt' => 'Hace 1 h',
    ]);

    expect($incidents[4])->toMatchArray([
        'id' => 'INC-3005',
        'slaTime' => '1 d 4 h restantes',
        'slaStatus' => SlaStatus::Normal->value,
        'createdAt' => 'Hace 1 d',
    ]);
});

it('calculates mttrHours, slaCompliance, and resolvedSprint accurately', function (): void {
    travelTo('2026-11-15 12:00:00');

    $user = User::factory()->admin()->create();
    $system = System::factory()->create();
    $reporter = Reporter::factory()->create();

    // Resolved in sprint (2 days ago), 2.0h resolution, within SLA
    Ticket::factory()->for($system)->for($reporter)->create([
        'status' => TicketStatus::Resolved,
        'created_at' => now()->subDays(2)->subHours(2),
        'resolved_at' => now()->subDays(2),
        'sla_due_at' => now()->subDays(2)->addHour(),
    ]);

    // Closed in sprint (5 days ago), 4.0h resolution, breached SLA
    Ticket::factory()->for($system)->for($reporter)->create([
        'status' => TicketStatus::Closed,
        'created_at' => now()->subDays(5)->subHours(4),
        'resolved_at' => now()->subDays(5),
        'sla_due_at' => now()->subDays(5)->subMinutes(30),
    ]);

    // Resolved outside 14d sprint but within 30d MTTR window (20 days ago), 6.0h resolution, within SLA
    Ticket::factory()->for($system)->for($reporter)->create([
        'status' => TicketStatus::Resolved,
        'created_at' => now()->subDays(20)->subHours(6),
        'resolved_at' => now()->subDays(20),
        'sla_due_at' => now()->subDays(20)->addMinutes(10),
    ]);

    // Resolved outside 30d MTTR window (40 days ago) with null sla_due_at (excluded from both MTTR and compliance)
    Ticket::factory()->for($system)->for($reporter)->create([
        'status' => TicketStatus::Resolved,
        'severity' => TicketSeverity::P3,
        'created_at' => now()->subDays(40)->subHours(10),
        'resolved_at' => now()->subDays(40),
        'sla_due_at' => null,
    ]);

    // Active ticket (should not affect resolved metrics)
    Ticket::factory()->for($system)->for($reporter)->create([
        'status' => TicketStatus::Open,
        'resolved_at' => null,
        'sla_due_at' => now()->subHour(),
    ]);

    Sanctum::actingAs($user);

    $response = getJson('/api/dashboard');

    $response->assertOk();

    expect($response->json('data.mttrHours'))->toEqual(4.0)
        ->and($response->json('data.slaCompliance'))->toBe(66.7)
        ->and($response->json('data.resolvedSprint'))->toBe(2);
});

it('scopes apps, userIncidents, mttrHours, slaCompliance, and resolvedSprint to assigned systems for developers while keeping admin and agent global', function (): void {
    travelTo('2026-11-15 12:00:00');
    Model::preventLazyLoading(true);

    $developer = User::factory()->developer()->create();
    $reporter = Reporter::factory()->create();

    $assignedSystem = System::factory()->create([
        'id' => 'app-assigned',
        'name' => 'Alpha Assigned',
        'domain' => 'assigned.example.io',
    ]);
    $unassignedSystem = System::factory()->create([
        'id' => 'app-unassigned',
        'name' => 'Zeta Unassigned',
        'domain' => 'unassigned.example.io',
    ]);

    $developer->systems()->attach($assignedSystem->id);

    // Assigned system: 1 active P1 + 1 resolved within SLA (2.0h) in sprint
    Ticket::factory()->for($assignedSystem)->for($reporter)->create([
        'code' => 'INC-7001',
        'description' => 'Assigned system active incident',
        'severity' => TicketSeverity::P1,
        'status' => TicketStatus::Open,
        'sla_due_at' => now()->addMinutes(45),
        'created_at' => now()->subMinutes(15),
    ]);
    Ticket::factory()->for($assignedSystem)->for($reporter)->create([
        'code' => 'INC-7002',
        'severity' => TicketSeverity::P2,
        'status' => TicketStatus::Resolved,
        'created_at' => now()->subDays(2)->subHours(2),
        'resolved_at' => now()->subDays(2),
        'sla_due_at' => now()->subDays(2)->addHour(),
    ]);

    // Unassigned system: 1 active P1 + 1 closed breaching SLA (6.0h) in sprint
    Ticket::factory()->for($unassignedSystem)->for($reporter)->create([
        'code' => 'INC-7003',
        'description' => 'Unassigned system active incident',
        'severity' => TicketSeverity::P1,
        'status' => TicketStatus::Open,
        'sla_due_at' => now()->addMinutes(30),
        'created_at' => now()->subMinutes(10),
    ]);
    Ticket::factory()->for($unassignedSystem)->for($reporter)->create([
        'code' => 'INC-7004',
        'severity' => TicketSeverity::P1,
        'status' => TicketStatus::Closed,
        'created_at' => now()->subDays(3)->subHours(6),
        'resolved_at' => now()->subDays(3),
        'sla_due_at' => now()->subDays(3)->subHour(),
    ]);

    Sanctum::actingAs($developer);

    $devResponse = getJson('/api/dashboard');

    $devResponse->assertOk();

    expect(array_column($devResponse->json('data.apps'), 'id'))->toBe(['app-assigned'])
        ->and($devResponse->json('data.apps.0.severity.P1'))->toBe(1)
        ->and($devResponse->json('data.apps.0.statuses.open'))->toBe(1)
        ->and($devResponse->json('data.apps.0.statuses.resolved'))->toBe(1)
        ->and(array_column($devResponse->json('data.userIncidents'), 'id'))->toBe(['INC-7001'])
        ->and($devResponse->json('data.mttrHours'))->toEqual(2.0)
        ->and($devResponse->json('data.slaCompliance'))->toEqual(100.0)
        ->and($devResponse->json('data.resolvedSprint'))->toBe(1);

    Sanctum::actingAs(User::factory()->admin()->create());

    $adminResponse = getJson('/api/dashboard');

    $adminResponse->assertOk();

    expect(array_column($adminResponse->json('data.apps'), 'id'))->toBe(['app-assigned', 'app-unassigned'])
        ->and($adminResponse->json('data.mttrHours'))->toEqual(4.0)
        ->and($adminResponse->json('data.slaCompliance'))->toEqual(50.0)
        ->and($adminResponse->json('data.resolvedSprint'))->toBe(2);

    Sanctum::actingAs(User::factory()->agent()->create());

    $agentResponse = getJson('/api/dashboard');

    $agentResponse->assertOk();

    expect(array_column($agentResponse->json('data.apps'), 'id'))->toBe(['app-assigned', 'app-unassigned'])
        ->and($agentResponse->json('data.mttrHours'))->toEqual(4.0)
        ->and($agentResponse->json('data.slaCompliance'))->toEqual(50.0)
        ->and($agentResponse->json('data.resolvedSprint'))->toBe(2);
});

it('returns empty apps, empty userIncidents, and default metrics when a developer has no assigned systems', function (): void {
    travelTo('2026-11-15 12:00:00');
    Model::preventLazyLoading(true);

    $system = System::factory()->create([
        'id' => 'app-unassigned',
        'name' => 'Zeta Unassigned',
    ]);
    $reporter = Reporter::factory()->create();

    Ticket::factory()->for($system)->for($reporter)->create([
        'severity' => TicketSeverity::P1,
        'status' => TicketStatus::Open,
    ]);
    Ticket::factory()->for($system)->for($reporter)->create([
        'severity' => TicketSeverity::P1,
        'status' => TicketStatus::Resolved,
        'created_at' => now()->subDays(2)->subHours(4),
        'resolved_at' => now()->subDays(2),
        'sla_due_at' => now()->subDays(2)->subHour(),
    ]);

    Sanctum::actingAs(User::factory()->developer()->create());

    $response = getJson('/api/dashboard');

    $response->assertOk();

    expect($response->json())->toBe([
        'data' => [
            'apps' => [],
            'userIncidents' => [],
            'mttrHours' => 0,
            'slaCompliance' => 100,
            'resolvedSprint' => 0,
        ],
    ]);
});

it('flags user incidents assigned to the authenticated user with assignedToMe', function (): void {
    $user = User::factory()->developer()->create();
    $other = User::factory()->developer()->create();
    $system = System::factory()->create();
    $user->systems()->attach($system->id);
    $reporter = Reporter::factory()->create();

    Ticket::factory()->for($system)->for($reporter)->create([
        'code' => 'INC-9001', 'severity' => TicketSeverity::P2, 'status' => TicketStatus::Open, 'assignee_id' => $user->id,
    ]);
    Ticket::factory()->for($system)->for($reporter)->create([
        'code' => 'INC-9002', 'severity' => TicketSeverity::P3, 'status' => TicketStatus::Open, 'assignee_id' => $other->id,
    ]);

    Sanctum::actingAs($user);

    $byCode = collect($this->getJson('/api/dashboard')->assertOk()->json('data.userIncidents'))->keyBy('id');

    expect($byCode['INC-9001']['assignedToMe'])->toBeTrue()
        ->and($byCode['INC-9002']['assignedToMe'])->toBeFalse();
});

it('returns Sin plazo and normal slaStatus for active classified incidents with null sla_due_at', function (): void {
    Model::preventLazyLoading(true);

    $user = User::factory()->developer()->create();
    $system = System::factory()->create();
    $user->systems()->attach($system->id);
    $reporter = Reporter::factory()->create();

    Ticket::factory()->for($system)->for($reporter)->create([
        'code' => 'INC-9010',
        'severity' => TicketSeverity::P2,
        'status' => TicketStatus::Open,
        'sla_due_at' => null,
    ]);

    Sanctum::actingAs($user);

    $response = getJson('/api/dashboard');

    $response->assertOk();

    expect($response->json('data.userIncidents.0'))->toMatchArray([
        'id' => 'INC-9010',
        'slaTime' => 'Sin plazo',
        'slaStatus' => 'normal',
    ]);
});
