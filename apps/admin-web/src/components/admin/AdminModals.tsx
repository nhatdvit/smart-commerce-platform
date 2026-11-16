'use client';

import { useEffect, useState, type ReactNode } from 'react';

import { ORDER_STATUS_LABELS, nextOrderStatus } from '@/data/admin-demo';
import { money } from '@/lib/money';

import { useAdminData } from './AdminDataProvider';

interface ModalFrameProps {
  readonly title: string;
  readonly children: ReactNode;
  readonly actions?: ReactNode;
}

function ModalFrame({ title, children, actions }: ModalFrameProps) {
  const { closeModal } = useAdminData();

  return (
    <div className="modal">
      <h2>{title}</h2>
      {children}
      <div className="modal-actions">
        <button type="button" className="btn" onClick={closeModal}>
          Đóng
        </button>
        {actions}
      </div>
    </div>
  );
}

function OrderModal({ orderId }: { orderId: string }) {
  const { orders, advanceOrder } = useAdminData();
  const order = orders.find((candidate) => candidate.id === orderId);
  if (order === undefined) {
    return null;
  }
  const next = nextOrderStatus(order.status);

  return (
    <ModalFrame
      title={`Đơn hàng ${order.id}`}
      actions={
        next === undefined ? undefined : (
          <button type="button" className="btn btn-primary" onClick={() => advanceOrder(order.id)}>
            Chuyển sang: {ORDER_STATUS_LABELS[next]}
          </button>
        )
      }
    >
      <p>
        <b>Khách:</b> {order.name}
        <br />
        <b>Món:</b> {order.items}
        <br />
        <b>Giờ nhận:</b> {order.time}
        <br />
        <b>Thanh toán:</b> {order.payment}
        <br />
        <b>Tổng tiền:</b> {money(order.total)}
        <br />
        <b>Trạng thái:</b> {ORDER_STATUS_LABELS[order.status]}
      </p>
    </ModalFrame>
  );
}

function ProductModal({ productId }: { productId: number }) {
  const { products, saveProduct } = useAdminData();
  const product = products.find((candidate) => candidate.id === productId);
  const [price, setPrice] = useState(String(product?.price ?? 0));
  const [stock, setStock] = useState(String(product?.stock ?? 0));

  if (product === undefined) {
    return null;
  }

  return (
    <ModalFrame
      title="Chỉnh sửa món ăn"
      actions={
        <button
          type="button"
          className="btn btn-primary"
          onClick={() => saveProduct(product.id, Number(price), Number(stock))}
        >
          Lưu thay đổi
        </button>
      }
    >
      <p>
        {product.emoji} {product.name} · {product.cat}
      </p>
      <label className="form-field">
        <span>Giá bán (VND)</span>
        <input
          type="number"
          min={0}
          step={1000}
          value={price}
          onChange={(event) => setPrice(event.target.value)}
        />
      </label>
      <label className="form-field">
        <span>Số suất còn lại</span>
        <input
          type="number"
          min={0}
          value={stock}
          onChange={(event) => setStock(event.target.value)}
        />
      </label>
    </ModalFrame>
  );
}

function StockModal({ productId }: { productId: number }) {
  const { products, saveStock } = useAdminData();
  const product = products.find((candidate) => candidate.id === productId);
  const [stock, setStock] = useState(String(product?.stock ?? 0));

  if (product === undefined) {
    return null;
  }

  return (
    <ModalFrame
      title="Điều chỉnh số suất"
      actions={
        <button
          type="button"
          className="btn btn-primary"
          onClick={() => saveStock(product.id, Number(stock))}
        >
          Cập nhật
        </button>
      }
    >
      <p>
        {product.emoji} {product.name} · Đã chuẩn bị {product.capacity} suất
      </p>
      <label className="form-field">
        <span>Số suất còn bán</span>
        <input
          type="number"
          min={0}
          value={stock}
          onChange={(event) => setStock(event.target.value)}
        />
      </label>
    </ModalFrame>
  );
}

function AddCouponModal() {
  const { addCoupon } = useAdminData();
  const [code, setCode] = useState('');

  return (
    <ModalFrame
      title="Tạo mã ưu đãi"
      actions={
        <button type="button" className="btn btn-primary" onClick={() => addCoupon(code)}>
          Tạo mã
        </button>
      }
    >
      <label className="form-field">
        <span>Mã ưu đãi</span>
        <input
          maxLength={20}
          placeholder="VD: SANGMOI"
          value={code}
          onChange={(event) => setCode(event.target.value)}
        />
      </label>
      <p>Mẫu demo sẽ tạo mã giảm 5.000đ cho đơn từ 50.000đ.</p>
    </ModalFrame>
  );
}

