<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Action;

use App\Modules\Catalog\Application\DTO\StoredImageRef;
use App\Modules\Catalog\Domain\Contract\ProductImageStorage;
use App\Modules\Catalog\Domain\Contract\ProductRepository;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use DateTimeImmutable;

/**
 * Deletes uploaded photos no product points at.
 *
 * Two things leave files behind: a form abandoned after the upload, and a
 * photo replaced by another. Neither is an error, and neither cleans up after
 * itself, because an upload deliberately happens before the product exists.
 *
 * The rule has one safety property that matters more than thoroughness: a file
 * is only ever deleted when it is BOTH unreferenced AND older than the grace
 * period. A photo uploaded a minute ago and still sitting in an open dialog
 * looks exactly like an orphan, and deleting it would break a save the shop
 * owner is halfway through. Waiting a day costs a few kilobytes.
 *
 * Archived products keep their photos: a retired dish is still readable, and
 * an order's receipt pointing at a deleted file is worse than a file nobody
 * shows.
 */
final readonly class PruneProductImages
{
    public function __construct(
        private ProductImageStorage $storage,
        private ProductRepository $products,
        private RecordsAuditTrail $audit,
    ) {}

    /**
     * @param  bool  $dryRun  report what would go, delete nothing
     * @return list<StoredImageRef> the files deleted, or that would be
     */
    public function handle(DateTimeImmutable $writtenBefore, bool $dryRun = false): array
    {
        $candidates = $this->storage->olderThan($writtenBefore);

        if ($candidates === []) {
            return [];
        }

        $deleted = [];

        foreach ($this->groupByStore($candidates) as $storeId => $images) {
            $inUse = $this->products->imageUrlsInUse(
                $storeId,
                array_values(array_map(static fn (StoredImageRef $i): string => $i->imageUrl, $images)),
            );

            foreach ($images as $image) {
                if (in_array($image->imageUrl, $inUse, true)) {
                    continue;
                }

                if ($dryRun) {
                    $deleted[] = $image;

                    continue;
                }

                if (! $this->storage->delete($image)) {
                    // Already gone - another run, or a hand-cleaned disk.
                    continue;
                }

                // Deleting a shop's file is worth a line in the trail, even
                // when nothing was pointing at it. The actor is null: this runs
                // on a schedule, with nobody behind it.
                $this->audit->record(
                    AuditEntry::for(
                        'catalog.product-image.prune',
                        'product_image',
                        basename($image->imageUrl),
                        ['image_url' => $image->imageUrl],
                    )->inStore($storeId),
                );

                $deleted[] = $image;
            }
        }

        return $deleted;
    }

    /**
     * @param  list<StoredImageRef>  $images
     * @return array<string, list<StoredImageRef>>
     */
    private function groupByStore(array $images): array
    {
        $grouped = [];

        foreach ($images as $image) {
            $grouped[$image->storeId][] = $image;
        }

        return $grouped;
    }
}
