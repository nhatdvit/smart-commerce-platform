'use client';

import { useEffect, useState, type ReactNode } from 'react';

import { mediaUrl } from '@/lib/api-client';
import {
  COUPON_STATUSES,
  DISCOUNT_TYPES,
  EMPTY_COUPON_FORM,
  MAX_COUPON_CODE_LENGTH,
  MAX_COUPON_DESCRIPTION_LENGTH,
  couponFormValues,
  validateCouponForm,
  type CouponFieldErrors,
  type CouponFormValues,
} from '@/lib/coupon-form';
import { COUPON_STATUS_LABELS, DISCOUNT_TYPE_LABELS, describeUsage } from '@/lib/coupons-api';
import { ACCEPTED_IMAGE_TYPES, uploadProductImage } from '@/lib/image-upload';
import type { PreparedPortions } from '@/lib/menu-api';
import { money } from '@/lib/money';
import {
  FULFILLMENT_LABELS,
  ORDER_STATUS_LABELS,
  PAYMENT_METHOD_LABELS,
  PAYMENT_STATUS_LABELS,
  canCancel,
  dateTimeLabel,
  forwardStatus,
  loadOrderDetail,
  timeLabel,
  type AdminOrderDetail,
} from '@/lib/orders-api';
import {
  EMPTY_PRODUCT_FORM,
  FOOD_ICON_CHOICES,
  MAX_CATEGORY_LENGTH,
  MAX_NAME_LENGTH,
  MAX_PORTIONS,
  productFormValues,
  validateProductForm,
  type ProductFieldErrors,
  type ProductFormValues,
} from '@/lib/product-form';

import { useAdminData } from './AdminDataProvider';
import { FoodIcon, IconGift, IconStar } from './icons';
import { Metric, StatusBadge } from './primitives';

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

/**
 * The photo-or-icon choice.
 *
 * Two radios rather than "upload clears the icon" magic: the shop owner says
 * which of the two the dish shows, and only that one is editable. Picking a
 * file uploads it straight away, so what the dialog previews is the stored
 * image - not a local preview that might never have reached the server.
 */
function ProductImageField({
  values,
  errors,
  set,
}: {
  readonly values: ProductFormValues;
  readonly errors: ProductFieldErrors;
  readonly set: <K extends keyof ProductFormValues>(key: K, value: ProductFormValues[K]) => void;
}) {
  const { notify } = useAdminData();
  const [uploading, setUploading] = useState(false);

  function choose(file: File | undefined) {
    if (file === undefined) {
      return;
    }

    setUploading(true);
    uploadProductImage(file)
      .then((uploaded) => set('imageUrl', uploaded.image_url))
      .catch((error: unknown) =>
        notify(error instanceof Error ? error.message : 'Không tải được ảnh lên.'),
      )
      .finally(() => setUploading(false));
  }

  return (
    <div className="form-field image-field">
      <span>Ảnh minh hoạ</span>

      <div className="image-choice" role="radiogroup" aria-label="Kiểu ảnh minh hoạ">
        <label>
          <input
            type="radio"
            name="product-image-kind"
            checked={values.imageKind === 'icon'}
            onChange={() => set('imageKind', 'icon')}
          />
          Dùng icon
        </label>
        <label>
          <input
            type="radio"
            name="product-image-kind"
            checked={values.imageKind === 'photo'}
            onChange={() => set('imageKind', 'photo')}
          />
          Tải ảnh lên
        </label>
      </div>

      {values.imageKind === 'icon' ? (
        <select
          aria-label="Icon minh hoạ"
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
      ) : (
        <div className="image-upload">
          {values.imageUrl === null ? null : (
            <div className="image-preview">
              {/* A plain img, like every other image here: the path is opaque
                  and served by the API, not a remote pattern next/image can be
                  configured for ahead of time. */}
              <img src={mediaUrl(values.imageUrl)} alt="Ảnh món đã tải lên" />
              <button
                type="button"
                className="table-action danger"
                onClick={() => set('imageUrl', null)}
              >
                Xoá ảnh
              </button>
            </div>
          )}
          <input
            type="file"
            accept={ACCEPTED_IMAGE_TYPES}
            aria-invalid={errors.imageUrl !== undefined}
            disabled={uploading}
            onChange={(event) => choose(event.target.files?.[0])}
          />
          <em className="field-hint">
            {uploading
              ? 'Đang tải ảnh lên...'
              : 'JPEG, PNG hoặc WebP. Ảnh sẽ được thu nhỏ tự động.'}
          </em>
        </div>
      )}

      {errors.imageUrl === undefined ? null : (
        <em className="field-error" role="alert">
          {errors.imageUrl}
        </em>
      )}
    </div>
  );
}

