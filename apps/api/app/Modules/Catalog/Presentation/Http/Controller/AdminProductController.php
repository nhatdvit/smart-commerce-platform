<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Controller;

use App\Modules\Catalog\Application\Action\CreateProduct;
use App\Modules\Catalog\Application\Action\UpdateProduct;
use App\Modules\Catalog\Domain\Contract\ProductRepository;
use App\Modules\Catalog\Domain\Entity\Product;
use App\Modules\Catalog\Presentation\Http\Request\ListProductsRequest;
use App\Modules\Catalog\Presentation\Http\Request\SaveProductRequest;
use App\Modules\Catalog\Presentation\Http\Resource\ProductResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Admin catalog endpoints.
 *
 * Thin by design (CLAUDE.md section 3): authorize, hand the validated input to
 * a use case, shape the answer with a Resource. No business rule and no query
 * chain lives here.
 *
 * The store comes from StoreContext, which the `store.resolve` middleware fills
 * from the route or the host - never from a query parameter a caller controls.
 */
final class AdminProductController extends Controller
{
    /**
     * Only stateless collaborators are injected here.
     *
     * StoreContext is deliberately NOT a constructor dependency: it is scoped
     * to one request, while Laravel memoises the controller instance on the
     * Route object, which outlives a request in any long-lived process - a
     * test suite today, Octane tomorrow. The context would then be the one
     * resolved for an earlier request, and this controller would act on the
     * wrong store. It arrives as a method argument instead, which the
     * dispatcher resolves per call.
     */
    public function __construct(private readonly ProductRepository $products) {}

    /** Master-data products, including drafts and archived ones. */
    public function index(ListProductsRequest $request, StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        $page = $this->products->paginate(
            $storeContext->require()->id,
            $request->statuses(),
            $request->search(),
            $request->cursor(),
            $request->limit(),
        );

        return new JsonResponse([
            'items' => ProductResource::collection($page['items'])->resolve(),
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
        ]);
    }

    public function store(
        SaveProductRequest $request,
        StoreContext $storeContext,
        CreateProduct $createProduct,
    ): JsonResponse {
        Gate::authorize('create', Product::class);

        $product = $createProduct->handle(
            $storeContext->require()->id,
            $request->toInput(),
        );

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(
        SaveProductRequest $request,
        string $product,
        StoreContext $storeContext,
        UpdateProduct $updateProduct,
    ): JsonResponse {
        $storeId = $storeContext->require()->id;
        $existing = $this->products->findInStore($storeId, $product);

        if ($existing === null) {
            // A product of another store reads as missing, not as forbidden:
            // the response must not reveal that the id exists somewhere else
            // (CLAUDE.md section 8).
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Product not found');
        }

        Gate::authorize('update', $existing);

        return (new ProductResource(
            $updateProduct->handle($storeId, $product, $request->toInput()),
        ))->response();
    }
}
