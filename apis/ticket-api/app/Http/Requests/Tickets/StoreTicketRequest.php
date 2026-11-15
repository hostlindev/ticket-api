<?php

namespace App\Http\Requests\Tickets;

use App\Enums\IssueType;
use App\Services\Tickets\Screenshots\Base64ScreenshotStrategy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
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
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string'],
            'issueType' => ['required', Rule::enum(IssueType::class)],
            'reporterName' => ['required', 'string', 'max:255'],
            'reporterEmail' => ['required', 'email', 'max:255'],
            'reporterExternalId' => ['nullable', 'string', 'max:100'],
            'systemDomain' => ['nullable', 'string', 'max:255'],
            'browser' => ['nullable', 'string', 'max:255'],
            'screenshot' => $this->hasFile('screenshot')
                ? ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120']
                : [
                    'nullable',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (! is_string($value) || Base64ScreenshotStrategy::parse($value) === null) {
                            $fail('The screenshot must be a valid image file or base64-encoded image up to 5MB.');
                        }
                    },
                ],
        ];
    }
}
