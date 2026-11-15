<?php

namespace App\Modules\Helpdesk\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Modules\Helpdesk\Requests\StorePixibotTicketRequest;
use App\Services\Tickets\TicketService;
use Illuminate\Http\JsonResponse;

class PixibotIngestionController extends Controller
{
    public function __construct(private TicketService $ticketService) {}

    /**
     * Ingest, classify, and deduplicate a ticket reported from the Pixibot widget.
     */
    public function store(StorePixibotTicketRequest $request): JsonResponse
    {
        $result = $this->ticketService->ingestFromPixibot(
            data: $request->validated(),
            screenshot: $request->file('screenshot'),
            ipAddress: $request->ip() ?? '0.0.0.0',
            requestUserAgent: $request->userAgent(),
            actor: $request->user('sanctum'),
        );

        /** @var Ticket $ticket */
        $ticket = $result['ticket'];

        /** @var ?Ticket $parentTicket */
        $parentTicket = $result['parentTicket'];

        return response()->json([
            'ticket_id' => $ticket->id,
            'ticket_code' => $ticket->code,
            'parent_ticket_id' => $parentTicket?->id,
            'parent_ticket_code' => $parentTicket?->code,
            'layer_detected' => $result['layer'],
            'is_duplicate' => $result['isDuplicate'],
            'already_resolved' => $result['alreadyResolved'],
            'user_message' => $this->resolveUserMessage($result['alreadyResolved'], $result['isDuplicate']),
        ], 201);
    }

    /**
     * Build the user-facing acknowledgement message based on duplicate/resolved state.
     */
    private function resolveUserMessage(bool $alreadyResolved, bool $isDuplicate): string
    {
        if ($alreadyResolved) {
            return 'Este inconveniente ya fue resuelto recientemente. Por favor recarga tu navegador con Ctrl + F5.';
        }

        if ($isDuplicate) {
            return 'Hemos sumado tu reporte a una incidencia que ya está siendo atendida por el equipo.';
        }

        return 'Reporte registrado correctamente.';
    }
}
