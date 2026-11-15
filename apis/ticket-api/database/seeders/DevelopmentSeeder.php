<?php

namespace Database\Seeders;

use App\Enums\AppHealth;
use App\Enums\IssueType;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Reporter;
use App\Models\System;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class DevelopmentSeeder extends Seeder
{
    /**
     * Seed realistic development data for team members, systems, reporters, and tickets.
     */
    public function run(): void
    {
        $password = Hash::make('Ticket123!');
        $users = $this->seedTeamMembers($password);
        $systems = $this->seedSystems($users);
        $reporters = $this->seedReporters();

        $this->seedTickets($systems, $reporters, $users);
    }

    /**
     * Create Ada Lovelace (admin) plus 11 developers and support agents.
     *
     * @return Collection<string, User>
     */
    private function seedTeamMembers(string $hashedPassword): Collection
    {
        $members = [
            [
                'public_id' => 'usr_ada',
                'name' => 'Ada Lovelace',
                'email' => 'admin@ticket.dev',
                'role' => UserRole::Admin,
                'department' => 'Frontend & Web Platforms',
                'specialty' => 'Plataforma',
            ],
            [
                'public_id' => 'usr_tim',
                'name' => 'Tim Berners-Lee',
                'email' => 'tim@ticket.dev',
                'role' => UserRole::Developer,
                'department' => 'Frontend & Web Platforms',
                'specialty' => 'Frontend',
            ],
            [
                'public_id' => 'usr_grace',
                'name' => 'Grace Hopper',
                'email' => 'grace@ticket.dev',
                'role' => UserRole::Developer,
                'department' => 'Core Backend & APIs',
                'specialty' => 'Backend',
            ],
            [
                'public_id' => 'usr_linus',
                'name' => 'Linus Torvalds',
                'email' => 'linus@ticket.dev',
                'role' => UserRole::Developer,
                'department' => 'Platform Infrastructure',
                'specialty' => 'Plataforma',
            ],
            [
                'public_id' => 'usr_margaret',
                'name' => 'Margaret Hamilton',
                'email' => 'margaret@ticket.dev',
                'role' => UserRole::Agent,
                'department' => 'QA & Reliability',
                'specialty' => 'QA',
            ],
            [
                'public_id' => 'usr_alan',
                'name' => 'Alan Turing',
                'email' => 'alan@ticket.dev',
                'role' => UserRole::Developer,
                'department' => 'Data & Analytics',
                'specialty' => 'Datos',
            ],
            [
                'public_id' => 'usr_barbara',
                'name' => 'Barbara Liskov',
                'email' => 'barbara@ticket.dev',
                'role' => UserRole::Developer,
                'department' => 'Core Backend & APIs',
                'specialty' => 'Backend',
            ],
            [
                'public_id' => 'usr_ken',
                'name' => 'Ken Thompson',
                'email' => 'ken@ticket.dev',
                'role' => UserRole::Developer,
                'department' => 'Platform Infrastructure',
                'specialty' => 'Plataforma',
            ],
            [
                'public_id' => 'usr_donald',
                'name' => 'Donald Knuth',
                'email' => 'donald@ticket.dev',
                'role' => UserRole::Developer,
                'department' => 'Data & Analytics',
                'specialty' => 'Datos',
            ],
            [
                'public_id' => 'usr_brendan',
                'name' => 'Brendan Eich',
                'email' => 'brendan@ticket.dev',
                'role' => UserRole::Developer,
                'department' => 'Frontend & Web Platforms',
                'specialty' => 'Frontend',
            ],
            [
                'public_id' => 'usr_katherine',
                'name' => 'Katherine Johnson',
                'email' => 'katherine@ticket.dev',
                'role' => UserRole::Agent,
                'department' => 'QA & Reliability',
                'specialty' => 'QA',
            ],
            [
                'public_id' => 'usr_yukihiro',
                'name' => 'Yukihiro Matsumoto',
                'email' => 'matz@ticket.dev',
                'role' => UserRole::Agent,
                'department' => 'Customer Operations',
                'specialty' => 'Backend',
            ],
        ];

        $seeded = collect();

        foreach ($members as $member) {
            $user = User::query()->create([
                'public_id' => $member['public_id'],
                'name' => $member['name'],
                'email' => $member['email'],
                'email_verified_at' => now(),
                'password' => $hashedPassword,
                'role' => $member['role'],
                'department' => $member['department'],
                'specialty' => $member['specialty'],
                'avatar_url' => null,
            ]);

            $seeded->put($member['public_id'], $user);
        }

        return $seeded;
    }

    /**
     * Create 8 monitored systems and attach assigned developers including Ada.
     *
     * @param  Collection<string, User>  $users
     * @return Collection<string, System>
     */
    private function seedSystems(Collection $users): Collection
    {
        $definitions = [
            [
                'id' => 'app-sai',
                'name' => 'SAI',
                'domain' => 'sai.ticket.dev',
                'lead' => 'usr_ada',
                'health' => AppHealth::Operational,
                'uptime' => 99.94,
                'api_key' => 'sk_sys_sai_dev_01',
                'developers' => ['usr_ada', 'usr_tim', 'usr_grace', 'usr_margaret'],
            ],
            [
                'id' => 'app-gensadi',
                'name' => 'GENSADI',
                'domain' => 'gensadi.ticket.dev',
                'lead' => 'usr_tim',
                'health' => AppHealth::Degraded,
                'uptime' => 98.15,
                'api_key' => 'sk_sys_gensadi_dev_02',
                'developers' => ['usr_ada', 'usr_tim', 'usr_brendan', 'usr_barbara'],
            ],
            [
                'id' => 'app-dmp',
                'name' => 'DMP',
                'domain' => 'dmp.ticket.dev',
                'lead' => 'usr_grace',
                'health' => AppHealth::Alert,
                'uptime' => 94.80,
                'api_key' => 'sk_sys_dmp_dev_03',
                'developers' => ['usr_ada', 'usr_grace', 'usr_linus', 'usr_katherine'],
            ],
            [
                'id' => 'app-sbc',
                'name' => 'SBC',
                'domain' => 'sbc.ticket.dev',
                'lead' => 'usr_barbara',
                'health' => AppHealth::Operational,
                'uptime' => 99.87,
                'api_key' => 'sk_sys_sbc_dev_04',
                'developers' => ['usr_barbara', 'usr_alan', 'usr_yukihiro'],
            ],
            [
                'id' => 'app-viat',
                'name' => 'VIAT',
                'domain' => 'viat.ticket.dev',
                'lead' => 'usr_brendan',
                'health' => AppHealth::Operational,
                'uptime' => 99.72,
                'api_key' => 'sk_sys_viat_dev_05',
                'developers' => ['usr_ada', 'usr_brendan', 'usr_margaret'],
            ],
            [
                'id' => 'app-redoc',
                'name' => 'REDOC',
                'domain' => 'redoc.ticket.dev',
                'lead' => 'usr_linus',
                'health' => AppHealth::Degraded,
                'uptime' => 97.60,
                'api_key' => 'sk_sys_redoc_dev_06',
                'developers' => ['usr_linus', 'usr_ken', 'usr_katherine'],
            ],
            [
                'id' => 'app-pucq',
                'name' => 'PUCQ',
                'domain' => 'pucq.ticket.dev',
                'lead' => 'usr_alan',
                'health' => AppHealth::Operational,
                'uptime' => 99.91,
                'api_key' => 'sk_sys_pucq_dev_07',
                'developers' => ['usr_alan', 'usr_donald', 'usr_ken'],
            ],
            [
                'id' => 'app-smc',
                'name' => 'SMC',
                'domain' => 'smc.ticket.dev',
                'lead' => 'usr_ken',
                'health' => AppHealth::Operational,
                'uptime' => 99.65,
                'api_key' => 'sk_sys_smc_dev_08',
                'developers' => ['usr_ada', 'usr_ken', 'usr_yukihiro'],
            ],
        ];

        $seeded = collect();

        foreach ($definitions as $definition) {
            $system = System::query()->create([
                'id' => $definition['id'],
                'name' => $definition['name'],
                'domain' => $definition['domain'],
                'lead_user_id' => $users->get($definition['lead'])?->id,
                'health' => $definition['health'],
                'uptime' => $definition['uptime'],
                'api_key' => $definition['api_key'],
            ]);

            $developerIds = collect($definition['developers'])
                ->map(fn (string $publicId): ?int => $users->get($publicId)?->id)
                ->filter()
                ->values()
                ->all();

            $system->developers()->sync($developerIds);
            $seeded->put($definition['id'], $system);
        }

        return $seeded;
    }

    /**
     * Create realistic end-user reporters.
     *
     * @return list<Reporter>
     */
    private function seedReporters(): array
    {
        $records = [
            ['id' => 'usr_8120', 'name' => 'Lucía Mendoza', 'email' => 'lucia.mendoza@acmecorp.es'],
            ['id' => 'usr_8121', 'name' => 'Carlos Paredes', 'email' => 'c.paredes@distribuidora-norte.com'],
            ['id' => 'usr_8122', 'name' => 'Elena Navarro', 'email' => 'elena.navarro@finanzaspro.io'],
            ['id' => 'usr_8123', 'name' => 'Javier Ríos', 'email' => 'jrios@logistica-sur.es'],
            ['id' => 'usr_8124', 'name' => 'Sofía Castellanos', 'email' => 'sofia@retailhub.com'],
            ['id' => 'usr_8125', 'name' => 'Mateo Villalobos', 'email' => 'mvillalobos@grupokroma.io'],
            ['id' => 'usr_8126', 'name' => 'Valeria Domínguez', 'email' => 'vdominguez@veloce-partner.com'],
            ['id' => 'usr_8127', 'name' => 'Andrés Salgado', 'email' => 'andres.salgado@nexaclient.es'],
            ['id' => 'usr_8128', 'name' => 'Camila Herrera', 'email' => 'camila@talentcloud.io'],
            ['id' => 'usr_8129', 'name' => 'Diego Santamaría', 'email' => 'dsantamaria@orion-sales.com'],
            ['id' => 'usr_8130', 'name' => 'Paula Benítez', 'email' => 'pbenitez@atlasenvios.es'],
            ['id' => 'usr_8131', 'name' => 'Nicolás Fuentes', 'email' => 'nfuentes@vertexclient.io'],
            ['id' => 'usr_8132', 'name' => 'Mariana Ibarra', 'email' => 'mibarra@nimbuslegal.es'],
            ['id' => 'usr_8133', 'name' => 'Tomás Aguirre', 'email' => 'taguirre@comercialb2b.com'],
        ];

        $reporters = [];

        foreach ($records as $record) {
            $reporters[] = Reporter::query()->create($record);
        }

        return $reporters;
    }

    /**
     * Seed 56 realistic tickets distributed across the last 7 days.
     *
     * @param  Collection<string, System>  $systems
     * @param  list<Reporter>  $reporters
     * @param  Collection<string, User>  $users
     */
    private function seedTickets(Collection $systems, array $reporters, Collection $users): void
    {
        $now = CarbonImmutable::now();
        $systemIds = $systems->keys()->all();
        $assigneePool = $users->values()->all();

        $descriptions = [
            IssueType::BlocksFlow->value => [
                'El botón de confirmar pago se queda en estado de carga indefinida al usar tarjeta corporativa 3DS.',
                'No permite avanzar del paso 2 al paso 3 en el alta de cliente cuando el NIF incluye guiones.',
                'El modal de firma digital bloquea toda la pantalla y no responde al hacer clic en Aceptar.',
                'Al intentar aprobar una orden de compra superior a 10.000 EUR la sesión redirige al login.',
            ],
            IssueType::NotWorking->value => [
                'La descarga de facturas en PDF devuelve error 502 Bad Gateway desde esta mañana.',
                'El buscador global de pedidos no devuelve resultados aunque el código exista en el listado.',
                'Falla la sincronización de webhook con inventario al actualizar stock desde el panel B2B.',
                'El filtro por rango de fechas en el reporte mensual ignora la selección y muestra todo el año.',
            ],
            IssueType::WrongInfo->value => [
                'El subtotal del carrito muestra IVA del 10% en lugar del 21% para productos estándar.',
                'El estado del envío figura como Entregado pero el transportista indica que sigue en tránsito.',
                'El resumen de nómina duplica la línea de retención en la vista previa antes de exportar.',
                'La zona horaria de los eventos de auditoría aparece en UTC en vez de la del usuario.',
            ],
            IssueType::Slow->value => [
                'El tablero principal tarda más de 14 segundos en cargar cuando hay más de 500 filas.',
                'La exportación CSV de movimientos tarda casi un minuto y a veces expira la petición.',
                'El autocompletado de clientes tarda varios segundos por cada tecla presionada.',
                'La carga inicial del catálogo B2B congela la pestaña durante 8 segundos en Chrome.',
            ],
            IssueType::Visual->value => [
                'La tabla de liquidaciones se desborda horizontalmente en pantallas de 1280px ocultando acciones.',
                'Los badges de estado se superponen con el título del ticket en la vista móvil.',
                'El selector de idioma queda cortado detrás de la barra superior fija.',
                'En modo oscuro el texto del tooltip de ayuda tiene contraste insuficiente.',
            ],
            IssueType::Other->value => [
                'Las notificaciones por correo de cambio de contraseña llegan con 25 minutos de retraso.',
                'Se requiere habilitar soporte para caracteres especiales en el campo de referencia externa.',
                'El enlace de restablecimiento de sesión expira antes de los 15 minutos configurados.',
                'Solicitud de revisión sobre el mensaje genérico mostrado al superar intentos de validación.',
            ],
        ];

        $browsers = [
            'Chrome 131 / macOS',
            'Chrome 131 / Windows 11',
            'Firefox 133 / Ubuntu 24.04',
            'Safari 18 / macOS',
            'Safari 18 / iOS 18',
            'Edge 131 / Windows 11',
        ];

        $issueTypes = IssueType::cases();

        $statusSchedule = [
            TicketStatus::Open,
            TicketStatus::Open,
            TicketStatus::Open,
            TicketStatus::Open,
            TicketStatus::InProgress,
            TicketStatus::InProgress,
            TicketStatus::InProgress,
            TicketStatus::InReview,
            TicketStatus::InReview,
            TicketStatus::Resolved,
            TicketStatus::Resolved,
            TicketStatus::Resolved,
            TicketStatus::Closed,
            TicketStatus::Closed,
        ];

        for ($index = 0; $index < 56; $index++) {
            $codeNumber = 2001 + $index;
            $daysAgo = $index % 7;
            $hoursOffset = ($index * 3) % 20;
            $createdAt = $now->subDays($daysAgo)->subHours($hoursOffset)->subMinutes(($index * 11) % 60);

            $status = $statusSchedule[$index % count($statusSchedule)];
            $issueType = $issueTypes[$index % count($issueTypes)];
            $severity = TicketSeverity::fromIssueType($issueType);
            $systemId = $systemIds[$index % count($systemIds)];
            $reporter = $reporters[$index % count($reporters)];

            $assigneeId = $status === TicketStatus::Open && ($index % 3 === 0)
                ? null
                : $assigneePool[$index % count($assigneePool)]->id;

            $slaDueAt = $this->computeSlaDueAt($createdAt, $severity, $index, $now);
            $resolvedAt = $this->computeResolvedAt($status, $createdAt, $index, $now);
            $updatedAt = $resolvedAt ?? $createdAt->addMinutes(30 + ($index * 7) % 180);

            if ($updatedAt->greaterThan($now)) {
                $updatedAt = $now;
            }

            $typeDescriptions = $descriptions[$issueType->value];
            $description = $typeDescriptions[$index % count($typeDescriptions)];

            $ticket = new Ticket([
                'code' => sprintf('INC-%d', $codeNumber),
                'system_id' => $systemId,
                'reporter_id' => $reporter->id,
                'assignee_id' => $assigneeId,
                'resolved_by_id' => $resolvedAt !== null ? $assigneeId : null,
                'description' => $description,
                'issue_type' => $issueType,
                'severity' => $severity,
                'status' => $status,
                'screenshot_path' => null,
                'browser' => $browsers[$index % count($browsers)],
                'ip_address' => sprintf('192.168.%d.%d', 10 + ($index % 5), 20 + $index),
                'sla_due_at' => $slaDueAt,
                'resolved_at' => $resolvedAt,
            ]);

            $ticket->created_at = $createdAt;
            $ticket->updated_at = $updatedAt;
            $ticket->save();
        }
    }

    /**
     * Calculate a varied SLA due timestamp so normal, warning, and critical SLA states appear in dev.
     */
    private function computeSlaDueAt(
        CarbonImmutable $createdAt,
        TicketSeverity $severity,
        int $index,
        CarbonImmutable $now,
    ): CarbonImmutable {
        if ($index % 9 === 0) {
            return $now->subMinutes(45 + ($index % 90));
        }

        if ($index % 6 === 0) {
            return $now->addMinutes(75 + ($index % 60));
        }

        $hoursBySeverity = match ($severity) {
            TicketSeverity::P1 => 4,
            TicketSeverity::P2 => 12,
            TicketSeverity::P3 => 24,
            TicketSeverity::P4 => 72,
        };

        return $createdAt->addHours($hoursBySeverity);
    }

    /**
     * Calculate a realistic resolution timestamp for resolved or closed tickets.
     */
    private function computeResolvedAt(
        TicketStatus $status,
        CarbonImmutable $createdAt,
        int $index,
        CarbonImmutable $now,
    ): ?CarbonImmutable {
        if (! in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            return null;
        }

        $candidate = $createdAt->addHours(2 + ($index % 8))->addMinutes(15);

        return $candidate->greaterThan($now) ? $now->subMinutes(10) : $candidate;
    }
}
