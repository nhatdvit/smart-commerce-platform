'use client';

import { useEffect, useState, type ReactNode } from 'react';

import { mediaUrl } from '@/lib/api-client';
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
import { FoodIcon } from './icons';
import { StatusBadge } from './primitives';

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
      {modal.kind === 'daily-portions' ? <DailyPortionsModal /> : null}
      {modal.kind === 'add-coupon' ? <AddCouponModal /> : null}
      {modal.kind === 'add-reward' ? <AddRewardModal /> : null}
      {modal.kind === 'loyalty-settings' ? <LoyaltySettingsModal /> : null}
      {modal.kind === 'coupon-detail' ? <CouponDetailModal code={modal.code} /> : null}
      {modal.kind === 'add-product' ? <ProductFormModal /> : null}
    </div>
  );
}
