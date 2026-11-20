'use client';

import type { MenuProduct } from '@/lib/menu-api';

import { useAdminData } from '../AdminDataProvider';
import { IconAlert, IconBoxes, IconCheckCircle, IconPlus, IconXCircle } from '../icons';
import { ProductThumb } from '../ProductThumb';
import { Metric, PageHeading } from '../primitives';

/**
 * When a dish counts as "nearly out".
 *
 * A share of what was prepared, not a flat number: eight portions left of ten
 * is nearly out, eight of eighty is a normal morning. Named rather than
 * written into three expressions, so the badge, the bar and the counter cannot
 * disagree about what "low" means (CLAUDE.md section 12).
 */
const LOW_STOCK_SHARE = 0.2;

/** Percentage left, for the bar. Null when nothing was prepared to divide by. */
function remainingPercent(product: MenuProduct): number | null {
  if (product.capacity <= 0) {
    // Dividing by zero rendered `NaN%` as a bar width, which the browser
    // silently ignored - the bar simply never moved.
    return null;
  }

  return Math.min(100, Math.round((product.stock / product.capacity) * 100));
}

function isSoldOut(product: MenuProduct): boolean {
  return product.stock === 0;
}

function isLow(product: MenuProduct): boolean {
  const percent = remainingPercent(product);

  return !isSoldOut(product) && percent !== null && percent <= LOW_STOCK_SHARE * 100;
}

export function InventoryScreen() {
  const { products, openModal, menuBusy, menuError } = useAdminData();

  const totalCapacity = products.reduce((sum, product) => sum + product.capacity, 0);
  const totalStock = products.reduce((sum, product) => sum + product.stock, 0);
  const lowCount = products.filter(isLow).length;
  const soldOutCount = products.filter(isSoldOut).length;

  return (
    <>
      <PageHeading
        title="Số suất & tồn kho"
        description="Kiểm soát số lượng chuẩn bị để tránh nhận đơn vượt khả năng phục vụ."
        actions={
          <button
            type="button"
            className="btn btn-primary"
            disabled={products.length === 0}
            onClick={() => openModal({ kind: 'daily-portions' })}
          >
            <IconPlus size={15} /> Cập nhật số suất
          </button>
        }
      />

      <div className="metrics">
        <Metric
          title="Tổng suất chuẩn bị"
          value={totalCapacity}
          icon={<IconBoxes size={18} />}
          color="#e9f0ff"
          foot={`${products.length} món trên thực đơn`}
        />
        <Metric
          title="Suất còn bán"
          value={totalStock}
          icon={<IconCheckCircle size={18} />}
          color="#e9f8f0"
          foot={totalCapacity === 0 ? 'Chưa ghi số suất hôm nay' : 'Còn lại hôm nay'}
        />
        <Metric
          title="Món sắp hết"
          value={lowCount}
          icon={<IconAlert size={18} />}
          color="#fff0e9"
          foot={`Còn dưới ${Math.round(LOW_STOCK_SHARE * 100)}% số đã chuẩn bị`}
        />
        <Metric
          title="Món hết hàng"
          value={soldOutCount}
          icon={<IconXCircle size={18} />}
          color="#f2f3f6"
          foot={soldOutCount === 0 ? 'Chưa món nào hết' : 'Khách không đặt được'}
        />
      </div>

      {/*
       * A menu that could not be loaded is not an empty menu. Saying so keeps
       * staff from "fixing" it by re-entering a morning that is already there.
       */}
      {menuError === '' ? null : (
        <div className="panel empty">
          <strong>Không tải được thực đơn</strong>
          {menuError}
        </div>
      )}

      {menuError !== '' ? null : products.length === 0 ? (
        <div className="panel empty">
          <strong>{menuBusy ? 'Đang tải thực đơn...' : 'Thực đơn chưa có món nào'}</strong>
          {menuBusy
            ? 'Vui lòng đợi trong giây lát.'
            : 'Thêm món ở màn hình Thực đơn, rồi quay lại đây để ghi số suất chuẩn bị.'}
        </div>
      ) : (
        <div className="inventory-list">
          {products.map((product) => {
            const percent = remainingPercent(product);
            const progressClass = [
              'progress',
              isLow(product) ? 'low' : '',
              isSoldOut(product) ? 'empty' : '',
            ]
              .filter((part) => part !== '')
              .join(' ');

            return (
              <div className="inventory-item" key={product.id}>
                <div>
                  <strong>
                    <ProductThumb product={product} size={16} /> {product.name}
                  </strong>
                  <small>{product.cat}</small>
                </div>
                <div
                  className={progressClass}
                  role="img"
                  aria-label={
                    percent === null
                      ? `${product.name}: chưa ghi số suất chuẩn bị`
                      : `${product.name}: còn ${percent}% số suất đã chuẩn bị`
                  }
                >
                  <div style={{ width: `${percent ?? 0}%` }} />
                </div>
                <div>
                  <strong>
                    {product.stock}/{product.capacity}
                  </strong>
                </div>
                <div>
                  <button
                    type="button"
                    className="btn"
                    onClick={() => openModal({ kind: 'stock', productId: product.id })}
                  >
                    Điều chỉnh
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </>
  );
}
