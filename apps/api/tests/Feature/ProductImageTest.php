<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Persistence\Model\Product;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Product photography: upload, delivery, and the rule that a dish shows a
 * photo or an icon but never both.
 *
 * `storeWithStaff()` and `productPayload()` live in AdminProductTest.php.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    // A fake disk, so a test run writes nothing into the real storage volume.
    Storage::fake('local');
});

/** A real, tiny PNG. Written byte by byte because the API image has no GD. */
function pngBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    );
}

/** Uploads bytes under a given filename and declared type. */
function uploadImage(string $contents, string $name = 'photo.png', string $type = 'image/png'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'scp');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, $type, null, true);
}

it('stores an uploaded photo and answers where it went', function (): void {
    [$store, $manager] = storeWithStaff();

    $response = $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/admin/products/images', ['file' => uploadImage(pngBytes())])
        ->assertCreated()
        ->assertJsonPath('content_type', 'image/png');

    $imageUrl = (string) $response->json('image_url');

    // The URL carries the store, and a generated name - never the client's.
    expect($imageUrl)->toStartWith("/api/v1/media/products/{$store->id}/")
        ->and($imageUrl)->not->toContain('photo.png')
        ->and($response->json('byte_size'))->toBe(strlen(pngBytes()));
});

it('decides the type from the bytes, not from the name', function (): void {
    [, $manager] = storeWithStaff();

    // A PHP script wearing a .png name and declaring image/png. Trusting
    // either would store something that is served from the dashboard origin.
    $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/admin/products/images', [
            'file' => uploadImage('<?php system($_GET["c"]); ?>', 'innocent.png', 'image/png'),
        ])
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'file');
});

it('refuses an SVG, which can carry script', function (): void {
    [, $manager] = storeWithStaff();

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/admin/products/images', ['file' => uploadImage($svg, 'logo.svg', 'image/svg+xml')])
        ->assertStatus(422);
});

it('refuses a file above the size cap', function (): void {
    [, $manager] = storeWithStaff();

    config(['commerce.media.max_bytes' => 1024]);

    $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/admin/products/images', [
            'file' => UploadedFile::fake()->create('big.png', 4, 'image/png'),
        ])
        ->assertStatus(422);
});

it('refuses an upload from a role without catalog rights', function (): void {
    [, $kitchen] = storeWithStaff('KITCHEN_STAFF');

    $this->actingAs($kitchen, 'sanctum')
        ->post('/api/v1/admin/products/images', ['file' => uploadImage(pngBytes())])
        ->assertForbidden();
});

it('refuses an upload with no session', function (): void {
    storeWithStaff();

    $this->post('/api/v1/admin/products/images', ['file' => uploadImage(pngBytes())])
        ->assertUnauthorized();
});

it('serves the stored photo with its own type and nosniff', function (): void {
    [, $manager] = storeWithStaff();

    $imageUrl = (string) $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/admin/products/images', ['file' => uploadImage(pngBytes())])
        ->json('image_url');

    // Public: a storefront shows these without signing in.
    $this->get($imageUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('answers 404 for a photo that is not there', function (): void {
    [$store] = storeWithStaff();

    $this->get("/api/v1/media/products/{$store->id}/01930000-0000-7000-8000-000000000000.png")
        ->assertNotFound();
});

it('refuses a media path that is not a stored file name', function (): void {
    [$store] = storeWithStaff();

    // The route constraints make a traversal unrepresentable rather than
    // merely rejected: these never reach the controller at all.
    foreach (['..%2f..%2fetc%2fpasswd', 'index.php', 'photo.svg'] as $candidate) {
        $this->get("/api/v1/media/products/{$store->id}/{$candidate}")->assertNotFound();
    }
});

it('saves a product with a photo and no icon', function (): void {
    [, $manager] = storeWithStaff();

    $imageUrl = (string) $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/admin/products/images', ['file' => uploadImage(pngBytes())])
        ->json('image_url');

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload(['icon' => null, 'image_url' => $imageUrl]))
        ->assertCreated()
        ->assertJsonPath('image_url', $imageUrl)
        ->assertJsonPath('icon', null);
});

it('refuses a product carrying both a photo and an icon', function (): void {
    [, $manager] = storeWithStaff();

    $imageUrl = (string) $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/admin/products/images', ['file' => uploadImage(pngBytes())])
        ->json('image_url');

    // Two answers to "what does this dish look like" is not a preference the
    // server gets to guess.
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload(['icon' => 'banh-mi', 'image_url' => $imageUrl]))
        ->assertStatus(422);

    expect(Product::query()->count())->toBe(0);
});

it('refuses an image_url this API did not issue', function (): void {
    [, $manager] = storeWithStaff();

    // A product pointing at someone else's host turns every menu view into a
    // request they can watch.
    foreach ([
        'https://evil.test/tracker.png',
        '/api/v1/media/products/../../../etc/passwd',
        'javascript:alert(1)',
    ] as $candidate) {
        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/admin/products', productPayload(['icon' => null, 'image_url' => $candidate]))
            ->assertStatus(422);
    }
});

it('swaps a photo back for an icon', function (): void {
    [, $manager] = storeWithStaff();

    $imageUrl = (string) $this->actingAs($manager, 'sanctum')
        ->post('/api/v1/admin/products/images', ['file' => uploadImage(pngBytes())])
        ->json('image_url');

    $created = $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload(['icon' => null, 'image_url' => $imageUrl]))
        ->assertCreated()
        ->json();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/products/{$created['id']}", [
            'name' => $created['name'],
            'status' => 'ACTIVE',
            'category_id' => $created['category_id'],
            'icon' => 'xoi',
            // Explicitly null, not omitted: the dish stops showing the photo.
            'image_url' => null,
            'variants' => [[
                'id' => $created['variants'][0]['id'],
                'name' => 'Mặc định',
                'base_price_amount' => '25000',
                'status' => 'ACTIVE',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('icon', 'xoi')
        ->assertJsonPath('image_url', null);
});

it('keeps each store photos under its own path', function (): void {
    [$storeA, $managerA] = storeWithStaff();

    $theirUrl = (string) $this->actingAs($managerA, 'sanctum')
        ->post('/api/v1/admin/products/images', ['file' => uploadImage(pngBytes())])
        ->json('image_url');

    $otherStore = Store::factory()->create();

    // Same file name, a different store segment: nothing is found, because
    // the store is part of where the file lives.
    $fileName = basename($theirUrl);

    $this->get("/api/v1/media/products/{$otherStore->id}/{$fileName}")->assertNotFound();
    $this->get("/api/v1/media/products/{$storeA->id}/{$fileName}")->assertOk();
});
