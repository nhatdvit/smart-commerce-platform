<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Contract\ProductImageStorage;
use App\Modules\Catalog\Infrastructure\Persistence\Model\Product;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Housekeeping for uploaded photos.
 *
 * The interesting cases are the two that must NOT be deleted: a photo a
 * product still points at, and a fresh upload an open dialog is still holding.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    Storage::fake('local');
});

/** Writes a stored photo for a store, aged by touching its mtime. */
function storedPhoto(string $storeId, int $hoursOld = 0): string
{
    $fileName = sprintf('%s.webp', Str::uuid7());
    $path = sprintf('media/products/%s/%s', $storeId, $fileName);

    Storage::disk('local')->put($path, 'not-really-webp-but-never-read');

    if ($hoursOld > 0) {
        // The fake disk writes through to a real temporary directory, so the
        // modification time is a real one and can be moved back.
        touch(Storage::disk('local')->path($path), time() - $hoursOld * 3600);
    }

    return sprintf('/api/v1/media/products/%s/%s', $storeId, $fileName);
}

function prune(string $options = ''): int
{
    return Artisan::call(trim('catalog:prune-product-images '.$options));
}

it('deletes a photo no product points at', function (): void {
    $store = Store::factory()->create();
    $orphan = storedPhoto($store->id, hoursOld: 48);

    expect(prune())->toBe(0);

    expect(app(ProductImageStorage::class)->read($store->id, basename($orphan)))->toBeNull();
});

it('keeps a photo a product still points at', function (): void {
    $store = Store::factory()->create();
    $used = storedPhoto($store->id, hoursOld: 48);

    Product::factory()->inStore($store->id)->create(['icon' => null, 'image_url' => $used]);

    prune();

    expect(app(ProductImageStorage::class)->read($store->id, basename($used)))->not->toBeNull();
});

it('keeps a photo an archived product points at', function (): void {
    $store = Store::factory()->create();
    $used = storedPhoto($store->id, hoursOld: 72);

    // A retired dish is still readable, and its receipt still shows the photo.
    Product::factory()->inStore($store->id)->archived()->create([
        'icon' => null,
        'image_url' => $used,
    ]);

    prune();

    expect(app(ProductImageStorage::class)->read($store->id, basename($used)))->not->toBeNull();
});

it('keeps a fresh upload a dialog may still be holding', function (): void {
    $store = Store::factory()->create();
    // Uploaded a minute ago, saved on nothing yet: exactly what an open "add
    // dish" dialog looks like. Deleting it would break the save.
    $fresh = storedPhoto($store->id);

    prune();

    expect(app(ProductImageStorage::class)->read($store->id, basename($fresh)))->not->toBeNull();
});

it('honours a shorter grace period when asked', function (): void {
    $store = Store::factory()->create();
    $recent = storedPhoto($store->id, hoursOld: 2);

    prune('--hours=1');

    expect(app(ProductImageStorage::class)->read($store->id, basename($recent)))->toBeNull();
});

it('deletes nothing on a dry run', function (): void {
    $store = Store::factory()->create();
    $orphan = storedPhoto($store->id, hoursOld: 48);

    prune('--dry-run');

    expect(app(ProductImageStorage::class)->read($store->id, basename($orphan)))->not->toBeNull()
        ->and(Artisan::output())->toContain('Would prune 1');
});

it('judges each store against its own products', function (): void {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    $usedByA = storedPhoto($storeA->id, hoursOld: 48);
    $orphanInB = storedPhoto($storeB->id, hoursOld: 48);

    Product::factory()->inStore($storeA->id)->create(['icon' => null, 'image_url' => $usedByA]);

    prune();

    $storage = app(ProductImageStorage::class);

    expect($storage->read($storeA->id, basename($usedByA)))->not->toBeNull()
        ->and($storage->read($storeB->id, basename($orphanInB)))->toBeNull();
});

it('records a deletion in the audit trail', function (): void {
    $store = Store::factory()->create();
    $orphan = storedPhoto($store->id, hoursOld: 48);

    prune();

    $this->assertDatabaseHas('audit_logs', [
        'store_id' => $store->id,
        'action' => 'catalog.product-image.prune',
        'entity_id' => basename($orphan),
        // Nobody is behind a scheduled run, and saying so is the point.
        'actor_id' => null,
    ]);
});

it('says so when there is nothing to do', function (): void {
    Store::factory()->create();

    expect(prune())->toBe(0)
        ->and(Artisan::output())->toContain('No unreferenced product images');
});
