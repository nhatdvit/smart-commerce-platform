'use client';

import { useAdminData } from '../AdminDataProvider';
import { Metric, PageHeading } from '../primitives';

export function InventoryScreen() {
  const { products, openModal } = useAdminData();

  const totalCapacity = products.reduce((sum, product) => sum + product.capacity, 0);
  const totalStock = products.reduce((sum, product) => sum + product.stock, 0);
  const lowCount = products.filter((product) => product.stock < 10).length;
  const emptyCount = products.filter((product) => product.stock === 0).length;

  return (
    <>
      <PageHeading
        title="Số suất & tồn kho"
        description="Kiểm soát số lượng chuẩn bị để tránh nhận đơn vượt khả năng phục vụ."
        actions={
          <button
            type="button"
            className="btn btn-primary"
            onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
          >
            ＋ Cập nhật số suất
          </button>
        }
      />

      <div className="metrics">
        <Metric
          title="Tổng suất chuẩn bị"
          value={totalCapacity}
          icon="▥"
          color="#e9f0ff"
          foot="Tất cả món hôm nay"
        />
        <Metric
          title="Suất còn bán"
          value={totalStock}
          icon="◉"
          color="#e9f8f0"
          foot="Cập nhật theo demo"
        />
        <Metric title="Món sắp hết" value={lowCount} icon="!" color="#fff0e9" foot="Cần bổ sung" />
        <Metric
          title="Món hết hàng"
          value={emptyCount}
          icon="×"
          color="#f2f3f6"
          foot="Kiểm tra thực đơn"
        />
      </div>

      <div className="inventory-list">
        {products.map((product) => {
          const percent = Math.round((product.stock / product.capacity) * 100);
          const progressClass = [
            'progress',
            percent < 20 ? 'low' : '',
            percent === 0 ? 'empty' : '',
          ]
            .filter((part) => part !== '')
            .join(' ');

          return (
            <div className="inventory-item" key={product.id}>
              <div>
                <strong>
                  {product.emoji} {product.name}
                </strong>
                <small>{product.cat}</small>
              </div>
              <div className={progressClass}>
                <div style={{ width: `${percent}%` }} />
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
    </>
  );
}
