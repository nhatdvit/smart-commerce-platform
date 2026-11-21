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
 * Creates a discount code for one store.
 *
 * The uniqueness check and the insert are in one transaction, and the database
 * carries the same rule as a unique index: two cashiers creating `SANG10` at
 * the same moment must not end up with two campaigns answering to one code.
 */
final readonly class CreateCoupon
{
    public function __construct(
        private CouponRepository $coupons,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(string $storeId, CouponInput $input): Coupon
    {
        return DB::transaction(function () use ($storeId, $input): Coupon {
            // Built first: the entity normalises the code to upper case, and
            // the duplicate check has to ask about the normalised form.
            $draft = Coupon::create(
                null,
                $storeId,
                $input->code,
                $input->description,
                $input->discountType,
                $input->discountValue,
                $input->maxDiscount,
                $input->minOrder,
                $input->usageLimit,
                0,
                $input->startsAt,
                $input->endsAt,
                $input->status,
            );

            if ($this->coupons->codeExists($storeId, $draft->code)) {
                throw new BusinessRuleViolation(
                    ErrorCode::ValidationFailed,
                    'This store already has a coupon with that code',
                    [['field' => 'code', 'message' => 'Mã này đã tồn tại trong cửa hàng.']],
                );
            }

            $coupon = $this->coupons->create($draft);

            $this->audit->record(
                AuditEntry::for('pricing.coupon.create', 'coupon', (string) $coupon->id, [
                    'code' => $coupon->code,
                    'discount_type' => $coupon->discountType->value,
                    'discount_value' => $coupon->discountValue,
                    'min_order_amount' => $coupon->minOrder->amount,
                    'usage_limit' => $coupon->usageLimit,
                    'status' => $coupon->status->value,
                ])->inStore($storeId),
            );

            return $coupon;
        });
    }
}
