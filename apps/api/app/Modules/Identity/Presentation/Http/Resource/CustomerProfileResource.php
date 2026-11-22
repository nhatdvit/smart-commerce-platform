<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Resource;

use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The designed `CustomerProfile` schema.
 *
 * `pending_email` is always null: there is no email-change verification flow
 * built yet (`docs/api/openapi.yaml`'s own note on `PUT /customer/profile`),
 * and this account's login identity is its phone number regardless.
 *
 * @property-read User $resource
 */
final class CustomerProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'pending_email' => null,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
