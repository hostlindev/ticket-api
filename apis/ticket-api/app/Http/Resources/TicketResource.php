<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use App\Services\Tickets\TicketSlaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->code,
            'description' => $this->description,
            'issueType' => $this->issue_type->value,
            'severity' => $this->severity?->value,
            'status' => $this->status->value,
            'system' => $this->system->name,
            'reporter' => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'email' => $this->reporter->email,
            ],
            'screenshotUrl' => $this->screenshot_path
                ? Storage::disk('public')->url($this->screenshot_path)
                : null,
            'browser' => $this->browser,
            'ipAddress' => $this->ip_address,
            'layer' => $this->layer,
            'moduleRoute' => $this->module_route,
            'occurrencesCount' => $this->occurrences_count,
            'parentTicketId' => $this->parent_ticket_id,
            'createdAt' => $this->created_at?->toISOString(),
            'assignee' => $this->assignee !== null ? [
                'id' => $this->assignee->public_id,
                'name' => $this->assignee->name,
                'email' => $this->assignee->email,
                'role' => $this->assignee->specialty ?: $this->assignee->role->value,
            ] : null,
            'resolvedBy' => $this->resolvedBy !== null ? [
                'id' => $this->resolvedBy->public_id,
                'name' => $this->resolvedBy->name,
                'email' => $this->resolvedBy->email,
                'role' => $this->resolvedBy->specialty ?: $this->resolvedBy->role->value,
                'removed' => $this->resolvedBy->trashed(),
            ] : null,
            'slaMinutesRemaining' => app(TicketSlaService::class)->slaMinutesRemaining($this->resource),
        ];
    }
}
