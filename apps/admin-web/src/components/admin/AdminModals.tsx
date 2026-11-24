'use client';

import { useEffect, useRef, useState, type ReactNode } from 'react';
import QRCode from 'qrcode';

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
  DISCREPANCY_TYPES,
  DISCREPANCY_TYPE_LABELS,
  OVERPAYMENT_DISPOSITIONS,
  PAYMENT_METHOD_LABELS as SETTLEMENT_METHOD_LABELS,
  PAYMENT_STATUS_LABELS as SETTLEMENT_STATUS_LABELS,
  loadPayment,
  loadPaymentReceiptUrl,
  paymentStatusTone,
  regeneratePaymentQr,
  type AdminPayment,
  type DiscrepancyType,
  type KnownBankOption,
  type OverpaymentDisposition,
} from '@/lib/payments-api';
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
import { FoodIcon, IconGift, IconReceipt, IconStar, IconWallet } from './icons';
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

/** True once the order's payment is settled - nothing left to collect here. */
function isSettledPayment(status: AdminOrderDetail['paymentStatus']): boolean {
  return status === 'PAID' || status === 'PARTIALLY_REFUNDED' || status === 'REFUNDED';
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
 *
 * Its own frame rather than `ModalFrame`: this is the one dialog wide enough
 * to need a two-column layout, and the only one with a close button in the
 * header instead of just the footer.
 */
function OrderModal({ orderId }: { orderId: string }) {
  const { moveOrder, ordersBusy, closeModal } = useAdminData();
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

  if (loadError !== '' || order === null) {
    return (
      <div className="modal order-detail-modal">
        <div className="modal-head">
          <div>
            <div className="eyebrow">ĐƠN HÀNG</div>
            <h2>{loadError === '' ? 'Đang tải...' : 'Không tải được đơn hàng'}</h2>
          </div>
          <button type="button" className="icon-btn" aria-label="Đóng" onClick={closeModal}>
            ✕
          </button>
        </div>
        <div className="modal-content">
          <p role={loadError === '' ? undefined : 'alert'}>
            {loadError === '' ? 'Đang tải chi tiết đơn...' : loadError}
          </p>
        </div>
      </div>
    );
  }

  const next = forwardStatus(order.status);
  // No money was ever collected, so there is nothing a refund policy needs
  // to look at - a real anomaly (a payment row with no method at all) reads
  // the same way, honestly, rather than as a settled transaction.
  const cancelledWithNothingOwed =
    order.status === 'CANCELLED' &&
    (order.paymentStatus === null ||
      order.paymentStatus === 'PENDING' ||
      order.paymentStatus === 'FAILED');

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
    <div className="modal order-detail-modal">
      <div className="modal-head">
        <div>
          <div className="eyebrow">ĐẶT LÚC {timeLabel(order.placedAt ?? order.createdAt)}</div>
          <h2>Đơn hàng {order.code}</h2>
        </div>
        <button type="button" className="icon-btn" aria-label="Đóng" onClick={closeModal}>
          ✕
        </button>
      </div>

      <div className="order-detail-layout">
        <div className="order-detail-main">
          <div className="order-customer-bar">
            <div className="order-person">
              <span className="detail-round-icon" aria-hidden="true">
                ♙
              </span>
              <div>
                <strong>{order.contactName}</strong>
                <a href={`tel:${order.contactPhone.replace(/\s/g, '')}`}>{order.contactPhone}</a>
              </div>
            </div>
            <div className="order-pickup">
              <span className="detail-round-icon" aria-hidden="true">
                ▣
              </span>
              <div>
                <small>Nhận hàng</small>
                <strong>
                  {timeLabel(order.scheduledAt ?? order.placedAt ?? order.createdAt)} ·{' '}
                  {FULFILLMENT_LABELS[order.fulfillment]}
                </strong>
              </div>
            </div>
          </div>

          {order.deliveryAddress === null ? null : (
            <p className="order-delivery-address">Giao tới: {order.deliveryAddress}</p>
          )}

          <div className="order-status-pair">
            <div className="order-status-card order-status-blue">
              <span className="detail-status-icon" aria-hidden="true">
                ⬡
              </span>
              <div>
                <small>Trạng thái đơn</small>
                <StatusBadge status={order.status} />
              </div>
            </div>
            <div className="order-status-card order-status-gold">
              <span className="detail-status-icon" aria-hidden="true">
                ▤
              </span>
              <div>
                <small>Thanh toán</small>
                {order.paymentMethod === null ? (
                  <span className="status waiting">Chưa ghi nhận</span>
                ) : (
                  <span
                    className={
                      isSettledPayment(order.paymentStatus) ? 'status done' : 'status waiting'
                    }
                  >
                    {order.paymentStatus === null
                      ? 'Không rõ'
                      : PAYMENT_STATUS_LABELS[order.paymentStatus]}
                  </span>
                )}
                <span className="order-payment-method">
                  {order.paymentMethod === null ? '—' : PAYMENT_METHOD_LABELS[order.paymentMethod]}
                  {order.paidAt === null ? '' : ` · đã thu ${dateTimeLabel(order.paidAt)}`}
                  {order.paymentStatus === 'PENDING' && order.paymentExpiresAt !== null
                    ? ` · hết hạn ${dateTimeLabel(order.paymentExpiresAt)}`
                    : ''}
                </span>
              </div>
            </div>
          </div>

          <div className="detail-section-heading">
            <span aria-hidden="true">♧</span>
            <h3>Sản phẩm đã đặt</h3>
          </div>
          <div className="detail-items">
            {order.lines.map((line) => (
              <div className="detail-product" key={line.id}>
                <span className="detail-product-thumb" aria-hidden="true">
                  🍽️
                </span>
                <div className="detail-product-name">
                  <strong>{line.name}</strong>
                  <small>
                    {money(line.unitPrice)} / sản phẩm
                    {line.note === null ? '' : ` · ${line.note}`}
                  </small>
                </div>
                <span className="detail-product-qty">× {line.quantity}</span>
                <strong className="detail-product-price">{money(line.lineTotal)}</strong>
              </div>
            ))}
            {order.discount === 0 ? null : (
              <div className="detail-product">
                <div className="detail-product-name">
                  <strong>Giảm giá</strong>
                </div>
                <strong className="detail-product-price">-{money(order.discount)}</strong>
              </div>
            )}
            {order.deliveryFee === 0 ? null : (
              <div className="detail-product">
                <div className="detail-product-name">
                  <strong>Phí giao hàng</strong>
                </div>
                <strong className="detail-product-price">{money(order.deliveryFee)}</strong>
              </div>
            )}
          </div>

          <div className="detail-total">
            <strong>Tổng thanh toán</strong>
            <b>{money(order.total)}</b>
          </div>

          {order.status !== 'PENDING_PAYMENT' ? null : (
            <p className="detail-cancel-note">
              ⓘ Đơn chờ chuyển khoản chỉ vào hàng đợi khi nhân viên xác nhận đã nhận được tiền thật
              ở màn hình Thanh toán. Ảnh chụp hay thao tác "tôi đã chuyển" không phải bằng chứng.
            </p>
          )}

          {!cancelledWithNothingOwed ? null : (
            <p className="detail-cancel-note">
              ⓘ Đơn đã hủy trước khi thu tiền. Không phát sinh công nợ hoặc hoàn tiền.
            </p>
          )}

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
        </div>

        <aside className="order-detail-side">
          <div className="detail-section-heading side-heading">
            <span aria-hidden="true">◈</span>
            <h3>Tiến trình đơn hàng</h3>
          </div>
          {order.history.length === 0 ? (
            <p className="panel-note">Chưa có lịch sử nào được ghi nhận.</p>
          ) : (
            <div className="order-timeline">
              {order.history.map((event, index) => (
                <div
                  className={`order-timeline-step is-done${event.to === 'CANCELLED' || event.to === 'EXPIRED' ? ' is-cancelled' : ''}`}
                  key={`${event.to}-${index}`}
                >
                  <span className="timeline-dot" aria-hidden="true">
                    {event.to === 'CANCELLED' || event.to === 'EXPIRED' ? '×' : '✓'}
                  </span>
                  <div>
                    <strong>{ORDER_STATUS_LABELS[event.to]}</strong>
                    <small>
                      {dateTimeLabel(event.at)}
                      {/* No actor means the system moved it - an expiry job, say. */}
                      {event.actorName === null ? ' · Hệ thống' : ` · ${event.actorName}`}
                    </small>
                    {event.reason === null ? null : <small>{event.reason}</small>}
                  </div>
                </div>
              ))}
            </div>
          )}

          <div className="detail-side-notes">
            <div className="detail-section-heading">
              <span aria-hidden="true">♢</span>
              <h3>Ghi chú</h3>
            </div>
            <p>{order.note ?? 'Không có ghi chú'}</p>
          </div>
        </aside>
      </div>

      <div className="modal-actions">
        <button type="button" className="btn" onClick={closeModal}>
          Đóng
        </button>
        {!canCancel(order.status) || askingReason ? null : (
          <button
            type="button"
            className="btn btn-danger"
            onClick={() => setAskingReason(true)}
            disabled={ordersBusy}
          >
            Hủy đơn
          </button>
        )}
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
      </div>
    </div>
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

/**
 * Generic wording for the read-only history view - the live confirm form
 * phrases "KEEP" as a tip for cash and as kept revenue for a transfer, but a
 * past record just needs one plain label regardless of which it was.
 */
const OVERPAYMENT_DISPOSITION_LABELS: Record<OverpaymentDisposition, string> = {
  KEEP: 'Giữ lại số tiền thừa',
  REFUND: 'Hoàn lại cho khách',
  DEBT: 'Ghi nhận công nợ',
};

/** "20/11/2026 08:40" from an ISO instant, or a dash when there is none. */
function dateTime(iso: string | null): string {
  if (iso === null) {
    return '—';
  }

  const at = new Date(iso);

  return Number.isNaN(at.getTime())
    ? '—'
    : at.toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' });
}

/**
 * The actual scannable VietQR, rendered client-side from the raw EMVCo
 * string the server already validated - never proof of anything itself,
 * just a convenience so staff can show it on a screen without a second app.
 */
function PaymentQrImage({ payload }: { readonly payload: string }) {
  const [dataUrl, setDataUrl] = useState<string | null>(null);

  useEffect(() => {
    let current = true;
    setDataUrl(null);

    void QRCode.toDataURL(payload, { margin: 1, width: 200 })
      .then((url) => {
        if (current) {
          setDataUrl(url);
        }
      })
      .catch(() => {
        // Decorative: a failed render just means no image, not an error the
        // operator needs to see - the reference and masked account below are
        // what they actually verify a transfer against.
      });

    return () => {
      current = false;
    };
  }, [payload]);

  if (dataUrl === null) {
    return null;
  }

  return <img src={dataUrl} alt="Mã QR chuyển khoản" className="payment-qr" />;
}

/**
 * The customer's uploaded receipt photo, fetched once (it is authenticated
 * and store-scoped, never a public URL - see `loadPaymentReceiptUrl`'s own
 * note) and opened full-size in a new tab on request. Never treated as
 * anything but a reference image for staff to look at.
 */
function CustomerReceiptThumb({ paymentId }: { readonly paymentId: string }) {
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let current = true;
    let objectUrl: string | null = null;
    setUrl(null);
    setFailed(false);

    void loadPaymentReceiptUrl(paymentId)
      .then((loaded) => {
        if (current) {
          objectUrl = loaded;
          setUrl(loaded);
        } else {
          URL.revokeObjectURL(loaded);
        }
      })
      .catch(() => {
        if (current) {
          setFailed(true);
        }
      });

    return () => {
      current = false;
      if (objectUrl !== null) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [paymentId]);

  if (failed) {
    return <p className="cell-sub warn">Không tải được ảnh biên lai.</p>;
  }

  if (url === null) {
    return <div className="payment-receipt-thumb is-loading" aria-hidden="true" />;
  }

  return (
    <span className="payment-receipt-preview">
      <img src={url} alt="Ảnh biên lai khách gửi" className="payment-receipt-thumb" />
      <a href={url} target="_blank" rel="noreferrer" className="btn">
        Xem ảnh lớn
      </a>
    </span>
  );
}

/**
 * One payment: its reconciliation facts, then the two things staff can do
 * about it.
 *
 * Confirming is the one way a payment becomes `PAID` (CLAUDE.md section 6):
 * there is deliberately no button here for "customer says they paid", only a
 * form that asks the operator to say what they themselves saw. Flagging never
 * touches the status - a discrepancy is a note on the queue, not a verdict.
 */
function PaymentModal({ paymentId }: { readonly paymentId: string }) {
  const { confirmPayment, flagPayment, paymentsBusy, notify, closeModal } = useAdminData();
  const [payment, setPayment] = useState<AdminPayment | null>(null);
  const [loadError, setLoadError] = useState('');
  const [order, setOrder] = useState<AdminOrderDetail | null>(null);

  const [confirmAmount, setConfirmAmount] = useState('');
  const [bankReference, setBankReference] = useState('');
  const [confirmNote, setConfirmNote] = useState('');
  const [disposition, setDisposition] = useState<OverpaymentDisposition | null>(null);
  const [dispositionError, setDispositionError] = useState('');

  const [flagging, setFlagging] = useState(false);
  const [flagType, setFlagType] = useState<DiscrepancyType>('UNKNOWN_REFERENCE');
  const [flagAmount, setFlagAmount] = useState('');
  const [flagNote, setFlagNote] = useState('');

  const [regenerating, setRegenerating] = useState(false);

  useEffect(() => {
    let current = true;

    void loadPayment(paymentId)
      .then((detail) => {
        if (current) {
          setPayment(detail);
          setConfirmAmount(String(detail.amount));
          setLoadError('');
        }
      })
      .catch((error: unknown) => {
        if (current) {
          setLoadError(error instanceof Error ? error.message : 'Không tải được thanh toán.');
        }
      });

    return () => {
      current = false;
    };
  }, [paymentId]);

  const orderId = payment?.orderId ?? null;

  // The order's own items and phone number are not on the payment itself, and
  // are purely a convenience for whoever is reconciling - a failure here is
  // swallowed rather than shown, because it must never block confirming an
  // actual payment.
  useEffect(() => {
    if (orderId === null) {
      return;
    }
    let current = true;

    void loadOrderDetail(orderId)
      .then((detail) => {
        if (current) {
          setOrder(detail);
        }
      })
      .catch(() => {});

    return () => {
      current = false;
    };
  }, [orderId]);

  if (loadError !== '') {
    return (
      <ModalFrame title="Thanh toán">
        <p role="alert">{loadError}</p>
      </ModalFrame>
    );
  }

  if (payment === null) {
    return (
      <ModalFrame title="Thanh toán">
        <p>Đang tải chi tiết...</p>
      </ModalFrame>
    );
  }

  const settled =
    payment.status === 'PAID' ||
    payment.status === 'PARTIALLY_REFUNDED' ||
    payment.status === 'REFUNDED';

  // Taken as an argument rather than read from the closure: TypeScript does
  // not carry the null check above into a function declaration, the same
  // reasoning as OrderModal's cancelOrder.
  function submitFlag(target: AdminPayment) {
    flagPayment(target.id, {
      type: flagType,
      observedAmount: flagAmount.trim() === '' ? null : Number(flagAmount),
      note: flagNote.trim() === '' ? null : flagNote.trim(),
    });
  }

  // Not routed through the provider's confirm/flag writes: those close the
  // modal on success, and the point here is the opposite - staying open so
  // the fresh QR is immediately visible to show a customer.
  function submitRegenerate(target: AdminPayment) {
    setRegenerating(true);
    void regeneratePaymentQr(target.id)
      .then((updated) => {
        setPayment(updated);
        notify(`Đã tạo mã QR mới cho đơn ${updated.orderCode}`);
      })
      .catch((error: unknown) => {
        notify(error instanceof Error ? error.message : 'Không tạo lại được mã QR.');
      })
      .finally(() => setRegenerating(false));
  }

  const flagSection = (
    <>
      {!flagging ? (
        <button type="button" className="btn" onClick={() => setFlagging(true)}>
          Ghi nhận ngoại lệ khác
        </button>
      ) : (
        <>
          <h3>Ghi nhận ngoại lệ</h3>
          <p className="notice">
            Dùng khi không nên đánh dấu đã thu hoặc thất bại: mã không khớp đơn nào, chuyển khoản
            trùng lặp, hoặc tiền về sau khi đơn đã hủy. Không tự đổi trạng thái thanh toán.
          </p>
          <Field label="Loại ngoại lệ">
            <select
              value={flagType}
              onChange={(event) =>
                setFlagType(
                  DISCREPANCY_TYPES.find((type) => type === event.target.value) ?? flagType,
                )
              }
            >
              {DISCREPANCY_TYPES.map((type) => (
                <option key={type} value={type}>
                  {DISCREPANCY_TYPE_LABELS[type]}
                </option>
              ))}
            </select>
          </Field>
          <div className="form-row">
            <Field label="Số tiền thực tế (VND)" hint="Để trống nếu không áp dụng.">
              <input
                type="number"
                min={0}
                value={flagAmount}
                onChange={(event) => setFlagAmount(event.target.value)}
              />
            </Field>
            <Field label="Ghi chú">
              <input value={flagNote} onChange={(event) => setFlagNote(event.target.value)} />
            </Field>
          </div>
          <button
            type="button"
            className="btn btn-primary"
            disabled={paymentsBusy}
            onClick={() => submitFlag(payment)}
          >
            Ghi nhận ngoại lệ
          </button>
        </>
      )}
    </>
  );

  if (settled) {
    return (
      <ModalFrame title={`Thanh toán đơn ${payment.orderCode}`}>
        <div className="order-detail">
          <div className="order-detail-row">
            <span className={`status ${paymentStatusTone(payment.status)}`}>
              {SETTLEMENT_STATUS_LABELS[payment.status]}
            </span>
            <span>{SETTLEMENT_METHOD_LABELS[payment.method]}</span>
            {payment.needsAttention ? <span className="status waiting">Cần xử lý</span> : null}
          </div>

          <dl className="order-facts">
            <dt>Số tiền cần thu</dt>
            <dd>{money(payment.amount)}</dd>
            {payment.confirmedAmount === null ? null : (
              <>
                <dt>Số tiền đã xác nhận</dt>
                <dd>{money(payment.confirmedAmount)}</dd>
              </>
            )}
            {payment.transferReference === null ? null : (
              <>
                <dt>Mã tham chiếu</dt>
                <dd>{payment.transferReference}</dd>
              </>
            )}
            {payment.bankAccountMasked === null ? null : (
              <>
                <dt>Tài khoản nhận</dt>
                <dd>{payment.bankAccountMasked}</dd>
              </>
            )}
            {payment.bankTransactionReference === null ? null : (
              <>
                <dt>Mã giao dịch ngân hàng</dt>
                <dd>{payment.bankTransactionReference}</dd>
              </>
            )}
            {payment.confirmedBy === null ? null : (
              <>
                <dt>Người xác nhận</dt>
                <dd>
                  {payment.confirmedBy}
                  {payment.confirmedAt === null ? '' : ` · ${dateTime(payment.confirmedAt)}`}
                </dd>
              </>
            )}
          </dl>

          {payment.discrepancies.length === 0 ? null : (
            <>
              <h3>Ngoại lệ đã ghi nhận</h3>
              <ol className="order-timeline">
                {payment.discrepancies.map((discrepancy, index) => (
                  <li key={`${discrepancy.type}-${index}`}>
                    <b>{DISCREPANCY_TYPE_LABELS[discrepancy.type]}</b>
                    <span>
                      {dateTime(discrepancy.recordedAt)}
                      {discrepancy.recordedBy === null ? '' : ` · ${discrepancy.recordedBy}`}
                    </span>
                    {discrepancy.observedAmount === null ? null : (
                      <em>Số tiền thực tế: {money(discrepancy.observedAmount)}</em>
                    )}
                    {discrepancy.disposition === null ? null : (
                      <em>Xử lý: {OVERPAYMENT_DISPOSITION_LABELS[discrepancy.disposition]}</em>
                    )}
                    {discrepancy.note === null ? null : <em>{discrepancy.note}</em>}
                  </li>
                ))}
              </ol>
            </>
          )}

          {flagSection}
        </div>
      </ModalFrame>
    );
  }

  // Taken as an argument rather than read from the closure, same as above.
  function submitConfirm(target: AdminPayment) {
    const amount = Number(confirmAmount);
    if (!Number.isInteger(amount) || amount < 0) {
      return;
    }

    if (amount > target.amount && disposition === null) {
      setDispositionError('Vui lòng chọn cách xử lý số tiền thừa.');

      return;
    }

    confirmPayment(target.id, {
      amount,
      bankTransactionReference: bankReference.trim() === '' ? null : bankReference.trim(),
      acceptDiscrepancy: amount !== target.amount,
      note: confirmNote.trim() === '' ? null : confirmNote.trim(),
      overpaymentDisposition: amount > target.amount ? disposition : null,
    });
  }

  const enteredAmount = confirmAmount.trim() === '' ? null : Number(confirmAmount);
  const hasValidAmount =
    enteredAmount !== null && Number.isInteger(enteredAmount) && enteredAmount >= 0;
  const diff = hasValidAmount ? enteredAmount - payment.amount : 0;
  const isOverpaid = hasValidAmount && diff > 0;
  const isUnderpaid = hasValidAmount && diff < 0;
  const mismatched = isOverpaid || isUnderpaid;

  const dispositionLabels: Record<OverpaymentDisposition, string> =
    payment.method === 'CASH'
      ? {
          KEEP: 'Khách bo thêm (tiền tip)',
          REFUND: 'Trả lại khách',
          DEBT: 'Ghi nhận cần trả khách sau',
        }
      : {
          KEEP: 'Giữ lại số tiền thừa (cộng vào doanh thu)',
          REFUND: 'Hoàn lại cho khách',
          DEBT: 'Ghi nhận công nợ',
        };

  return (
    <div className="modal order-detail-modal payment-confirm-modal" role="dialog" aria-modal="true">
      <div className="modal-head">
        <div className="payment-confirm-heading">
          <span className="payment-confirm-icon" aria-hidden="true">
            <IconWallet size={20} />
          </span>
          <div>
            <h2>Xác nhận thanh toán</h2>
            <p>Đối soát số tiền thực nhận và xử lý chênh lệch (tiền thừa, tiền thiếu, tiền bo).</p>
          </div>
        </div>
        <button type="button" className="icon-btn" aria-label="Đóng" onClick={closeModal}>
          ✕
        </button>
      </div>

      <div className="payment-confirm-layout">
        <div className="payment-info-col">
          <div className="payment-info-box">
            <span className="detail-round-icon" aria-hidden="true">
              ▤
            </span>
            <div>
              <small>Đơn hàng</small>
              <strong>{payment.orderCode}</strong>
            </div>
          </div>
          <div className="payment-info-box">
            <span className="detail-round-icon" aria-hidden="true">
              ♙
            </span>
            <div>
              <small>Khách hàng</small>
              <strong>{payment.customerName}</strong>
              {order === null ? null : (
                <a href={`tel:${order.contactPhone.replace(/\s/g, '')}`}>{order.contactPhone}</a>
              )}
            </div>
          </div>

          {order === null || order.lines.length === 0 ? null : (
            <>
              <div className="detail-section-heading">
                <span aria-hidden="true">♧</span>
                <h3>Món đã đặt ({order.lines.length})</h3>
              </div>
              <div className="detail-items">
                {order.lines.map((line) => (
                  <div className="detail-product" key={line.id}>
                    <span className="detail-product-thumb" aria-hidden="true">
                      🍽️
                    </span>
                    <div className="detail-product-name">
                      <strong>{line.name}</strong>
                    </div>
                    <span className="detail-product-qty">× {line.quantity}</span>
                    <strong className="detail-product-price">{money(line.lineTotal)}</strong>
                  </div>
                ))}
              </div>
            </>
          )}

          <div className="detail-total">
            <strong>Tổng tiền đơn hàng</strong>
            <b>{money(payment.amount)}</b>
          </div>
        </div>

        <div className="payment-form-col">
          <div className="detail-section-heading">
            <span aria-hidden="true">▥</span>
            <h3>Phương thức thanh toán</h3>
          </div>
          <div className="payment-method-pick">
            <div
              className={`payment-method-card${payment.method === 'BANK_TRANSFER' ? ' is-active' : ''}`}
            >
              <IconReceipt size={16} /> Chuyển khoản VietQR
              {payment.method !== 'BANK_TRANSFER' ? null : (
                <span className="method-check" aria-hidden="true">
                  ✓
                </span>
              )}
            </div>
            <div className={`payment-method-card${payment.method === 'CASH' ? ' is-active' : ''}`}>
              <IconWallet size={16} /> Tiền mặt
              {payment.method !== 'CASH' ? null : (
                <span className="method-check" aria-hidden="true">
                  ✓
                </span>
              )}
            </div>
          </div>

          {payment.method !== 'BANK_TRANSFER' || payment.qrPayload === null ? null : (
            <div className="payment-qr-block">
              <PaymentQrImage payload={payment.qrPayload} />
              <button
                type="button"
                className="btn"
                disabled={regenerating}
                onClick={() => submitRegenerate(payment)}
              >
                Tạo lại mã QR
              </button>
            </div>
          )}

          {payment.customerReportedAt === null ? null : (
            <div className="payment-evidence-block">
              <div className="detail-section-heading">
                <span aria-hidden="true">◈</span>
                <h3>Bằng chứng từ khách hàng</h3>
              </div>
              <div className="payment-evidence-row">
                {payment.hasCustomerReceipt ? (
                  <CustomerReceiptThumb paymentId={payment.id} />
                ) : (
                  <p className="cell-sub">Khách chưa gửi ảnh biên lai.</p>
                )}
                <p className="cell-sub">
                  Khách báo đã chuyển khoản lúc {dateTime(payment.customerReportedAt)}
                </p>
              </div>
              <p className="notice">
                Đây chỉ là thông báo từ khách, không phải bằng chứng thanh toán. Vẫn tự kiểm tra
                ngân hàng và nhập đúng số tiền thực nhận bên dưới.
              </p>
            </div>
          )}

          <Field label="Số tiền thực nhận (VND) *">
            <input
              type="number"
              min={0}
              value={confirmAmount}
              onChange={(event) => {
                setConfirmAmount(event.target.value);
                setDisposition(null);
                setDispositionError('');
              }}
            />
          </Field>

          {!hasValidAmount ? null : (
            <div
              className={`payment-reconcile-banner ${mismatched ? (isOverpaid ? 'is-over' : 'is-under') : 'is-match'}`}
              role="status"
            >
              <span aria-hidden="true">{isUnderpaid ? '!' : '✓'}</span>
              <div>
                <strong>
                  {isOverpaid
                    ? `Khách chuyển thừa ${money(diff)}`
                    : isUnderpaid
                      ? `Khách thiếu ${money(-diff)}`
                      : 'Khớp với tổng đơn'}
                </strong>
                <small>
                  Tổng đơn: {money(payment.amount)} · Thực nhận: {money(enteredAmount)}
                </small>
              </div>
            </div>
          )}

          {!isOverpaid ? null : (
            <Field
              label="Xử lý phần chênh lệch *"
              error={dispositionError === '' ? undefined : dispositionError}
            >
              <div className="payment-disposition-list">
                {OVERPAYMENT_DISPOSITIONS.map((option) => (
                  <label
                    key={option}
                    className={`payment-disposition-option${disposition === option ? ' is-selected' : ''}`}
                  >
                    <input
                      type="radio"
                      name="overpayment-disposition"
                      checked={disposition === option}
                      onChange={() => {
                        setDisposition(option);
                        setDispositionError('');
                      }}
                    />
                    {dispositionLabels[option]}
                  </label>
                ))}
              </div>
            </Field>
          )}

          {!isOverpaid || disposition !== 'KEEP' ? null : (
            <div className="payment-settle-summary">
              <div className="summary-row">
                <span>Doanh thu đơn hàng</span>
                <span>{money(payment.amount)}</span>
              </div>
              <div className="summary-row">
                <span>
                  {payment.method === 'CASH' ? 'Tiền tip (khách bo)' : 'Tiền thu thêm (giữ lại)'}
                </span>
                <span>{money(diff)}</span>
              </div>
              <div className="summary-row total">
                <span>Tổng tiền thu</span>
                <span>{money(enteredAmount ?? payment.amount)}</span>
              </div>
            </div>
          )}

          {payment.method !== 'BANK_TRANSFER' ? null : (
            <Field label="Mã giao dịch ngân hàng" hint="Tùy chọn - theo sao kê ngân hàng thật.">
              <input
                maxLength={120}
                value={bankReference}
                onChange={(event) => setBankReference(event.target.value)}
              />
            </Field>
          )}

          <Field label="Ghi chú" hint="Không bắt buộc.">
            <input value={confirmNote} onChange={(event) => setConfirmNote(event.target.value)} />
          </Field>

          {payment.discrepancies.length === 0 ? null : (
            <details className="payment-history-details">
              <summary>Ngoại lệ đã ghi nhận ({payment.discrepancies.length})</summary>
              <ol className="order-timeline">
                {payment.discrepancies.map((discrepancy, index) => (
                  <li key={`${discrepancy.type}-${index}`}>
                    <b>{DISCREPANCY_TYPE_LABELS[discrepancy.type]}</b>
                    <span>
                      {dateTime(discrepancy.recordedAt)}
                      {discrepancy.recordedBy === null ? '' : ` · ${discrepancy.recordedBy}`}
                    </span>
                    {discrepancy.observedAmount === null ? null : (
                      <em>Số tiền thực tế: {money(discrepancy.observedAmount)}</em>
                    )}
                    {discrepancy.note === null ? null : <em>{discrepancy.note}</em>}
                  </li>
                ))}
              </ol>
            </details>
          )}

          {flagSection}
        </div>
      </div>

      <div className="modal-actions">
        <button type="button" className="btn" onClick={closeModal}>
          Đóng
        </button>
        <button
          type="button"
          className="btn btn-primary"
          disabled={paymentsBusy || !hasValidAmount}
          onClick={() => submitConfirm(payment)}
        >
          ✓ Xác nhận đã thanh toán
        </button>
      </div>
    </div>
  );
}

/** Where this store's bank transfers are received, and the VietQR it builds against. */
/**
 * A searchable bank picker: logo and name, filtered as the operator types.
 *
 * A native `<select>` cannot show a logo inside an option in any browser that
 * matters here, and 28+ bank names is enough that scanning a plain list beats
 * typing a BIN only if the list is actually searchable - so this is a small
 * combobox rather than either of those on their own.
 */
function BankPicker({
  banks,
  logos,
  value,
  onChange,
  disabled,
}: {
  readonly banks: readonly KnownBankOption[];
  readonly logos: Readonly<Record<string, string>>;
  readonly value: string;
  readonly onChange: (bin: string) => void;
  readonly disabled?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);
  const selected = banks.find((bank) => bank.bin === value) ?? null;

  // Closes on an outside click, the same as a native select's own dropdown.
  useEffect(() => {
    if (!open) {
      return;
    }

    function onPointerDown(event: MouseEvent) {
      if (containerRef.current !== null && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }

    document.addEventListener('mousedown', onPointerDown);

    return () => document.removeEventListener('mousedown', onPointerDown);
  }, [open]);

  const normalizedQuery = query.trim().toLowerCase();
  const filtered =
    normalizedQuery === ''
      ? banks
      : banks.filter(
          (bank) =>
            bank.name.toLowerCase().includes(normalizedQuery) || bank.bin.includes(normalizedQuery),
        );

  function pick(bank: KnownBankOption) {
    onChange(bank.bin);
    setOpen(false);
    setQuery('');
  }

  return (
    <div className="bank-picker" ref={containerRef}>
      <button
        type="button"
        className="bank-picker-trigger"
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        {selected === null ? (
          <span className="bank-picker-placeholder">— Chọn ngân hàng —</span>
        ) : (
          <>
            {logos[selected.bin] === undefined ? (
              <span className="bank-picker-logo-fallback" aria-hidden="true" />
            ) : (
              <img src={logos[selected.bin]} alt="" className="bank-picker-logo" />
            )}
            <span>{selected.name}</span>
          </>
        )}
        <span className="bank-picker-caret" aria-hidden="true" />
      </button>

      {!open ? null : (
        <div className="bank-picker-panel">
          <input
            autoFocus
            type="text"
            className="bank-picker-search"
            placeholder="Tìm ngân hàng theo tên..."
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
          <ul className="bank-picker-list" role="listbox">
            {filtered.length === 0 ? (
              <li className="bank-picker-empty">Không tìm thấy ngân hàng phù hợp</li>
            ) : (
              filtered.map((bank) => (
                <li key={bank.bin}>
                  <button
                    type="button"
                    role="option"
                    aria-selected={bank.bin === value}
                    className={bank.bin === value ? 'is-selected' : undefined}
                    onClick={() => pick(bank)}
                  >
                    {logos[bank.bin] === undefined ? (
                      <span className="bank-picker-logo-fallback" aria-hidden="true" />
                    ) : (
                      <img src={logos[bank.bin]} alt="" className="bank-picker-logo" />
                    )}
                    <span>{bank.name}</span>
                  </button>
                </li>
              ))
            )}
          </ul>
        </div>
      )}
    </div>
  );
}

function BankAccountFormModal() {
  const {
    bankAccount,
    bankAccountBusy,
    bankAccountError,
    bankAccountLoaded,
    knownBanks,
    bankLogos,
    loadBankAccount,
    loadKnownBanks,
    loadBankLogos,
    saveBankAccount,
  } = useAdminData();
  const [bankBin, setBankBin] = useState('');
  const [accountNumber, setAccountNumber] = useState('');
  const [accountName, setAccountName] = useState('');
  const [touched, setTouched] = useState(false);

  // Asked once when the dialog opens; all three are stable across re-renders
  // (useCallback in the provider), so this never re-fires on its own updates.
  useEffect(() => {
    loadBankAccount();
    loadKnownBanks();
    loadBankLogos();
  }, [loadBankAccount, loadKnownBanks, loadBankLogos]);

  useEffect(() => {
    if (bankAccount !== null && !touched) {
      setBankBin(bankAccount.bankBin);
    }
  }, [bankAccount, touched]);

  return (
    <ModalFrame
      title="Tài khoản nhận chuyển khoản"
      actions={
        <button
          type="button"
          className="btn btn-primary"
          disabled={
            bankAccountBusy ||
            bankBin.trim() === '' ||
            accountNumber.trim() === '' ||
            accountName.trim() === ''
          }
          onClick={() =>
            saveBankAccount({
              bankBin: bankBin.trim(),
              accountNumber: accountNumber.trim(),
              accountName: accountName.trim(),
            })
          }
        >
          Lưu tài khoản
        </button>
      }
    >
      {!bankAccountLoaded ? (
        <p>Đang tải...</p>
      ) : (
        <>
          {bankAccount === null ? (
            <p className="notice">Cửa hàng chưa cấu hình tài khoản nhận chuyển khoản.</p>
          ) : (
            <p className="notice">
              Hiện tại: {bankAccount.bankName} · {bankAccount.accountNumberMasked} ·{' '}
              {bankAccount.accountName}
            </p>
          )}
          {bankAccountError === '' ? null : <p role="alert">{bankAccountError}</p>}
          <Field
            label="Ngân hàng"
            hint={
              knownBanks.length === 0
                ? 'Đang tải danh sách ngân hàng...'
                : 'Theo danh sách NAPAS/VietQR. Không thấy ngân hàng cần tìm nghĩa là chưa hỗ trợ.'
            }
          >
            <BankPicker
              banks={knownBanks}
              logos={bankLogos}
              value={bankBin}
              disabled={knownBanks.length === 0}
              onChange={(bin) => {
                setTouched(true);
                setBankBin(bin);
              }}
            />
          </Field>
          <Field label="Số tài khoản">
            <input
              placeholder="0071000123456"
              value={accountNumber}
              onChange={(event) => setAccountNumber(event.target.value.replace(/[^0-9]/g, ''))}
            />
          </Field>
          <Field label="Tên chủ tài khoản" hint="Đúng như đăng ký tại ngân hàng.">
            <input
              maxLength={160}
              value={accountName}
              onChange={(event) => setAccountName(event.target.value.toUpperCase())}
            />
          </Field>
          <p className="notice">
            Đổi tài khoản không ảnh hưởng các đơn đã tạo mã QR trước đó - thông tin trên các đơn đó
            được giữ nguyên như lúc tạo.
          </p>
        </>
      )}
    </ModalFrame>
  );
}

/** Enable or disable CASH and BANK_TRANSFER - the two fixed MVP methods. */
function PaymentMethodsModal() {
  const {
    paymentMethodOptions,
    paymentMethodsBusy,
    loadPaymentMethodOptions,
    setPaymentMethodEnabled,
  } = useAdminData();

  useEffect(() => {
    loadPaymentMethodOptions();
  }, [loadPaymentMethodOptions]);

  return (
    <ModalFrame title="Phương thức thanh toán">
      {paymentMethodOptions.length === 0 ? (
        <p>Đang tải...</p>
      ) : (
        <>
          <p className="notice">
            Cửa hàng phải luôn bật ít nhất một phương thức. Chuyển khoản cần cấu hình tài khoản nhận
            trước khi bật được.
          </p>
          {paymentMethodOptions.map((option) => (
            <div className="settings-row" key={option.method}>
              <div>
                <strong>{option.label}</strong>
                {option.unavailableReason === null ? null : (
                  <small>{option.unavailableReason}</small>
                )}
              </div>
              <label className="switch">
                <input
                  type="checkbox"
                  checked={option.enabled}
                  disabled={paymentMethodsBusy}
                  onChange={(event) => setPaymentMethodEnabled(option.method, event.target.checked)}
                />
                <span />
              </label>
            </div>
          ))}
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
      {modal.kind === 'payment' ? (
        <PaymentModal key={modal.paymentId} paymentId={modal.paymentId} />
      ) : null}
      {modal.kind === 'bank-account-form' ? <BankAccountFormModal /> : null}
      {modal.kind === 'payment-methods' ? <PaymentMethodsModal /> : null}
    </div>
  );
}
