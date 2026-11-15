<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class ApiExceptionRenderer
{
    /**
     * Render API exceptions using the standardized JSON error envelope.
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! ($request->is('api/*') || $request->expectsJson())) {
            return null;
        }

        if ($e instanceof HttpResponseException) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => self::validationResponse($e),
            $e instanceof InvalidCredentialsException => self::jsonError(
                'ERR_AUTH_INVALID_CREDENTIALS',
                self::resolveMessage($e->getMessage(), 'Invalid credentials.'),
                401,
                $e->errors,
            ),
            $e instanceof AuthenticationException => self::jsonError(
                'ERR_UNAUTHENTICATED',
                self::resolveMessage($e->getMessage(), 'Unauthenticated.'),
                401,
            ),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => self::jsonError(
                'ERR_FORBIDDEN',
                self::resolveMessage($e->getMessage(), 'Forbidden.'),
                403,
            ),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => self::jsonError(
                'ERR_NOT_FOUND',
                self::resolveMessage($e->getMessage(), 'Resource not found.'),
                404,
            ),
            $e instanceof TooManyRequestsHttpException => self::jsonError(
                'ERR_RATE_LIMITED',
                self::resolveMessage($e->getMessage(), 'Too many requests.'),
                429,
                headers: $e->getHeaders(),
            ),
            $e instanceof HttpExceptionInterface => self::httpExceptionResponse($e),
            default => self::jsonError(
                'ERR_INTERNAL_SERVER_ERROR',
                config('app.debug')
                    ? self::resolveMessage($e->getMessage(), 'Internal server error.')
                    : 'Internal server error.',
                500,
            ),
        };
    }

    private static function validationResponse(ValidationException $e): JsonResponse
    {
        $errors = [];

        foreach ($e->errors() as $field => $messages) {
            $firstMessage = is_array($messages) ? ($messages[0] ?? null) : $messages;

            if (is_string($firstMessage) && $firstMessage !== '') {
                $errors[(string) $field] = $firstMessage;
            }
        }

        return self::jsonError(
            'ERR_VALIDATION',
            self::resolveMessage($e->getMessage(), 'The given data was invalid.'),
            $e->status,
            $errors,
        );
    }

    private static function httpExceptionResponse(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        $code = match ($status) {
            401 => 'ERR_UNAUTHENTICATED',
            403 => 'ERR_FORBIDDEN',
            404 => 'ERR_NOT_FOUND',
            422 => 'ERR_VALIDATION',
            429 => 'ERR_RATE_LIMITED',
            default => $status >= 500 ? 'ERR_INTERNAL_SERVER_ERROR' : 'ERR_HTTP_'.$status,
        };

        $fallback = Response::$statusTexts[$status] ?? 'Error.';
        $message = $status >= 500 && ! config('app.debug')
            ? 'Internal server error.'
            : self::resolveMessage($e->getMessage(), $fallback);

        return self::jsonError($code, $message, $status, headers: $e->getHeaders());
    }

    /**
     * Build a standardized JSON error response.
     *
     * @param  array<string, string>  $errors
     * @param  array<string, string>  $headers
     */
    private static function jsonError(
        string $code,
        string $message,
        int $status,
        array $errors = [],
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status, $headers);
    }

    private static function resolveMessage(string $message, string $fallback): string
    {
        return $message !== '' ? $message : $fallback;
    }
}
