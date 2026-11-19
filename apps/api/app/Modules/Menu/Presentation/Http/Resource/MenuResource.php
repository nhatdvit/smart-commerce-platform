<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Http\Resource;

use App\Modules\Menu\Application\DTO\MenuView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Menu` schema from the designed contract.
 *
 * `schedules` and `exceptions` are required by the contract and answered as
 * empty arrays: this milestone stores no windows, and an empty list is the
 * truthful answer - this menu has no schedule - rather than a field quietly
 * missing from a documented response.
 *
 * @property-read MenuView $resource
 */
final class MenuResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $menu = $this->resource->menu;

        return [
            'id' => $menu->id,
            'store_id' => $menu->storeId,
            'name' => $menu->name,
            'status' => $menu->status->value,
            'schedules' => [],
            'exceptions' => [],
            'items' => MenuItemResource::collection($this->resource->items)->resolve(),
        ];
    }
}
