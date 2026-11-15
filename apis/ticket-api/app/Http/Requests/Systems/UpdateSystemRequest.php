<?php

namespace App\Http\Requests\Systems;

use App\Enums\AppHealth;
use App\Models\System;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var System|string|null $system */
        $system = $this->route('system');
        $systemId = $system instanceof System ? $system->id : $system;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('systems', 'name')->ignore($systemId)],
            'domain' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('systems', 'domain')->ignore($systemId)],
            'leadId' => ['sometimes', 'nullable', 'string', 'exists:users,public_id'],
            'health' => ['sometimes', 'required', Rule::enum(AppHealth::class)],
            'uptime' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
