'use client';

import { useEffect, useState, type ReactNode } from 'react';

import { ORDER_STATUS_LABELS, nextOrderStatus } from '@/data/admin-demo';
import { money } from '@/lib/money';
import {
  EMPTY_PRODUCT_FORM,
  FOOD_ICON_CHOICES,
  MAX_CATEGORY_LENGTH,
  MAX_NAME_LENGTH,
  productFormValues,
  validateProductForm,
  type ProductFieldErrors,
  type ProductFormValues,
} from '@/lib/product-form';

import { useAdminData } from './AdminDataProvider';
import { FoodIcon } from './icons';

interface ModalFrameProps {
  readonly title: string;
  readonly children: ReactNode;
  readonly actions?: ReactNode;
  /**
   * When given, the body is a real `<form>` and Enter submits it. Without it
   * the dialog stays a plain div, as the read-only dialogs want.
   */
  readonly onSubmit?: () => void;
}

function ModalFrame({ title, children, actions, onSubmit }: ModalFrameProps) {
  const { closeModal } = useAdminData();

  const body = (
    <>
      {children}
      <div className="modal-actions">
        <button type="button" className="btn" onClick={closeModal}>
          Đóng
        </button>
        {actions}
      </div>
    </>
  );

  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={title}>
      <h2>{title}</h2>
      {onSubmit === undefined ? (
        body
      ) : (
        // noValidate: the browser's own bubbles would pre-empt the inline
        // messages, and cannot express the cross-field rule stock <= capacity.
        <form
          noValidate
          onSubmit={(event) => {
            event.preventDefault();
            onSubmit();
          }}
        >
          {body}
        </form>
      )}
    </div>
  );
}

interface FieldProps {
  readonly label: string;
  readonly error?: string;
  readonly hint?: string;
  readonly children: ReactNode;
}

