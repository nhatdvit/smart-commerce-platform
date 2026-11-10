<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id, shared by the response header, the log
 * lines, the audit records and the queued jobs it dispatches.
 *
 * An inbound X-Request-Id is reused so a trace started at the gateway or in a
 * client survives, but only after validation: an unchecked header value would
 * end up in log files and response headers.
 */
final class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    /** Conservative on purpose: no spaces, no control characters, bounded length. */
    private const string SAFE_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $inbound = $request->header(self::HEADER);

        $requestId = is_string($inbound) && preg_match(self::SAFE_PATTERN, $inbound) === 1
            ? $inbound
            : (string) Str::uuid7();

        $request->attributes->set('request_id', $requestId);
        $request->headers->set(self::HEADER, $requestId);

        // Every log line for the rest of this request carries the id.
        Log::withContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
