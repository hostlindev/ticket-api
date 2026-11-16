<?php

namespace App\Modules\Helpdesk\Requests;

use App\Enums\IssueType;
use App\Services\Tickets\TicketService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePixibotTicketRequest extends FormRequest
{
    /**
     * Legacy Pixibot issue types accepted by the triage endpoint.
     *
     * @var list<string>
     */
    private const array PIXIBOT_ERROR_TYPES = [
        'bug',
        'visual',
        'lentitud',
        'consulta',
        'otro',
    ];

    /**
     * Flat telemetry keys that may be nested directly inside `client[...]`.
     *
     * @var list<string>
     */
    private const array CLIENT_TELEMETRY_KEYS = [
        'http_status',
        'failed_endpoint',
        'js_error',
        'last_ui_action',
    ];

    /**
     * Authorize the request by verifying an enabled system's X-System-Key header.
     *
     * @throws AuthorizationException
     */
    public function authorize(TicketService $ticketService): bool
    {
        $ticketService->resolveSystemByApiKey($this->header('X-System-Key'));

        return true;
    }

    /**
     * Normalize both native Pixibot JSON-string fields and bracketed widget FormData fields.
     */
    protected function prepareForValidation(): void
    {
        $reporterData = $this->resolveArrayInput('reporter_data', []);
        $reporter = $this->resolveArrayInput('reporter', []);
        $client = $this->resolveArrayInput('client', null);
        $telemetry = $this->resolveArrayInput('telemetry', null);

        $merged = [];

        if ($reporterData !== null) {
            $merged['reporter_data'] = $reporterData;
        } elseif ($reporter !== null) {
            $merged['reporter_data'] = $reporter;
        }

        if ($this->input('system_name') === null && $this->input('system') !== null) {
            $merged['system_name'] = $this->input('system');
        }

        if ($this->input('error_type') === null && $this->input('issue_type') !== null) {
            $merged['error_type'] = $this->input('issue_type');
        }

        if ($client !== null) {
            $merged['client'] = $client;
        }

        $resolvedRoute = $this->resolveModuleRoute($client);

        if ($resolvedRoute !== null) {
            $merged['module_route'] = $resolvedRoute;
        }

        $resolvedUserAgent = $this->resolveUserAgent($client);

        if ($resolvedUserAgent !== null) {
            $merged['user_agent'] = $resolvedUserAgent;
        }

        $resolvedTelemetry = $this->mergeTelemetry($telemetry, $client);

        if ($resolvedTelemetry !== null) {
            $merged['telemetry'] = $resolvedTelemetry;
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $allowedErrorTypes = array_values(array_unique([
            ...self::PIXIBOT_ERROR_TYPES,
            ...IssueType::values(),
        ]));

        return [
            'system_name' => ['nullable', 'string', 'max:100'],
            'error_type' => ['required', 'string', Rule::in($allowedErrorTypes)],
            'description' => ['required', 'string', 'min:10'],
            'module_route' => ['required', 'string', 'max:255'],
            'user_agent' => ['nullable', 'string'],
            'browser' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:50'],
            'client' => ['nullable', 'array'],
            'reporter_data' => ['required', 'array'],
            'reporter_data.id' => ['required'],
            'reporter_data.name' => ['required', 'string'],
            'reporter_data.email' => ['nullable', 'email'],
            'reporter_data.role' => ['nullable', 'string', 'max:100'],
            'reporter_data.extra' => ['nullable'],
            'telemetry' => ['nullable', 'array'],
            'screenshot' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ];
    }

    /**
     * Decode an input key when provided as a JSON string or return an existing array intact.
     */
    private function resolveArrayInput(string $key, mixed $emptyFallback): mixed
    {
        if (! $this->exists($key)) {
            return null;
        }

        $value = $this->input($key);

        if (is_string($value)) {
            return $this->decodeJsonField($value, $emptyFallback);
        }

        return $value;
    }

    /**
     * Decode a JSON string into an associative array, keeping invalid non-empty strings so validation fails.
     */
    private function decodeJsonField(string $raw, mixed $emptyFallback): mixed
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || $trimmed === 'null') {
            return $emptyFallback;
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $raw;
        }

        return is_array($decoded) ? $decoded : $raw;
    }

    /**
     * Resolve `module_route` from explicit input or nested `client[...]` metadata when using the widget contract.
     */
    private function resolveModuleRoute(mixed $client): ?string
    {
        if ($this->exists('module_route')) {
            return null;
        }

        if (is_array($client)) {
            foreach (['module_route', 'route', 'pathname', 'path', 'url'] as $key) {
                $candidate = $client[$key] ?? null;

                if (is_string($candidate) && trim($candidate) !== '') {
                    return $this->extractPath($candidate);
                }
            }
        }

        if ($this->input('source') === 'pixibot' || $this->exists('system') || is_array($client)) {
            return '/';
        }

        return null;
    }

    /**
     * Extract a normalized path from either a relative route or a full URL.
     */
    private function extractPath(string $candidate): string
    {
        $trimmed = trim($candidate);

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            $path = parse_url($trimmed, PHP_URL_PATH);

            return is_string($path) && $path !== '' ? $path : '/';
        }

        return $trimmed;
    }

    /**
     * Resolve `user_agent` from explicit input, `client[user_agent]`, or `browser`.
     */
    private function resolveUserAgent(mixed $client): ?string
    {
        if (is_string($this->input('user_agent')) && trim($this->input('user_agent')) !== '') {
            return null;
        }

        if (is_array($client)) {
            foreach (['user_agent', 'userAgent'] as $key) {
                $candidate = $client[$key] ?? null;

                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        $browser = $this->input('browser');

        if (is_string($browser) && trim($browser) !== '') {
            return trim($browser);
        }

        return null;
    }

    /**
     * Merge explicit `telemetry` with nested `client[...]`, `browser`, and `source` context.
     */
    private function mergeTelemetry(mixed $telemetry, mixed $client): mixed
    {
        if ($telemetry !== null && ! is_array($telemetry)) {
            return $telemetry;
        }

        $merged = is_array($telemetry) ? $telemetry : [];
        $hasExtras = false;

        if (is_array($client)) {
            $hasExtras = true;

            $nestedTelemetry = $client['telemetry'] ?? null;

            if (is_string($nestedTelemetry)) {
                $nestedTelemetry = $this->decodeJsonField($nestedTelemetry, []);
            }

            if (is_array($nestedTelemetry)) {
                $merged = array_merge($nestedTelemetry, $merged);
            }

            foreach (self::CLIENT_TELEMETRY_KEYS as $key) {
                if (! array_key_exists($key, $merged) && array_key_exists($key, $client)) {
                    $merged[$key] = $client[$key];
                }
            }

            $merged['client'] = $client;
        }

        $browser = $this->input('browser');

        if (is_string($browser) && trim($browser) !== '') {
            $hasExtras = true;
            $merged['browser'] = trim($browser);
        }

        $source = $this->input('source');

        if (is_string($source) && trim($source) !== '') {
            $hasExtras = true;
            $merged['source'] = trim($source);
        }

        if ($telemetry === null && ! $hasExtras) {
            return null;
        }

        return $merged;
    }
}
