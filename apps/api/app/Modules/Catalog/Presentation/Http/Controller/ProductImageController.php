<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Controller;

use App\Modules\Catalog\Application\Action\UploadProductImage;
use App\Modules\Catalog\Domain\Contract\ProductImageStorage;
use App\Modules\Catalog\Domain\Entity\Product;
use App\Modules\Catalog\Presentation\Http\Request\UploadProductImageRequest;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Upload and delivery of product photography.
 *
 * Upload is store-scoped and needs `catalog.manage`; delivery is public,
 * because product photos are what a storefront shows. The file name is a
 * random UUID, so nothing can be enumerated from the outside.
 */
final class ProductImageController extends Controller
{
    public function __construct(private readonly ProductImageStorage $storage) {}

    public function store(
        UploadProductImageRequest $request,
        StoreContext $storeContext,
        UploadProductImage $uploadImage,
    ): JsonResponse {
        Gate::authorize('create', Product::class);

        /** @var list<string> $allowed */
        $allowed = (array) config('commerce.media.image_mime_types', []);

        $stored = $uploadImage->handle(
            $storeContext->require()->id,
            $request->contents(),
            $allowed,
            (int) config('commerce.media.max_bytes', 2 * 1024 * 1024),
        );

        return new JsonResponse([
            'image_url' => $stored->imageUrl,
            'content_type' => $stored->contentType,
            'byte_size' => $stored->byteSize,
        ], 201);
    }

    /**
     * Serves one stored photo.
     *
     * The content type is pinned to what the file was accepted as and
     * `nosniff` forbids the browser from deciding otherwise, so a file can
     * never be interpreted as anything but that image type. Both route
     * parameters are constrained to a UUID shape, which leaves no way to
     * express a path at all, let alone one that climbs out of the directory.
     */
    public function show(string $store, string $file): Response
    {
        $image = $this->storage->read($store, $file);

        if ($image === null) {
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Image not found');
        }

        return new Response($image['contents'], 200, [
            'Content-Type' => $image['content_type'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            // The name is a UUID and the bytes behind it never change, so the
            // answer is immutable: a re-upload produces a different name.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