/** A labelled input with room for one validation message underneath. */
function Field({ label, error, hint, children }: FieldProps) {
  return (
    <label className="form-field">
      <span>{label}</span>
      {children}
      {error === undefined ? (
        hint === undefined ? null : (
          <em className="field-hint">{hint}</em>
        )
      ) : (
        <em className="field-error" role="alert">
          {error}
        </em>
      )}
    </label>
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

/**
 * Add and edit are the same dialog: `productId` undefined means add.
 *
 * One component on purpose - the previous pair had drifted to the point where
 * editing could change a price and a portion count while adding was a
 * placeholder with no fields at all.
 */
function ProductFormModal({ productId }: { readonly productId?: number }) {
  const { products, createProduct, updateProduct } = useAdminData();
  const editing = products.find((candidate) => candidate.id === productId);
  const [values, setValues] = useState<ProductFormValues>(() =>
    editing === undefined ? EMPTY_PRODUCT_FORM : productFormValues(editing),
  );
  const [errors, setErrors] = useState<ProductFieldErrors>({});

  // An id that matches nothing: the item was deleted under the open dialog.
  // Render nothing rather than quietly re-adding it as a new one on save.
  if (productId !== undefined && editing === undefined) {
    return null;
  }

  const categories = [...new Set(products.map((product) => product.cat))];

  function set<K extends keyof ProductFormValues>(key: K, value: ProductFormValues[K]) {
    setValues((current) => ({ ...current, [key]: value }));
    // Clear that field's message as soon as it is touched; the next submit
    // decides whether it comes back.
    setErrors((current) => {
      if (current[key] === undefined) {
        return current;
      }
      const next = { ...current };
      delete next[key];

      return next;
    });
  }

  function submit() {
    const result = validateProductForm(values, products, productId);
    if (!result.ok) {
      setErrors(result.errors);

      return;
    }
    if (productId === undefined) {
      createProduct(result.draft);
    } else {
      updateProduct(productId, result.draft);
    }
  }

  return (
    <ModalFrame
      title={productId === undefined ? 'Thêm món mới' : 'Chỉnh sửa món ăn'}
      onSubmit={submit}
      actions={
        <button type="submit" className="btn btn-primary">
          {productId === undefined ? 'Thêm vào thực đơn' : 'Lưu thay đổi'}
        </button>
      }
    >
      <Field label="Tên món" error={errors.name}>
        <input
          autoFocus
          maxLength={MAX_NAME_LENGTH}
          placeholder="VD: Bánh mì chả lụa"
          aria-invalid={errors.name !== undefined}
          value={values.name}
          onChange={(event) => set('name', event.target.value)}
        />
      </Field>

      <Field
        label="Danh mục"
        error={errors.cat}
        hint="Chọn danh mục có sẵn hoặc nhập tên danh mục mới."
      >
        <input
          list="product-categories"
          maxLength={MAX_CATEGORY_LENGTH}
          placeholder="VD: Bánh mì"
          aria-invalid={errors.cat !== undefined}
          value={values.cat}
          onChange={(event) => set('cat', event.target.value)}
        />
      </Field>
      <datalist id="product-categories">
        {categories.map((name) => (
          <option key={name} value={name} />
        ))}
      </datalist>

      <Field label="Ảnh minh hoạ">
        <select
          value={values.icon}
          onChange={(event) =>
            // Narrowed through the choice list rather than cast, so an
            // unexpected value cannot reach FoodIcon as a valid name.
            set(
              'icon',
              FOOD_ICON_CHOICES.find((choice) => choice.value === event.target.value)?.value ??
                values.icon,
            )
          }
        >
          {FOOD_ICON_CHOICES.map((choice) => (
            <option key={choice.value} value={choice.value}>
              {choice.label}
            </option>
          ))}
        </select>
      </Field>

      <Field label="Giá bán (VND)" error={errors.price}>
        <input
          type="number"
          min={0}
          step={1000}
          placeholder="25000"
          aria-invalid={errors.price !== undefined}
          value={values.price}
          onChange={(event) => set('price', event.target.value)}
        />
      </Field>

      <div className="form-row">
        <Field label="Số suất chuẩn bị" error={errors.capacity}>
          <input
            type="number"
            min={0}
            placeholder="40"
            aria-invalid={errors.capacity !== undefined}
            value={values.capacity}
            onChange={(event) => set('capacity', event.target.value)}
          />
        </Field>
        <Field label="Số suất còn lại" error={errors.stock}>
          <input
            type="number"
            min={0}
            placeholder="40"
            aria-invalid={errors.stock !== undefined}
            value={values.stock}
            onChange={(event) => set('stock', event.target.value)}
          />
        </Field>
      </div>
    </ModalFrame>
  );
}

function DeleteProductModal({ productId }: { readonly productId: number }) {
  const { products, deleteProduct } = useAdminData();
  const product = products.find((candidate) => candidate.id === productId);
  if (product === undefined) {
    return null;
  }

  return (
    <ModalFrame
      title="Xoá món khỏi thực đơn"
      actions={
        <button type="button" className="btn btn-danger" onClick={() => deleteProduct(product.id)}>
          Xoá món
        </button>
      }
    >
      <p>
        Xoá <b>{product.name}</b> khỏi thực đơn? Món đang còn {product.stock}/{product.capacity}{' '}
        suất.
      </p>
      <p className="notice">
        Bản demo xoá ngay trong bộ nhớ. Khi nối API, món đã từng được đặt sẽ được ngừng bán thay vì
        xoá khỏi cơ sở dữ liệu: đơn cũ phải giữ nguyên bản chụp món đã bán (CLAUDE.md mục 6).
      </p>
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
        <FoodIcon name={product.icon} size={16} /> {product.name} · Đã chuẩn bị {product.capacity}{' '}
        suất
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
      {modal.kind === 'product' ? (
        <ProductFormModal key={modal.productId} productId={modal.productId} />
      ) : null}
      {modal.kind === 'delete-product' ? (
        <DeleteProductModal key={modal.productId} productId={modal.productId} />
      ) : null}
      {modal.kind === 'stock' ? <StockModal productId={modal.productId} /> : null}
      {modal.kind === 'add-coupon' ? <AddCouponModal /> : null}
      {modal.kind === 'add-reward' ? <AddRewardModal /> : null}
      {modal.kind === 'loyalty-settings' ? <LoyaltySettingsModal /> : null}
      {modal.kind === 'coupon-detail' ? <CouponDetailModal code={modal.code} /> : null}
      {modal.kind === 'add-product' ? <ProductFormModal /> : null}
    </div>
  );
}
