<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Console;

use App\Modules\Catalog\Application\Action\PruneProductImages;
use Illuminate\Console\Command;

/**
 * Deletes uploaded product photos nothing points at.
 *
 * An upload happens before the product exists, so an abandoned form - or a
 * photo swapped for another - leaves a file behind. Without this they
 * accumulate for the life of the system.
 *
 * The grace period is the safety rule: a file uploaded minutes ago and still
 * held by an open dialog is indistinguishable from an orphan, so only files
 * older than `--hours` are even considered. The default is generous on
 * purpose; storage is cheaper than a save that fails because its photo
 * vanished mid-edit.
 */
final class PruneProductImagesCommand extends Command
{
    protected $signature = 'catalog:prune-product-images
        {--hours=24 : Leave files younger than this alone}
        {--dry-run : Report what would be deleted, delete nothing}';

    protected $description = 'Delete uploaded product photos no product references';

    public function handle(PruneProductImages $prune): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');

        $deleted = $prune->handle(now()->subHours($hours)->toDateTimeImmutable(), $dryRun);

        if ($deleted === []) {
            $this->info('No unreferenced product images to prune.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d unreferenced product image(s) older than %d hour(s).',
            $dryRun ? 'Would prune' : 'Pruned',
            count($deleted),
            $hours,
        ));

        if ($this->output->isVerbose()) {
            foreach ($deleted as $image) {
                $this->line('  '.$image->imageUrl);
            }
        }

        return self::SUCCESS;
    }
}
