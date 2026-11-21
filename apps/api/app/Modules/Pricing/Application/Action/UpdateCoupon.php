<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Action;

use App\Modules\Pricing\Application\DTO\CouponInput;
use App\Modules\Pricing\Domain\Contract\CouponRepository;
use App\Modules\Pricing\Domain\Entity\Coupon;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Edits a discount code: what it takes off, when it runs, whether it is on.
 *
 * The code itself is not editable, and the request is refused rather than
 * ignored when it tries. A code lives on a sign by the door and in customers'
 * messages; changing it silently would strand everyone holding the old one.
 * Retiring this coupon and creating another says the same thing honestly and
 * keeps the record of what the old one did.
 */
final readonly class UpdateCoupon
{
    public function __construct(
        private CouponRepository $coupons,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(string $storeId, string $couponId, CouponInput $input): Coupon
    {
        return DB::transaction(function () use ($storeId, $couponId, $input): Coupon {
            $existing = $this->coupons->findInStore($storeId, $couponId);

            if ($existing === null) {
                // A coupon of another store reads as missing (CLAUDE.md §8).
                throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Coupon not found');
            }

            if (strtoupper(trim($input->code)) !== $existing->code) {
                throw new BusinessRuleViolation(
                    ErrorCode::ValidationFailed,
                    'A coupon code cannot be changed after it has been created',
                    [[
                        'field' => 'code',
                        'message' => 'Không đổi được mã đã tạo. Hãy lưu trữ mã này và tạo mã mới.',
                    ]],
                );
            }

            $updated = $this->coupons->update($couponId, $existing->withEdits(
                $input->description,
                $input->discountType,
                $input->discountValue,
                $input->maxDiscount,
                $input->minOrder,
                $input->usageLimit,
                $input->startsAt,
                $input->endsAt,
                $input->status,
            ));

            $this->audit->record(
                AuditEntry::for('pricing.coupon.update', 'coupon', $couponId, [
                    'code' => $updated->code,
                    'discount_value' => ['from' => $existing->discountValue, 'to' => $updated->discountValue],
                    'min_order_amount' => ['from' => $existing->minOrder->amount, 'to' => $updated->minOrder->amount],
                    'usage_limit' => ['from' => $existing->usageLimit, 'to' => $updated->usageLimit],
                    'status' => ['from' => $existing->status->value, 'to' => $updated->status->value],
                ])->inStore($storeId),
            );

            return $updated;
        });
    }
}
