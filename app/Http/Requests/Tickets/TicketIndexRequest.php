<?php

namespace App\Http\Requests\Tickets;

use App\Enums\IssueType;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Ticket::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'pageSize' => ['sometimes', 'nullable', 'integer', Rule::in([10, 20, 50])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'severity' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['all', ...TicketSeverity::values()]),
            ],
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['all', ...TicketStatus::values()]),
            ],
            'system' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issueType' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['all', ...IssueType::values()]),
            ],
        ];
    }
}
