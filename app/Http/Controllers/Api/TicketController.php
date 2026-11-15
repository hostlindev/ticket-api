<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\TicketIndexRequest;
use App\Http\Requests\Tickets\UpdateTicketStatusRequest;
use App\Http\Resources\PaginatedTicketsResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Modules\Helpdesk\Controllers\PixibotIngestionController;
use App\Modules\Helpdesk\Requests\StorePixibotTicketRequest;
use App\Services\Tickets\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketController extends Controller
{
    public function __construct(private TicketService $ticketService) {}

    /**
     * Return a paginated, filtered list of tickets ordered newest first.
     */
    public function index(TicketIndexRequest $request): PaginatedTicketsResource
    {
        Gate::authorize('viewAny', Ticket::class);

        /** @var User $user */
        $user = $request->user();

        return new PaginatedTicketsResource(
            $this->ticketService->paginateForUser($user, $request->validated()),
        );
    }

    /**
     * Return aggregate ticket KPI counts and system names that have tickets.
     */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->ticketService->summaryForUser($user),
        ]);
    }

    /**
     * Display a single ticket by its INC code.
     */
    public function show(Ticket $code): TicketResource
    {
        Gate::authorize('view', $code);

        return new TicketResource($this->ticketService->loadDetails($code));
    }

    /**
     * Update the status of a ticket.
     */
    public function updateStatus(UpdateTicketStatusRequest $request, Ticket $code): TicketResource
    {
        Gate::authorize('updateStatus', $code);

        $status = TicketStatus::from($request->string('status')->toString());

        return new TicketResource(
            $this->ticketService->updateStatus($code, $status, $request->user()),
        );
    }

    /**
     * Ingest a new ticket reported from an embedded widget.
     */
    public function store(Request $request, PixibotIngestionController $pixibotController): JsonResponse
    {
        if ($request->input('source') === 'pixibot') {
            return $pixibotController->store(app(StorePixibotTicketRequest::class));
        }

        $storeRequest = app(StoreTicketRequest::class);
        $data = $storeRequest->validated();

        if ($storeRequest->hasFile('screenshot')) {
            $data['screenshot'] = $storeRequest->file('screenshot');
        }

        $ticket = $this->ticketService->ingestFromWidget(
            data: $data,
            systemKey: $storeRequest->header('X-System-Key'),
            ipAddress: $storeRequest->ip() ?? '0.0.0.0',
            userAgent: $storeRequest->userAgent(),
        );

        return (new TicketResource($ticket))
            ->response()
            ->setStatusCode(201);
    }
}
