<?php

namespace App\Http\Requests\Systems;

use App\Enums\AppHealth;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreSystemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize system name and domain whitespace/casing prior to uniqueness checks.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('name'))) {
            $normalized['name'] = trim($this->input('name'));
        }

        if (is_string($this->input('domain'))) {
            $normalized['domain'] = Str::lower(trim($this->input('domain')));
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('systems', 'name')],
            'domain' => ['required', 'string', 'max:255', Rule::unique('systems', 'domain')],
            'leadId' => ['required', 'string', Rule::exists('users', 'public_id')->whereNull('deleted_at')],
            'health' => ['sometimes', 'required', Rule::enum(AppHealth::class)],
            'uptime' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'developerIds' => ['sometimes', 'array'],
            'developerIds.*' => ['string', Rule::exists('users', 'public_id')->whereNull('deleted_at')],
        ];
    }
}
