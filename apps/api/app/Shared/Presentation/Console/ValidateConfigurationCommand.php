<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Console;

use App\Shared\Infrastructure\Config\EnvironmentValidator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Explicit configuration check.
 *
 * Run by the container entrypoint before php-fpm or Horizon starts, and by CI,
 * so a misconfigured deployment fails at startup instead of on the first
 * customer request.
 *
 * It exists as a command rather than only as boot-time validation because
 * build-time artisan calls (package:discover, config:cache) boot the
 * application before any environment file exists, and must not be blocked.
 */
final class ValidateConfigurationCommand extends Command
{
    protected $signature = 'app:validate-config';

    protected $description = 'Validate application configuration and fail if anything is missing or unsafe';

    public function handle(EnvironmentValidator $validator): int
    {
        try {
            $validator->validate();
        } catch (RuntimeException $e) {
            $this->components->error('Configuration is not valid');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Configuration is valid for the %s environment.',
            (string) config('app.env'),
        ));

        return self::SUCCESS;
    }
}