function AddRewardModal() {
  const { addReward } = useAdminData();
  const [name, setName] = useState('');
  const [points, setPoints] = useState('100');

  return (
    <ModalFrame
      title="Thêm quà đổi điểm"
      actions={
        <button
          type="button"
          className="btn btn-primary"
          onClick={() => addReward(name, Number(points))}
        >
          Thêm quà
        </button>
      }
    >
      <label className="form-field">
        <span>Tên món đổi thưởng</span>
        <input
          maxLength={100}
          placeholder="VD: Trà tắc"
          value={name}
          onChange={(event) => setName(event.target.value)}
        />
      </label>
      <label className="form-field">
        <span>Số điểm cần đổi</span>
        <input
          type="number"
          min={1}
          value={points}
          onChange={(event) => setPoints(event.target.value)}
        />
      </label>
    </ModalFrame>
  );
}

function LoyaltySettingsModal() {
  const { notify, closeModal } = useAdminData();
  const [rate, setRate] = useState('10000');

  function save() {
    const value = Number(rate);
    if (!Number.isInteger(value) || value < 1000) {
      notify('Số tiền tích điểm không hợp lệ');

      return;
    }
    closeModal();
    notify(`Đã lưu cấu hình demo: ${money(value)} = 1 điểm`);
  }

  return (
    <ModalFrame
      title="Cấu hình tích điểm"
      actions={
        <button type="button" className="btn btn-primary" onClick={save}>
          Lưu cấu hình
        </button>
      }
    >
      <p>Quy tắc minh họa: mỗi số tiền chi tiêu hợp lệ sẽ được 1 điểm.</p>
      <label className="form-field">
        <span>Số tiền để nhận 1 điểm (VND)</span>
        <input
          type="number"
          min={1000}
          step={1000}
          value={rate}
          onChange={(event) => setRate(event.target.value)}
        />
      </label>
    </ModalFrame>
  );
}

function CouponDetailModal({ code }: { code: string }) {
  const { coupons } = useAdminData();
  const coupon = coupons.find((candidate) => candidate.code === code);
  if (coupon === undefined) {
    return null;
  }

  return (
    <ModalFrame title={`Mã ${coupon.code}`}>
      <p>
        {coupon.desc}
        <br />
        Đã dùng {coupon.used}/{coupon.limit} lượt.
      </p>
      <p>
        Cấu hình mức giảm, giá trị đơn tối thiểu, thời hạn và giới hạn số lượt sẽ được kết nối API
        trong giai đoạn triển khai.
      </p>
    </ModalFrame>
  );
}

/** Modal host: one dialog at a time, backdrop click and Escape both close it. */
export function AdminModals() {
  const { modal, closeModal } = useAdminData();

  useEffect(() => {
    if (modal === null) {
      return;
    }

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        closeModal();
      }
    }

    document.addEventListener('keydown', onKeyDown);

    return () => document.removeEventListener('keydown', onKeyDown);
  }, [modal, closeModal]);

  if (modal === null) {
    return <div className="modal-backdrop" />;
  }

  return (
    <div
      className="modal-backdrop open"
      role="presentation"
      onClick={(event) => {
        if (event.target === event.currentTarget) {
          closeModal();
        }
      }}
    >
      {modal.kind === 'order' ? <OrderModal orderId={modal.orderId} /> : null}
      {modal.kind === 'product' ? <ProductModal productId={modal.productId} /> : null}
      {modal.kind === 'stock' ? <StockModal productId={modal.productId} /> : null}
      {modal.kind === 'add-coupon' ? <AddCouponModal /> : null}
      {modal.kind === 'add-reward' ? <AddRewardModal /> : null}
      {modal.kind === 'loyalty-settings' ? <LoyaltySettingsModal /> : null}
      {modal.kind === 'coupon-detail' ? <CouponDetailModal code={modal.code} /> : null}
      {modal.kind === 'add-product' ? (
        <ModalFrame title="Thêm món mới">
          <p>Đây là bản demo quản trị. Chức năng thêm món đầy đủ sẽ được kết nối Laravel API.</p>
        </ModalFrame>
      ) : null}
    </div>
  );
}
