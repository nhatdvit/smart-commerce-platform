<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Liveness and readiness probes.
 *
 * These sit outside the versioned API prefix so orchestrators never depend on
 * the business contract.
 *
 * live  - is the process up? No dependency checks, so a brief database blip
 *         does not get a healthy container killed and restarted in a loop.
 * ready - may this instance receive traffic? Checks the dependencies it cannot
 *         serve without, and answers 503 when one is down so the load balancer
 *         takes it out of rotation instead of returning errors to customers.
 */
final class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        // No process uptime is reported: PHP-FPM serves each request from a
        // recycled worker, so any number here would describe that worker
        // rather than the service, and would read as near zero every time.
        return new JsonResponse([
            'status' => 'ok',
            'checked_at' => now()->toIso8601ZuluString(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static function (): void {
                DB::connection()->select('SELECT 1');
            }),
            'redis' => $this->check(static function (): void {
                $reply = Redis::connection()->ping();

                // phpredis answers with true or "PONG" depending on version.
                if ($reply !== true && $reply !== 'PONG' && $reply !== '+PONG') {
                    throw new RuntimeException('Unexpected PING reply');
                }
            }),
        ];

        $healthy = array_reduce(
            $checks,
            static fn (bool $carry, array $check): bool => $carry && $check['status'] === 'up',
            true,
        );

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'error', 'checks' => $checks],
            $healthy ? 200 : 503,
        );
    }

    /**
     * @param  callable(): void  $probe
     * @return array{status: string, message?: string}
     */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['status' => 'up'];
        } catch (Throwable $e) {
            // The class name is useful and safe; the message may carry a DSN
            // or credentials, so it never reaches the response body.
            report($e);

            return ['status' => 'down', 'message' => class_basename($e)];
        }
    }
}
