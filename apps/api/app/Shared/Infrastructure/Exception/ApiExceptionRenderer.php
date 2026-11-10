<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Exception;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use App\Shared\Presentation\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Single exit point for API errors.
 *
 * Every failure leaves as the one documented envelope, and nothing internal
 * leaks: no stack traces, no SQL, no credentials (CLAUDE.md section 8). Wired up in
 * bootstrap/app.php.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): JsonResponse
    {
        [$code, $message, $details] = $this->describe($e, $request);

        $body = [
            'status_code' => $code->httpStatus(),
            'code' => $code->value,
            'message' => $message,
            'request_id' => $this->requestId($request),
            'timestamp' => now()->toIso8601ZuluString(),
            'path' => '/'.ltrim($request->path(), '/'),
        ];

        if ($details !== []) {
            $body['details'] = $details;
        }

        // Only ever in local/testing, and only for genuinely unexpected errors:
        // this is the one case where a developer needs the class and location.
        if ($code === ErrorCode::InternalError && config('app.debug') === true) {
            $body['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return new JsonResponse($body, $code->httpStatus());
    }

    /**
     * @return array{0: ErrorCode, 1: string, 2: array<int, array{field: string, message: string}>}
     */
    private function describe(Throwable $e, Request $request): array
    {
        return match (true) {
            $e instanceof BusinessRuleViolation => [$e->errorCode, $e->getMessage(), $e->details],

            $e instanceof ValidationException => [
                ErrorCode::ValidationFailed,
                'Request validation failed',
                $this->flattenValidationErrors($e),
            ],

            $e instanceof AuthenticationException => [
                ErrorCode::Unauthenticated,
                'Authentication required',
                [],
            ],

            $e instanceof AuthorizationException => [
                ErrorCode::Forbidden,
                // The exception message is written by our own policies, so it
                // is safe to surface and is more useful than a generic string.
                $e->getMessage() !== '' ? $e->getMessage() : 'This action is not allowed',
                [],
            ],

            // Never reveal whether the id existed but belonged to another
            // store: that would be an object-enumeration oracle.
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => [
                ErrorCode::ResourceNotFound,
                'Resource not found',
                [],
            ],

            $e instanceof MethodNotAllowedHttpException => [
                ErrorCode::MethodNotAllowed,
                'Method not allowed for this endpoint',
                [],
            ],

            $e instanceof TooManyRequestsHttpException => [
                ErrorCode::RateLimited,
                'Too many requests, please retry later',
                [],
            ],

            // A deliberate abort() keeps its status; the message is only
            // surfaced for client errors, where we wrote it ourselves.
            $e instanceof HttpExceptionInterface => [
                $this->codeForStatus($e->getStatusCode()),
                $e->getStatusCode() < 500 && $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Request could not be completed',
                [],
            ],

            default => [ErrorCode::InternalError, 'Internal server error', []],
        };
    }

    /** @return array<int, array{field: string, message: string}> */
    private function flattenValidationErrors(ValidationException $e): array
    {
        $details = [];

        foreach ($e->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = ['field' => (string) $field, 'message' => (string) $message];
            }
        }

        return $details;
    }

    private function codeForStatus(int $status): ErrorCode
    {
        return match ($status) {
            400 => ErrorCode::ValidationFailed,
            401 => ErrorCode::Unauthenticated,
            403 => ErrorCode::Forbidden,
            404 => ErrorCode::ResourceNotFound,
            405 => ErrorCode::MethodNotAllowed,
            409 => ErrorCode::InvalidStateTransition,
            422 => ErrorCode::ValidationFailed,
            429 => ErrorCode::RateLimited,
            503 => ErrorCode::DependencyUnavailable,
            default => ErrorCode::InternalError,
        };
    }

    private function requestId(Request $request): string
    {
        $id = $request->attributes->get('request_id')
            ?? $request->header(AssignRequestId::HEADER);

        return is_string($id) && $id !== '' ? $id : 'unknown';
    }
}