/**
 * One order in full, and the moves a staff member can make from here.
 *
 * The detail is fetched rather than read from the queue row: the row carries
 * what a glance needs, while this dialog shows the line prices, the payment
 * and the recorded history - the answer to "who moved this order, and why".
 *
 * Which buttons appear mirrors the server's state machine, and the server
 * still decides: it validates the move against the committed status under a
 * row lock, so a stale dialog gets a conflict rather than a surprise.
 */
function OrderModal({ orderId }: { orderId: string }) {
  const { moveOrder, ordersBusy } = useAdminData();
  const [order, setOrder] = useState<AdminOrderDetail | null>(null);
  const [loadError, setLoadError] = useState('');
  const [askingReason, setAskingReason] = useState(false);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState('');

  useEffect(() => {
    let current = true;

    void loadOrderDetail(orderId)
      .then((detail) => {
        if (current) {
          setOrder(detail);
          setLoadError('');
        }
      })
      .catch((error: unknown) => {
        if (current) {
          setLoadError(error instanceof Error ? error.message : 'Không tải được đơn hàng.');
        }
      });

    return () => {
      current = false;
    };
  }, [orderId]);

  if (loadError !== '') {
    return (
      <ModalFrame title="Đơn hàng">
        <p role="alert">{loadError}</p>
      </ModalFrame>
    );
  }

  if (order === null) {
    return (
      <ModalFrame title="Đơn hàng">
        <p>Đang tải chi tiết đơn...</p>
      </ModalFrame>
    );
  }

  const next = forwardStatus(order.status);

  // The order is passed in rather than read from the closure: TypeScript does
  // not carry the null check above into a function declaration, and an
  // assertion here would be a worse answer than an argument.
  function cancelOrder(target: AdminOrderDetail) {
    // The same rule the server enforces: a cancellation is the move a customer
    // will ask about, so it has to say why.
    if (reason.trim() === '') {
      setReasonError('Vui lòng nhập lý do hủy đơn.');

      return;
    }

    moveOrder(target.id, 'CANCELLED', reason.trim());
  }

  return (
    <ModalFrame
      title={`Đơn hàng ${order.code}`}
      actions={
        <>
          {canCancel(order.status) && !askingReason ? (
            <button
              type="button"
              className="btn btn-danger"
              onClick={() => setAskingReason(true)}
              disabled={ordersBusy}
            >
              Hủy đơn
            </button>
          ) : null}
          {askingReason ? (
            <button
              type="button"
              className="btn btn-danger"
              onClick={() => cancelOrder(order)}
              disabled={ordersBusy}
            >
              Xác nhận hủy
            </button>
          ) : null}
          {next === undefined ? null : (
            <button
              type="button"
              className="btn btn-primary"
              onClick={() => moveOrder(order.id, next)}
              disabled={ordersBusy}
            >
              Chuyển sang: {ORDER_STATUS_LABELS[next]}
            </button>
          )}
        </>
      }
    >
      <div className="order-detail">
        <div className="order-detail-row">
          <StatusBadge status={order.status} />
          <span>{FULFILLMENT_LABELS[order.fulfillment]}</span>
          <span>Giờ nhận {timeLabel(order.scheduledAt ?? order.placedAt ?? order.createdAt)}</span>
        </div>

        <dl className="order-facts">
          <dt>Khách</dt>
          <dd>
            {order.contactName} · {order.contactPhone}
          </dd>
          {order.deliveryAddress === null ? null : (
            <>
              <dt>Giao tới</dt>
              <dd>{order.deliveryAddress}</dd>
            </>
          )}
          <dt>Thanh toán</dt>
          <dd>
            {order.paymentMethod === null
              ? 'Chưa ghi nhận thanh toán'
              : `${PAYMENT_METHOD_LABELS[order.paymentMethod]} · ${
                  order.paymentStatus === null
                    ? 'không rõ'
                    : PAYMENT_STATUS_LABELS[order.paymentStatus]
                }`}
            {order.paidAt === null ? '' : ` · đã thu ${dateTimeLabel(order.paidAt)}`}
            {order.paymentStatus === 'PENDING' && order.paymentExpiresAt !== null
              ? ` · hết hạn ${dateTimeLabel(order.paymentExpiresAt)}`
              : ''}
          </dd>
          {order.note === null ? null : (
            <>
              <dt>Ghi chú</dt>
              <dd>{order.note}</dd>
            </>
          )}
        </dl>

        <table className="order-lines">
          <tbody>
            {order.lines.map((line) => (
              <tr key={line.id}>
                <td>
                  {line.quantity} × {line.name}
                  {line.note === null ? null : <em> ({line.note})</em>}
                </td>
                <td>{money(line.lineTotal)}</td>
              </tr>
            ))}
            {order.discount === 0 ? null : (
              <tr>
                <td>Giảm giá</td>
                <td>-{money(order.discount)}</td>
              </tr>
            )}
            {order.deliveryFee === 0 ? null : (
              <tr>
                <td>Phí giao hàng</td>
                <td>{money(order.deliveryFee)}</td>
              </tr>
            )}
            <tr className="order-total">
              <td>Tổng tiền</td>
              <td>{money(order.total)}</td>
            </tr>
          </tbody>
        </table>

        {order.status === 'PENDING_PAYMENT' ? (
          <p className="notice">
            Đơn chờ chuyển khoản chỉ vào hàng đợi khi nhân viên xác nhận đã nhận được tiền thật ở
            màn hình Thanh toán. Ảnh chụp hay thao tác “tôi đã chuyển” không phải bằng chứng.
          </p>
        ) : null}

        {askingReason ? (
          <Field label="Lý do hủy đơn" error={reasonError === '' ? undefined : reasonError}>
            <input
              autoFocus
              maxLength={255}
              placeholder="VD: Khách đổi ý"
              value={reason}
              onChange={(event) => {
                setReason(event.target.value);
                setReasonError('');
              }}
            />
          </Field>
        ) : null}

        {order.history.length === 0 ? null : (
          <ol className="order-timeline">
            {order.history.map((event, index) => (
              <li key={`${event.to}-${index}`}>
                <b>{ORDER_STATUS_LABELS[event.to]}</b>
                <span>
                  {dateTimeLabel(event.at)}
                  {/* No actor means the system moved it - an expiry job, say. */}
                  {event.actorName === null ? ' · hệ thống' : ` · ${event.actorName}`}
                </span>
                {event.reason === null ? null : <em>{event.reason}</em>}
              </li>
            ))}
          </ol>
        )}
      </div>
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
function ProductFormModal({ productId }: { readonly productId?: string }) {
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

      <ProductImageField values={values} errors={errors} set={set} />

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

function DeleteProductModal({ productId }: { readonly productId: string }) {
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

function StockModal({ productId }: { productId: string }) {
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

/**
 * The morning prep sheet: how many portions of each dish were made today.
 *
 * Every box starts EMPTY, showing today's prepared count as a placeholder, and
 * a dish left blank is not touched. Prefilling them would make "open the
 * dialog and save" reset what is left on every dish on the menu - including
 * ones half sold at 09:00 - which is a quiet way to promise food that is gone.
 *
 * A number here sets both of a dish's counts, because at the start of service
 * what was prepared and what is left are the same thing. Selling eight bánh mì
 * is the per-dish "Điều chỉnh" dialog instead.
 */
function DailyPortionsModal() {
  const { products, savePortions, menuBusy } = useAdminData();
  const [values, setValues] = useState<Record<string, string>>({});
  const [error, setError] = useState('');

  function submit() {
    const lines: PreparedPortions[] = [];

    for (const product of products) {
      const typed = (values[product.id] ?? '').trim();

      if (typed === '') {
        continue;
      }

      // Digits only, not Number(): Number('') is 0 and Number('4e2') is 400,
      // so either would save a quantity nobody typed (see product-form.ts).
      if (!/^\d+$/.test(typed)) {
        setError(`"${product.name}": số suất phải là số nguyên, không âm.`);

        return;
      }

      const prepared = Number(typed);

      if (prepared > MAX_PORTIONS) {
        setError(`"${product.name}": số suất chuẩn bị tối đa ${MAX_PORTIONS}.`);

        return;
      }

      lines.push({ productId: product.id, prepared });
    }

    if (lines.length === 0) {
      setError('Nhập số suất cho ít nhất một món.');

      return;
    }

    savePortions(lines);
  }

  return (
    <ModalFrame
      title="Số suất chuẩn bị hôm nay"
      onSubmit={submit}
      actions={
        <button type="submit" className="btn btn-primary" disabled={menuBusy}>
          Lưu bảng chuẩn bị
        </button>
      }
    >
      {products.length === 0 ? (
        <p>Thực đơn chưa có món nào. Thêm món ở màn hình Thực đơn trước.</p>
      ) : (
        <>
          <p>
            Nhập số suất đã chuẩn bị cho hôm nay. Bỏ trống là giữ nguyên, nhập 0 là báo hết món.
          </p>
          <div className="portion-sheet">
            {products.map((product) => (
              <label className="portion-row" key={product.id}>
                <span className="portion-name">
                  <FoodIcon name={product.icon} size={15} /> {product.name}
                </span>
                <em className="portion-now">
                  còn {product.stock}/{product.capacity}
                </em>
                <input
                  type="number"
                  min={0}
                  max={MAX_PORTIONS}
                  inputMode="numeric"
                  placeholder={String(product.capacity)}
                  aria-label={`Số suất chuẩn bị cho ${product.name}`}
                  value={values[product.id] ?? ''}
                  onChange={(event) => {
                    const typed = event.target.value;
                    setValues((current) => ({ ...current, [product.id]: typed }));
                    setError('');
                  }}
                />
              </label>
            ))}
          </div>
          {error === '' ? null : (
            <em className="field-error" role="alert">
              {error}
            </em>
          )}
          <p className="notice">
            Cả bảng được lưu trong một lần: hoặc tất cả các món được ghi nhận, hoặc không món nào -
            máy chủ áp dụng trong cùng một transaction.
          </p>
        </>
      )}
    </ModalFrame>
  );
}

/**
 * Create and edit are the same dialog: `couponId` absent means create.
 *
 * The code field is read-only on an edit, and says why. A code is already on
 * a sign by the door and in customers' messages; the server refuses a rename
 * for that reason, and a form that let one be typed would only produce a 422
 * the operator could not act on.
 *
 * The client checks shape - a number is a number, a percentage is 1..100 -
 * and the server checks the rest. It owns the rules that cost money, such as
 * a fixed discount larger than the order it requires, and it is the authority
 * on all of them.
 */
function CouponFormModal({ couponId }: { readonly couponId?: string }) {
  const { coupons, createCoupon, updateCoupon, couponsBusy } = useAdminData();
  const editing = coupons.find((candidate) => candidate.id === couponId);

  const [values, setValues] = useState<CouponFormValues>(() =>
    editing === undefined ? EMPTY_COUPON_FORM : couponFormValues(editing),
  );
  const [errors, setErrors] = useState<CouponFieldErrors>({});

  // An id that matches nothing: the code was removed under the open dialog.
  if (couponId !== undefined && editing === undefined) {
    return null;
  }

  function set<K extends keyof CouponFormValues>(key: K, value: CouponFormValues[K]) {
    setValues((current) => ({ ...current, [key]: value }));
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
    const result = validateCouponForm(values);

    if (!result.ok) {
      setErrors(result.errors);

      return;
    }

    if (couponId === undefined) {
      createCoupon(result.draft);
    } else {
      updateCoupon(couponId, result.draft);
    }
  }

  const isPercentage = values.discountType === 'PERCENTAGE';

  return (
    <ModalFrame
      title={couponId === undefined ? 'Tạo mã ưu đãi' : `Mã ${values.code}`}
      onSubmit={submit}
      actions={
        <button type="submit" className="btn btn-primary" disabled={couponsBusy}>
          {couponId === undefined ? 'Tạo mã' : 'Lưu thay đổi'}
        </button>
      }
    >
      {/*
       * The fields scroll, the buttons do not: this form is taller than a
       * laptop viewport, and a save button below the fold is a save button
       * nobody can press.
       */}
      <div className="form-scroll">
        <Field
          label="Mã ưu đãi"
          error={errors.code}
          hint={
            couponId === undefined
              ? 'Chữ in hoa, số, gạch ngang hoặc gạch dưới. Khách sẽ đọc mã này.'
              : 'Mã đã phát ra ngoài nên không đổi được. Muốn đổi thì lưu trữ mã này và tạo mã mới.'
          }
        >
          <input
            autoFocus={couponId === undefined}
            readOnly={couponId !== undefined}
            maxLength={MAX_COUPON_CODE_LENGTH}
            placeholder="VD: SANGMOI"
            aria-invalid={errors.code !== undefined}
            value={values.code}
            onChange={(event) => set('code', event.target.value.toUpperCase())}
          />
        </Field>

        <Field label="Mô tả ngắn" error={errors.description}>
          <input
            maxLength={MAX_COUPON_DESCRIPTION_LENGTH}
            placeholder="VD: Giảm 10% cho đơn sáng"
            value={values.description}
            onChange={(event) => set('description', event.target.value)}
          />
        </Field>

        <Field label="Kiểu giảm">
          <select
            value={values.discountType}
            onChange={(event) =>
              set(
                'discountType',
                // Narrowed through the known list rather than cast.
                DISCOUNT_TYPES.find((type) => type === event.target.value) ?? values.discountType,
              )
            }
          >
            {DISCOUNT_TYPES.map((type) => (
              <option key={type} value={type}>
                {DISCOUNT_TYPE_LABELS[type]}
              </option>
            ))}
          </select>
        </Field>

        <div className="form-row">
          <Field
            label={isPercentage ? 'Phần trăm giảm (%)' : 'Số tiền giảm (VND)'}
            error={errors.discountValue}
          >
            <input
              type="number"
              min={1}
              max={isPercentage ? 100 : undefined}
              placeholder={isPercentage ? '10' : '5000'}
              aria-invalid={errors.discountValue !== undefined}
              value={values.discountValue}
              onChange={(event) => set('discountValue', event.target.value)}
            />
          </Field>
          {isPercentage ? (
            <Field
              label="Giảm tối đa (VND)"
              error={errors.maxDiscount}
              hint="Để trống là không giới hạn."
            >
              <input
                type="number"
                min={1}
                placeholder="20000"
                aria-invalid={errors.maxDiscount !== undefined}
                value={values.maxDiscount}
                onChange={(event) => set('maxDiscount', event.target.value)}
              />
            </Field>
          ) : null}
        </div>

        <div className="form-row">
          <Field
            label="Đơn tối thiểu (VND)"
            error={errors.minOrder}
            hint="Để trống là không yêu cầu."
          >
            <input
              type="number"
              min={0}
              placeholder="50000"
              aria-invalid={errors.minOrder !== undefined}
              value={values.minOrder}
              onChange={(event) => set('minOrder', event.target.value)}
            />
          </Field>
          <Field label="Giới hạn lượt" error={errors.usageLimit} hint="Để trống là không giới hạn.">
            <input
              type="number"
              min={1}
              placeholder="100"
              aria-invalid={errors.usageLimit !== undefined}
              value={values.usageLimit}
              onChange={(event) => set('usageLimit', event.target.value)}
            />
          </Field>
        </div>

        <div className="form-row">
          <Field label="Bắt đầu" error={errors.startsOn} hint="Để trống là chạy ngay.">
            <input
              type="date"
              value={values.startsOn}
              onChange={(event) => set('startsOn', event.target.value)}
            />
          </Field>
          <Field label="Kết thúc" error={errors.endsOn} hint="Ngày cuối cùng còn dùng được.">
            <input
              type="date"
              value={values.endsOn}
              onChange={(event) => set('endsOn', event.target.value)}
            />
          </Field>
        </div>

        <Field label="Trạng thái">
          <select
            value={values.status}
            onChange={(event) =>
              set(
                'status',
                COUPON_STATUSES.find((status) => status === event.target.value) ?? values.status,
              )
            }
          >
            {COUPON_STATUSES.map((status) => (
              <option key={status} value={status}>
                {COUPON_STATUS_LABELS[status]}
              </option>
            ))}
          </select>
        </Field>

        {editing === undefined ? null : (
          <p className="notice">
            Đã dùng {describeUsage(editing)}. Số lượt là ghi nhận của hệ thống, không sửa tay được -
            và sẽ chỉ tăng khi khách đặt hàng kèm mã, tức là khi có luồng thanh toán của khách.
          </p>
        )}
      </div>
    </ModalFrame>
  );
}

/** Field-level messages, the same shape the coupon and product forms use. */
interface RewardFieldErrors {
  name?: string;
  pointsCost?: string;
}

function RewardFormModal({ rewardId }: { readonly rewardId?: string }) {
  const { loyaltyRewards, loyaltyRewardsBusy, createLoyaltyReward, updateLoyaltyReward } =
    useAdminData();
  const editing = loyaltyRewards.find((candidate) => candidate.id === rewardId);

  const [name, setName] = useState(editing?.name ?? '');
  const [pointsCost, setPointsCost] = useState(
    editing === undefined ? '' : String(editing.pointsCost),
  );
  const [isActive, setIsActive] = useState(editing?.isActive ?? true);
  const [errors, setErrors] = useState<RewardFieldErrors>({});

  // An id that matches nothing: the reward was removed under the open dialog.
  if (rewardId !== undefined && editing === undefined) {
    return null;
  }

  function submit() {
    const trimmedName = name.trim();
    const parsedPoints = Number(pointsCost);
    const nextErrors: RewardFieldErrors = {};

    if (trimmedName === '') {
      nextErrors.name = 'Vui lòng nhập tên quà tặng.';
    }

    if (!Number.isInteger(parsedPoints) || parsedPoints < 1) {
      nextErrors.pointsCost = 'Số điểm đổi phải là số nguyên, lớn hơn 0.';
    }

    if (Object.keys(nextErrors).length > 0) {
      setErrors(nextErrors);

      return;
    }

    const draft = {
      name: trimmedName,
      pointsCost: parsedPoints,
      icon: editing?.icon ?? 'gift',
      isActive,
    };

    if (rewardId === undefined) {
      createLoyaltyReward(draft);
    } else {
      updateLoyaltyReward(rewardId, draft);
    }
  }

  return (
    <ModalFrame
      title={rewardId === undefined ? 'Thêm quà đổi điểm' : `Quà ${editing?.name ?? ''}`}
      onSubmit={submit}
      actions={
        <button type="submit" className="btn btn-primary" disabled={loyaltyRewardsBusy}>
          {rewardId === undefined ? 'Thêm quà' : 'Lưu thay đổi'}
        </button>
      }
    >
      <Field label="Tên món đổi thưởng" error={errors.name}>
        <input
          autoFocus={rewardId === undefined}
          maxLength={160}
          placeholder="VD: Trà tắc"
          aria-invalid={errors.name !== undefined}
          value={name}
          onChange={(event) => setName(event.target.value)}
        />
      </Field>
      <Field label="Số điểm cần đổi" error={errors.pointsCost}>
        <input
          type="number"
          min={1}
          placeholder="100"
          aria-invalid={errors.pointsCost !== undefined}
          value={pointsCost}
          onChange={(event) => setPointsCost(event.target.value)}
        />
      </Field>
      {editing === undefined ? null : (
        <>
          <label className="form-field form-field-checkbox">
            <input
              type="checkbox"
              checked={isActive}
              onChange={(event) => setIsActive(event.target.checked)}
            />
            <span>Đang áp dụng (bỏ chọn để ngừng, không xóa lịch sử đã đổi)</span>
          </label>
          <p className="notice">
            Đã đổi {editing.redeemedCount} lần. Số lượt là ghi nhận của hệ thống, không sửa tay
            được.
          </p>
        </>
      )}
    </ModalFrame>
  );
}

function LoyaltySettingsModal() {
  const { loyaltySettings, updateLoyaltySettings } = useAdminData();
  const [pointsPerCurrency, setPointsPerCurrency] = useState(
    loyaltySettings === null ? '10000' : String(loyaltySettings.pointsPerCurrency),
  );
  const [threshold, setThreshold] = useState(
    loyaltySettings === null ? '100' : String(loyaltySettings.loyalTierThresholdPoints),
  );
  const [errors, setErrors] = useState<{ rate?: string; threshold?: string }>({});

  if (loyaltySettings === null) {
    return null;
  }

  function save() {
    const rate = Number(pointsPerCurrency);
    const thresholdValue = Number(threshold);
    const nextErrors: { rate?: string; threshold?: string } = {};

    if (!Number.isInteger(rate) || rate < 1) {
      nextErrors.rate = 'Số tiền để nhận 1 điểm phải là số nguyên, lớn hơn 0.';
    }

    if (!Number.isInteger(thresholdValue) || thresholdValue < 0) {
      nextErrors.threshold = 'Ngưỡng hạng thân thiết không được âm.';
    }

    if (Object.keys(nextErrors).length > 0) {
      setErrors(nextErrors);

      return;
    }

    updateLoyaltySettings({ pointsPerCurrency: rate, loyalTierThresholdPoints: thresholdValue });
  }

  return (
    <ModalFrame
      title="Cấu hình tích điểm"
      onSubmit={save}
      actions={
        <button type="submit" className="btn btn-primary">
          Lưu cấu hình
        </button>
      }
    >
      <p>Điểm được cộng tự động cho mỗi đơn đã thanh toán, qua một tiến trình chạy mỗi phút.</p>
      <Field label="Số tiền để nhận 1 điểm (VND)" error={errors.rate}>
        <input
          type="number"
          min={1}
          step={1000}
          value={pointsPerCurrency}
          onChange={(event) => setPointsPerCurrency(event.target.value)}
        />
      </Field>
      <Field
        label="Ngưỡng điểm lũy kế để lên hạng Thân thiết"
        error={errors.threshold}
        hint="Dựa trên điểm lũy kế đã nhận, đổi quà không làm giảm hạng."
      >
        <input
          type="number"
          min={0}
          value={threshold}
          onChange={(event) => setThreshold(event.target.value)}
        />
      </Field>
    </ModalFrame>
  );
}

function LoyaltyMemberModal({ phone, name }: { readonly phone: string; readonly name: string }) {
  const {
    loyaltyRewards,
    loyaltyMemberDetail,
    loyaltyMemberDetailBusy,
    loadLoyaltyMemberDetail,
    redeemLoyaltyReward,
  } = useAdminData();
  const activeRewards = loyaltyRewards.filter((reward) => reward.isActive);
  const [rewardId, setRewardId] = useState(activeRewards[0]?.id ?? '');
  const [note, setNote] = useState('');

  useEffect(() => {
    loadLoyaltyMemberDetail(phone);
    // Deliberately keyed on `phone` alone: `loadLoyaltyMemberDetail` is a
    // stable callback, and a redeem already refreshes the detail itself.
  }, [phone, loadLoyaltyMemberDetail]);

  useEffect(() => {
    if (rewardId === '' && activeRewards[0] !== undefined) {
      setRewardId(activeRewards[0].id);
    }
  }, [activeRewards, rewardId]);

  const selected = activeRewards.find((reward) => reward.id === rewardId);
  const canRedeem =
    selected !== undefined &&
    loyaltyMemberDetail !== null &&
    loyaltyMemberDetail.balance >= selected.pointsCost;

  return (
    <ModalFrame title={`${name} · ${phone}`}>
      {loyaltyMemberDetail === null ? (
        <p>{loyaltyMemberDetailBusy ? 'Đang tải...' : 'Không tải được dữ liệu khách.'}</p>
      ) : (
        <>
          <div className="metrics">
            <Metric
              title="Điểm lũy kế"
              value={loyaltyMemberDetail.lifetimeEarnedPoints}
              icon={<IconStar size={16} />}
              color="#fff3d9"
              foot="Không giảm khi đổi quà"
            />
            <Metric
              title="Điểm hiện có"
              value={loyaltyMemberDetail.balance}
              icon={<IconGift size={16} />}
              color="#e9f8f0"
              foot="Có thể dùng để đổi quà"
            />
          </div>

          {activeRewards.length === 0 ? (
            <p className="notice">Chưa có quà nào đang áp dụng để đổi.</p>
          ) : (
            <div className="form-row">
              <Field label="Đổi quà">
                <select value={rewardId} onChange={(event) => setRewardId(event.target.value)}>
                  {activeRewards.map((reward) => (
                    <option key={reward.id} value={reward.id}>
                      {reward.name} — {reward.pointsCost} điểm
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Ghi chú (tùy chọn)">
                <input
                  maxLength={500}
                  placeholder="VD: Mang đi"
                  value={note}
                  onChange={(event) => setNote(event.target.value)}
                />
              </Field>
            </div>
          )}

          {activeRewards.length === 0 ? null : (
            <div className="modal-actions" style={{ justifyContent: 'flex-start', paddingTop: 0 }}>
              <button
                type="button"
                className="btn btn-primary"
                disabled={!canRedeem}
                onClick={() => {
                  if (selected === undefined) {
                    return;
                  }
                  redeemLoyaltyReward(phone, selected.id, note.trim() === '' ? null : note.trim());
                  setNote('');
                }}
              >
                Xác nhận đổi quà
              </button>
              {selected !== undefined && !canRedeem ? (
                <span className="field-error" role="alert">
                  Khách chỉ còn {loyaltyMemberDetail.balance} điểm, không đủ {selected.pointsCost}{' '}
                  điểm.
                </span>
              ) : null}
            </div>
          )}

          <div className="panel-title">
            <h3>Lịch sử điểm</h3>
          </div>
          {loyaltyMemberDetail.history.length === 0 ? (
            <p className="notice">Chưa có lịch sử nào.</p>
          ) : (
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>THỜI GIAN</th>
                    <th>LOẠI</th>
                    <th>ĐIỂM</th>
                    <th>CHI TIẾT</th>
                  </tr>
                </thead>
                <tbody>
                  {loyaltyMemberDetail.history.map((entry) => (
                    <tr key={entry.id}>
                      <td>{new Date(entry.createdAt).toLocaleString('vi-VN')}</td>
                      <td>
                        <span className={`status ${entry.type === 'EARN' ? 'ready' : 'preparing'}`}>
                          {entry.type === 'EARN' ? 'Tích điểm' : 'Đổi quà'}
                        </span>
                      </td>
                      <td>{entry.points > 0 ? `+${entry.points}` : entry.points}</td>
                      <td>
                        {entry.type === 'EARN'
                          ? 'Đơn đã thanh toán'
                          : `${entry.rewardName ?? 'Quà đã xóa'}${
                              entry.actorName === null ? '' : ` · ${entry.actorName}`
                            }${entry.note === null ? '' : ` · ${entry.note}`}`}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
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
      {modal.kind === 'daily-portions' ? <DailyPortionsModal /> : null}
      {modal.kind === 'coupon-form' ? (
        <CouponFormModal key={modal.couponId ?? 'new'} couponId={modal.couponId} />
      ) : null}
      {modal.kind === 'reward-form' ? (
        <RewardFormModal key={modal.rewardId ?? 'new'} rewardId={modal.rewardId} />
      ) : null}
      {modal.kind === 'loyalty-settings' ? <LoyaltySettingsModal /> : null}
      {modal.kind === 'loyalty-member' ? (
        <LoyaltyMemberModal key={modal.phone} phone={modal.phone} name={modal.name} />
      ) : null}
      {modal.kind === 'add-product' ? <ProductFormModal /> : null}
    </div>
  );
}
