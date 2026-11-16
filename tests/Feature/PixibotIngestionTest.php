<?php

use App\Enums\IssueType;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\System;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Build Pixibot ingestion headers for an enabled system.
 *
 * @return array<string, string>
 */
function pixibotHeaders(?System $system = null): array
{
    $resolvedSystem = $system ?? System::query()->where('id', 'app-sai')->first() ?? System::factory()->create([
        'id' => 'app-sai',
        'name' => 'SAI',
        'domain' => 'sai.ticket.dev',
    ]);

    return [
        'Accept' => 'application/json',
        'X-System-Key' => (string) $resolvedSystem->api_key,
    ];
}

/**
 * Build a valid base payload for Pixibot ticket ingestion.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixibotPayload(array $overrides = []): array
{
    return array_merge([
        'system_name' => 'SAI',
        'error_type' => 'bug',
        'description' => 'Error al guardar el formulario de expediente clinico.',
        'module_route' => '/expedientes/10/editar',
        'user_agent' => 'Mozilla/5.0 PixibotTest',
        'reporter_data' => json_encode([
            'id' => 12,
            'name' => 'Juan Perez',
            'email' => 'jperez@dominio.gob.pa',
            'role' => 'FS',
        ], JSON_THROW_ON_ERROR),
        'telemetry' => json_encode([
            'http_status' => 500,
            'failed_endpoint' => '/api/expedientes/45',
            'js_error' => null,
            'last_ui_action' => 'button:guardar',
        ], JSON_THROW_ON_ERROR),
    ], $overrides);
}

it('classifies backend, frontend, integracion, and por_definir layers from telemetry', function (array $telemetry, string $expectedLayer): void {
    $payload = pixibotPayload([
        'telemetry' => json_encode($telemetry, JSON_THROW_ON_ERROR),
    ]);

    $response = $this->post('/api/v1/pixibot/tickets', $payload, pixibotHeaders());

    $response->assertCreated()
        ->assertJsonPath('layer_detected', $expectedLayer)
        ->assertJsonPath('is_duplicate', false)
        ->assertJsonPath('already_resolved', false)
        ->assertJsonPath('parent_ticket_id', null)
        ->assertJsonPath('user_message', 'Reporte registrado correctamente.');

    $ticket = Ticket::findOrFail($response->json('ticket_id'));

    expect($ticket->layer)->toBe($expectedLayer)
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->occurrences_count)->toBe(1)
        ->and($ticket->code)->toStartWith('INC-');
})->with([
    'backend 500' => [
        ['http_status' => 500, 'failed_endpoint' => '/api/expedientes/45', 'js_error' => null, 'last_ui_action' => 'button:guardar'],
        'backend',
    ],
    'frontend js_error without http_status' => [
        ['http_status' => null, 'failed_endpoint' => null, 'js_error' => 'TypeError: Cannot read properties of undefined', 'last_ui_action' => 'button:guardar'],
        'frontend',
    ],
    'integracion 422' => [
        ['http_status' => 422, 'failed_endpoint' => '/api/expedientes/45', 'js_error' => null, 'last_ui_action' => 'button:guardar'],
        'integracion',
    ],
    'integracion 200 with js_error' => [
        ['http_status' => 200, 'failed_endpoint' => '/api/expedientes/45', 'js_error' => 'SyntaxError: Unexpected token', 'last_ui_action' => 'button:guardar'],
        'integracion',
    ],
    'por_definir without status or js_error' => [
        ['http_status' => null, 'failed_endpoint' => null, 'js_error' => null, 'last_ui_action' => 'button:guardar'],
        'por_definir',
    ],
]);

it('deduplicates tickets across normalized dynamic module routes and increments parent occurrences', function (): void {
    $headers = pixibotHeaders();

    $firstResponse = $this->post('/api/v1/pixibot/tickets', pixibotPayload([
        'module_route' => '/expedientes/10/editar',
        'telemetry' => json_encode([
            'http_status' => 500,
            'failed_endpoint' => '/api/expedientes/10',
            'js_error' => null,
            'last_ui_action' => 'button:guardar',
        ], JSON_THROW_ON_ERROR),
    ]), $headers);

    $firstResponse->assertCreated();
    $parentId = $firstResponse->json('ticket_id');

    $secondResponse = $this->post('/api/v1/pixibot/tickets', pixibotPayload([
        'module_route' => '/expedientes/99/editar',
        'telemetry' => json_encode([
            'http_status' => 500,
            'failed_endpoint' => '/api/expedientes/10',
            'js_error' => null,
            'last_ui_action' => 'button:guardar',
        ], JSON_THROW_ON_ERROR),
    ]), $headers);

    $secondResponse->assertCreated()
        ->assertJsonPath('parent_ticket_id', $parentId)
        ->assertJsonPath('is_duplicate', true)
        ->assertJsonPath('already_resolved', false)
        ->assertJsonPath('user_message', 'Hemos sumado tu reporte a una incidencia que ya está siendo atendida por el equipo.');

    $parentTicket = Ticket::findOrFail($parentId);
    $duplicateTicket = Ticket::findOrFail($secondResponse->json('ticket_id'));

    expect($parentTicket->occurrences_count)->toBe(2)
        ->and($parentTicket->module_route)->toBe('/expedientes/:id/editar')
        ->and($duplicateTicket->status)->toBe(TicketStatus::Duplicate)
        ->and($duplicateTicket->parent_ticket_id)->toBe($parentId);
});

it('creates a new open parent ticket on the same module route when the error differs', function (array $secondTelemetry): void {
    $headers = pixibotHeaders();

    $firstResponse = $this->post('/api/v1/pixibot/tickets', pixibotPayload([
        'module_route' => '/expedientes/10/editar',
        'telemetry' => json_encode([
            'http_status' => 500,
            'failed_endpoint' => '/api/expedientes/10',
            'js_error' => null,
            'last_ui_action' => 'button:guardar',
        ], JSON_THROW_ON_ERROR),
    ]), $headers);

    $firstResponse->assertCreated();

    $secondResponse = $this->post('/api/v1/pixibot/tickets', pixibotPayload([
        'module_route' => '/expedientes/10/editar',
        'telemetry' => json_encode($secondTelemetry, JSON_THROW_ON_ERROR),
    ]), $headers);

    $secondResponse->assertCreated()
        ->assertJsonPath('parent_ticket_id', null)
        ->assertJsonPath('is_duplicate', false);

    $secondTicket = Ticket::findOrFail($secondResponse->json('ticket_id'));

    expect($secondTicket->status)->toBe(TicketStatus::Open)
        ->and($secondTicket->parent_ticket_id)->toBeNull()
        ->and(Ticket::count())->toBe(2);
})->with([
    'different failed_endpoint' => [[
        'http_status' => 500,
        'failed_endpoint' => '/api/catalogos/5',
        'js_error' => null,
        'last_ui_action' => 'button:guardar',
    ]],
    'different last_ui_action' => [[
        'http_status' => null,
        'failed_endpoint' => null,
        'js_error' => null,
        'last_ui_action' => 'button:eliminar',
    ]],
]);

it('notifies user when an identical incident was resolved within the last 48 hours', function (): void {
    $this->travelTo(now()->startOfSecond());
    $headers = pixibotHeaders();

    $firstResponse = $this->post('/api/v1/pixibot/tickets', pixibotPayload(), $headers);
    $firstResponse->assertCreated();

    $parentTicket = Ticket::findOrFail($firstResponse->json('ticket_id'));
    $parentTicket->update(['status' => TicketStatus::Resolved]);

    $this->travel(47)->hours();

    $secondResponse = $this->post('/api/v1/pixibot/tickets', pixibotPayload(), $headers);

    $secondResponse->assertCreated()
        ->assertJsonPath('parent_ticket_id', $parentTicket->id)
        ->assertJsonPath('is_duplicate', true)
        ->assertJsonPath('already_resolved', true)
        ->assertJsonPath('user_message', 'Este inconveniente ya fue resuelto recientemente. Por favor recarga tu navegador con Ctrl + F5.');
});

it('creates a new open ticket when an identical resolved incident is older than 48 hours', function (): void {
    $this->travelTo(now()->startOfSecond());
    $headers = pixibotHeaders();

    $firstResponse = $this->post('/api/v1/pixibot/tickets', pixibotPayload(), $headers);
    $firstResponse->assertCreated();

    $parentTicket = Ticket::findOrFail($firstResponse->json('ticket_id'));
    $parentTicket->update(['status' => TicketStatus::Resolved]);

    $this->travel(49)->hours();

    $secondResponse = $this->post('/api/v1/pixibot/tickets', pixibotPayload(), $headers);

    $secondResponse->assertCreated()
        ->assertJsonPath('parent_ticket_id', null)
        ->assertJsonPath('is_duplicate', false)
        ->assertJsonPath('already_resolved', false);
});

it('stores multipart screenshot attachment, normalized uuid route, and links existing system in unified tickets table', function (): void {
    Storage::fake('public');

    $existingSystem = System::factory()->create([
        'id' => 'app-sai',
        'name' => 'SAI',
        'domain' => 'sai.ticket.dev',
    ]);

    $screenshot = UploadedFile::fake()->image('captura.png', 100, 100);

    $response = $this->post('/api/v1/pixibot/tickets', pixibotPayload([
        'system_name' => 'SAI',
        'module_route' => '/usuarios/550e8400-e29b-41d4-a716-446655440000/perfil',
        'screenshot' => $screenshot,
    ]), pixibotHeaders($existingSystem));

    $response->assertCreated();

    $ticket = Ticket::with(['attachments', 'system'])->findOrFail($response->json('ticket_id'));
    $attachment = $ticket->attachments->first();

    expect($ticket->module_route)->toBe('/usuarios/:uuid/perfil')
        ->and($ticket->system->id)->toBe($existingSystem->id)
        ->and($ticket->system->name)->toBe('SAI')
        ->and($ticket->reporter_data['role'])->toBe('FS')
        ->and($ticket->screenshot_path)->not->toBeNull()
        ->and($attachment)->toBeInstanceOf(Attachment::class)
        ->and($attachment->file_name)->toBe('captura.png');

    Storage::disk('public')->assertExists($attachment->file_path);
    expect(System::count())->toBe(1);
});

it('returns 422 validation errors when required fields or JSON payloads are invalid', function (): void {
    $response = $this->post('/api/v1/pixibot/tickets', [
        'error_type' => 'invalido',
        'description' => 'corto',
        'module_route' => '',
        'reporter_data' => '{json-invalido',
        'telemetry' => '{json-invalido',
    ], pixibotHeaders());

    $response->assertUnprocessable()
        ->assertJsonPath('code', 'ERR_VALIDATION')
        ->assertJsonStructure([
            'code',
            'message',
            'errors' => [
                'error_type',
                'description',
                'module_route',
                'reporter_data',
                'telemetry',
            ],
        ]);

    expect(Ticket::count())->toBe(0);
});

it('ingests frontend widget multipart payload on POST /api/tickets when source is pixibot', function (): void {
    Storage::fake('public');

    $screenshot = UploadedFile::fake()->image('widget-error.jpg', 120, 80);

    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '198.51.100.24',
    ])->post('/api/tickets', [
        'issue_type' => 'blocks_flow',
        'description' => 'El formulario de registro falla al enviar los datos del paciente.',
        'system' => 'SAI',
        'source' => 'pixibot',
        'reporter' => [
            'id' => '42',
            'name' => 'Ana Gomez',
            'email' => 'agomez@dominio.gob.pa',
        ],
        'client' => [
            'url' => 'https://sai.gob.pa/expedientes/77/editar',
            'http_status' => '500',
            'failed_endpoint' => '/api/expedientes/77',
            'last_ui_action' => 'button:guardar',
            'viewport' => '1920x1080',
            'os' => 'Windows 11',
        ],
        'browser' => 'Chrome 131 / Windows',
        'screenshot' => $screenshot,
    ], pixibotHeaders());

    $response->assertCreated()
        ->assertJsonPath('layer_detected', 'backend')
        ->assertJsonPath('ip_address', '198.51.100.24')
        ->assertJsonPath('is_duplicate', false)
        ->assertJsonPath('already_resolved', false)
        ->assertJsonPath('user_message', 'Reporte registrado correctamente.');

    $ticket = Ticket::with(['attachments', 'system'])->findOrFail($response->json('ticket_id'));
    $attachment = $ticket->attachments->first();

    expect($ticket->issue_type)->toBe(IssueType::BlocksFlow)
        ->and($ticket->system->name)->toBe('SAI')
        ->and($ticket->ip_address)->toBe('198.51.100.24')
        ->and($ticket->reporter_external_id)->toBe('42')
        ->and($ticket->reporter_name)->toBe('Ana Gomez')
        ->and($ticket->reporter_email)->toBe('agomez@dominio.gob.pa')
        ->and($ticket->module_route)->toBe('/expedientes/:id/editar')
        ->and($ticket->user_agent)->toBe('Chrome 131 / Windows')
        ->and($ticket->reporter_data)->toBe([
            'id' => '42',
            'name' => 'Ana Gomez',
            'email' => 'agomez@dominio.gob.pa',
        ])
        ->and($ticket->telemetry['source'])->toBe('pixibot')
        ->and($ticket->telemetry['browser'])->toBe('Chrome 131 / Windows')
        ->and($ticket->telemetry['client']['viewport'])->toBe('1920x1080')
        ->and($ticket->telemetry['client']['os'])->toBe('Windows 11')
        ->and($attachment)->toBeInstanceOf(Attachment::class)
        ->and($attachment->file_name)->toBe('widget-error.jpg');

    Storage::disk('public')->assertExists($attachment->file_path);
});

it('deduplicates frontend widget payloads across POST /api/tickets and POST /api/v1/pixibot/tickets', function (): void {
    $gensadi = System::factory()->create([
        'id' => 'app-gensadi',
        'name' => 'GENSADI',
        'domain' => 'gensadi.ticket.dev',
    ]);
    $headers = pixibotHeaders($gensadi);

    $firstResponse = $this->post('/api/tickets', [
        'issue_type' => 'bug',
        'description' => 'Primer reporte del widget pixibot en expediente.',
        'system' => 'GENSADI',
        'source' => 'pixibot',
        'reporter' => [
            'id' => '10',
            'name' => 'Carlos Ruiz',
            'email' => 'cruiz@dominio.gob.pa',
        ],
        'client' => [
            'route' => '/expedientes/12/editar',
            'http_status' => '500',
            'failed_endpoint' => '/api/expedientes/12',
        ],
        'browser' => 'Firefox 132',
    ], $headers);

    $firstResponse->assertCreated();
    $parentId = $firstResponse->json('ticket_id');

    $secondResponse = $this->post('/api/v1/pixibot/tickets', [
        'issue_type' => 'bug',
        'description' => 'Segundo reporte desde otro expediente con mismo fallo.',
        'system' => 'GENSADI',
        'source' => 'pixibot',
        'reporter' => [
            'id' => '11',
            'name' => 'Maria Diaz',
            'email' => 'mdiaz@dominio.gob.pa',
        ],
        'client' => [
            'route' => '/expedientes/99/editar',
            'http_status' => '500',
            'failed_endpoint' => '/api/expedientes/99',
        ],
        'browser' => 'Chrome 131',
    ], $headers);

    $secondResponse->assertCreated()
        ->assertJsonPath('parent_ticket_id', $parentId)
        ->assertJsonPath('is_duplicate', true)
        ->assertJsonPath('layer_detected', 'backend');

    expect(Ticket::findOrFail($parentId)->occurrences_count)->toBe(2);
});

it('returns 422 validation errors on POST /api/tickets when source is pixibot and widget fields are invalid', function (): void {
    $response = $this->post('/api/tickets', [
        'source' => 'pixibot',
        'issue_type' => 'invalido',
        'description' => 'corto',
        'reporter' => [
            'id' => '',
            'name' => '',
            'email' => 'correo-invalido',
        ],
    ], pixibotHeaders());

    $response->assertUnprocessable()
        ->assertJsonPath('code', 'ERR_VALIDATION')
        ->assertJsonStructure([
            'code',
            'message',
            'errors' => [
                'error_type',
                'description',
                'reporter_data.id',
                'reporter_data.name',
                'reporter_data.email',
            ],
        ]);
});

it('lists master ticket with occurrencesCount and hides duplicates from GET /api/tickets and summary KPIs', function (): void {
    $developer = User::factory()->developer()->create([
        'public_id' => 'usr_grace',
        'name' => 'Grace Hopper',
        'email' => 'grace@ticket.dev',
    ]);
    $system = System::factory()->create(['id' => 'app-sai', 'name' => 'SAI']);
    $system->developers()->attach($developer->id);
    $headers = pixibotHeaders($system);

    Sanctum::actingAs($developer);

    $firstResponse = $this->post('/api/tickets', [
        'issue_type' => 'blocks_flow',
        'description' => 'Al momento de registrar la persona natural no permite guardar sin primer apellido.',
        'system' => 'SAI',
        'source' => 'pixibot',
        'reporter' => [
            'id' => 'usr_grace',
            'name' => 'Grace Hopper',
            'email' => 'grace@ticket.dev',
        ],
        'client' => [
            'url' => 'http://localhost:5173/tickets',
            'http_status' => '422',
            'failed_endpoint' => '/api/personas',
        ],
        'browser' => 'Chrome 155 · Linux',
    ], $headers);

    $firstResponse->assertCreated()
        ->assertJsonPath('is_duplicate', false);

    $ticketCode = $firstResponse->json('ticket_code');

    $duplicateResponse = $this->post('/api/tickets', [
        'issue_type' => 'blocks_flow',
        'description' => 'Segundo reporte del mismo fallo en registro.',
        'system' => 'SAI',
        'source' => 'pixibot',
        'reporter' => [
            'id' => 'usr_grace',
            'name' => 'Grace Hopper',
            'email' => 'grace@ticket.dev',
        ],
        'client' => [
            'url' => 'http://localhost:5173/tickets',
            'http_status' => '422',
            'failed_endpoint' => '/api/personas',
        ],
        'browser' => 'Chrome 155 · Linux',
    ], $headers);

    $duplicateResponse->assertCreated()
        ->assertJsonPath('is_duplicate', true)
        ->assertJsonPath('parent_ticket_code', $ticketCode);

    $this->getJson('/api/tickets')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $ticketCode)
        ->assertJsonPath('data.items.0.system', 'SAI')
        ->assertJsonPath('data.items.0.layer', 'integracion')
        ->assertJsonPath('data.items.0.moduleRoute', '/tickets')
        ->assertJsonPath('data.items.0.occurrencesCount', 2);

    $this->getJson('/api/tickets/summary')
        ->assertOk()
        ->assertJsonPath('data.total', 1);
});

it('rejects pixibot reports with 403 ERR_FORBIDDEN when X-System-Key is missing, invalid, or belongs to a deleted system', function (string $endpoint, ?string $headerKey): void {
    $deletedSystem = System::factory()->create(['api_key' => 'sk_sys_deleted_key']);
    $deletedSystem->delete();

    $headers = ['Accept' => 'application/json'];

    if ($headerKey !== null) {
        $headers['X-System-Key'] = $headerKey === 'deleted' ? 'sk_sys_deleted_key' : $headerKey;
    }

    $response = $this->post($endpoint, [
        'source' => 'pixibot',
        'issue_type' => 'bug',
        'description' => 'Intento de reporte sin API key valida del sistema.',
        'module_route' => '/inicio',
        'reporter' => [
            'id' => '1',
            'name' => 'Usuario Prueba',
            'email' => 'test@ticket.dev',
        ],
    ], $headers);

    $response->assertForbidden()
        ->assertJsonPath('code', 'ERR_FORBIDDEN');

    expect(Ticket::count())->toBe(0);
})->with([
    'missing key on /api/v1/pixibot/tickets' => ['/api/v1/pixibot/tickets', null],
    'invalid key on /api/v1/pixibot/tickets' => ['/api/v1/pixibot/tickets', 'sk_invalid_key'],
    'deleted system key on /api/v1/pixibot/tickets' => ['/api/v1/pixibot/tickets', 'deleted'],
    'missing key on /api/tickets?source=pixibot' => ['/api/tickets', null],
    'invalid key on /api/tickets?source=pixibot' => ['/api/tickets', 'sk_invalid_key'],
    'deleted system key on /api/tickets?source=pixibot' => ['/api/tickets', 'deleted'],
]);

it('resolves the target system strictly from X-System-Key even when system name is omitted in pixibot payload', function (): void {
    $system = System::factory()->create([
        'id' => 'app-redoc',
        'name' => 'REDOC',
        'domain' => 'redoc.ticket.dev',
    ]);

    $response = $this->post('/api/tickets', [
        'source' => 'pixibot',
        'issue_type' => 'bug',
        'description' => 'Reporte enviado configurando solo el API key del widget.',
        'reporter' => [
            'id' => '99',
            'name' => 'Lucia Torres',
            'email' => 'ltorres@ticket.dev',
        ],
        'client' => [
            'route' => '/documentos/42',
            'http_status' => '500',
        ],
    ], pixibotHeaders($system));

    $response->assertCreated()
        ->assertJsonPath('layer_detected', 'backend');

    $ticket = Ticket::with('system')->findOrFail($response->json('ticket_id'));

    expect($ticket->system->id)->toBe('app-redoc')
        ->and($ticket->system->name)->toBe('REDOC')
        ->and($ticket->module_route)->toBe('/documentos/:id');
});

it('automatically generates an api_key when a system is created without one and enables pixibot and widget ingestion', function (): void {
    $system = System::create([
        'id' => 'app-nuevo-sistema',
        'name' => 'NuevoSistema',
        'domain' => 'nuevosistema.ticket.dev',
    ]);

    expect($system->api_key)->toBeString()->toStartWith('sk_sys_')
        ->and(strlen((string) $system->api_key))->toBe(39);

    $pixibotResponse = $this->post('/api/tickets', [
        'issue_type' => 'bug',
        'description' => 'Primer ticket pixibot usando la api_key autogenerada del sistema.',
        'source' => 'pixibot',
        'reporter' => [
            'id' => 'usr_100',
            'name' => 'Reportero Uno',
            'email' => 'rep1@ticket.dev',
        ],
    ], pixibotHeaders($system));

    $pixibotResponse->assertCreated();

    $widgetResponse = $this->withHeaders([
        'X-System-Key' => (string) $system->api_key,
    ])->postJson('/api/tickets', [
        'description' => 'Segundo ticket usando la api_key autogenerada del sistema.',
        'issueType' => 'not_working',
        'reporterName' => 'Reportero Dos',
        'reporterEmail' => 'rep2@ticket.dev',
    ]);

    $widgetResponse->assertCreated()
        ->assertJsonPath('data.system', 'NuevoSistema');
});
