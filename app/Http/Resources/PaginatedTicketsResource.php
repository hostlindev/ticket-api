<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property LengthAwarePaginator<int, Ticket> $resource
 */
class PaginatedTicketsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{
     *     items: mixed,
     *     meta: array{page: int, pageSize: int, total: int, totalPages: int}
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'items' => TicketResource::collection($this->resource->items()),
            'meta' => [
                'page' => $this->resource->currentPage(),
                'pageSize' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'totalPages' => $this->resource->lastPage(),
            ],
        ];
    }
}
