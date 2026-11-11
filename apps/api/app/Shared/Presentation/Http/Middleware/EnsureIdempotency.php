<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http\Middleware;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Infrastructure\Idempotency\IdempotencyGuard;
use App\Shared\Presentation\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires and enforces `Idempotency-Key` on operations that must not run twice.
 *
 * Applied per route with the scope that namespaces the key:
 *
 *     ->middleware('idempotent:order.create')
 *
 * The scope matters: the same key used for an order and for a payment
 * confirmation are different operations, and sharing one namespace would make
 * the second look like a replay of the first.
 */
final class EnsureIdempotency
{
    public const string HEADER = 'Idempotency-Key';

    /**
     * Long enough for a UUID or a ULID, bounded by the column, and restricted
     * to characters that cannot confuse a log line or a header.
     */
    private const string KEY_PATTERN = '/^[A-Za-z0-9._:-]{8,255}$/';

    public function __construct(private readonly IdempotencyGuard $guard) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || $key === '') {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                sprintf('The %s header is required for this operation.', self::HEADER),
                [['field' => self::HEADER, 'message' => 'Required.']],
            );
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                sprintf('The %s header is not a valid key.', self::HEADER),
                [[
                    'field' => self::HEADER,
                    'message' => '8 to 255 characters: letters, digits, dot, underscore, colon or hyphen.',
                ]],
            );
        }

        return $this->guard->run(
            $scope,
            $key,
            $this->fingerprint($request),
            static fn (): Response => $next($request),
        );
    }

    /**
     * Identifies the request this key was used for.
     *
     * Method, path and body — not headers, and not the query string of a
     * different route, because a retry may legitimately carry a new trace id
     * or a refreshed token while being the same operation.
     *
     * The authenticated user is included so one caller cannot replay another
     * stored response by guessing a key.
     */
    private function fingerprint(Request $request): string
    {
        return hash('sha256', implode('|', [
            $request->getMethod(),
            $request->path(),
            (string) $request->user()?->getAuthIdentifier(),
            $request->getContent(),
        ]));
    }
}
