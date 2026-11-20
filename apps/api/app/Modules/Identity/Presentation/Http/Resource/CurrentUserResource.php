<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Resource;

use App\Modules\Identity\Infrastructure\Persistence\Model\Role;
use App\Modules\Identity\Infrastructure\Persistence\Model\StoreMember;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The designed `CurrentUser` schema.
 *
 * `store_memberships` is what the admin app uses to decide which navigation to
 * show. It is informational only: the server re-checks role and store scope on
 * every request regardless, so a client that lies to itself about what it may
 * do gets a 403 rather than access (CLAUDE.md section 8).
 *
 * Store names arrive from the Store module's directory service, because this
 * module does not read another module's tables (section 3).
 *
 * @property-read User $resource
 */
final class CurrentUserResource extends JsonResource
{
    /**
     * @param  array<string, string>  $storeNames  store id => display name
     */
    public function __construct(User $user, private readonly array $storeNames = [])
    {
        parent::__construct($user);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => array_values($user->globalRoles->pluck('code')->all()),
            'store_memberships' => array_values($user->storeMemberships
                ->map(function (StoreMember $membership): array {
                    // A membership always carries a role - the column is NOT
                    // NULL with a foreign key behind it - but the relation is
                    // still typed as nullable, and a half-rendered response is
                    // worse than an explicit answer.
                    $role = $membership->role;

                    return [
                        'store_id' => $membership->store_id,
                        'store_name' => $this->storeNames[$membership->store_id] ?? '',
                        'role' => $role instanceof Role ? $role->code : 'UNKNOWN',
                        'status' => $membership->status->value,
                    ];
                })
                ->all()),
        ];
    }
}
