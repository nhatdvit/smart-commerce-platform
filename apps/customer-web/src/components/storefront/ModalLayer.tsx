'use client';

import { useEffect, useId, useState, type ReactNode } from 'react';
import Link from 'next/link';
import QRCode from 'qrcode';

import type { CurrentCustomer, LoyaltyStanding } from '@/lib/auth-api';
import type { MenuProductCard } from '@/lib/menu-api';
import { money } from '@/lib/money';
import {
  reportTransfer,
  type FulfillmentType,
  type OrderConfirmation,
  type PaymentMethod,
} from '@/lib/order-api';
import { ORDER_STATUS_LABELS } from '@/lib/order-status-labels';

import {
  FoodIcon,
  IconBag,
  IconCheckCircle,
  IconCopy,
  IconGift,
  IconLogout,
  IconShield,
  IconUser,
  type FoodIconName,
} from './icons';
import type { CartTotals, ModalView } from './use-storefront';

/**
 * Sample rewards for the points-redemption demo.
 *
 * Not real menu items: the Loyalty module's reward catalogue is its own thing
 * (CLAUDE.md section 5). The balance they are weighed against is real
 * (`GET /customer/loyalty`); the redemption itself still is not - see
 * `redeemReward` in `use-storefront.ts`.
 */
const DEMO_REWARDS: readonly {
  readonly name: string;
  readonly icon: FoodIconName;
  readonly points: number;
}[] = [
  { name: 'Cà phê sữa đá', icon: 'drink', points: 150 },
  { name: 'Bánh mì pate trứng', icon: 'banh-mi', points: 200 },
  { name: 'Chả ốc 250g', icon: 'gift', points: 500 },
];

interface ModalShellProps {
  readonly title: string;
  /** Optional leading icon, shown before the title. */
  readonly icon?: ReactNode;
  readonly onClose: () => void;
  readonly children: ReactNode;
}

function ModalShell({ title, icon, onClose, children }: ModalShellProps) {
  const titleId = useId();

  return (
    <div className="modal" role="dialog" aria-modal="true" aria-labelledby={titleId}>
      <div className="modal-head">
        <h3 id={titleId}>
          {icon} {title}
        </h3>
        <button type="button" className="close" onClick={onClose}>
          ✕
        </button>
      </div>
      <div>{children}</div>
    </div>
  );
}

interface VariantPickerProps {
  readonly card: MenuProductCard;
  readonly onConfirm: (menuItemId: string) => void;
}

function VariantPicker({ card, onConfirm }: VariantPickerProps) {
  const [menuItemId, setMenuItemId] = useState(() => card.options[0]?.id ?? '');

  return (
    <>
      {card.imageUrl === null ? (
        <FoodIcon name={card.icon} size={64} />
      ) : (
        <img
          src={card.imageUrl}
          alt={card.name}
          style={{ width: '100%', height: 160, objectFit: 'cover', borderRadius: 12 }}
        />
      )}
      <p>Chọn quy cách phù hợp:</p>
      <div className="form-row">
        <label htmlFor="variantChoice">Quy cách</label>
        <select
          id="variantChoice"
          value={menuItemId}
          onChange={(event) => setMenuItemId(event.target.value)}
        >
          {card.options.map((option) => (
            <option key={option.id} value={option.id}>
              {option.variantName} — {money(option.price)}
            </option>
          ))}
        </select>
      </div>
      <button
        className="btn btn-yellow"
        type="button"
        disabled={menuItemId === ''}
        onClick={() => onConfirm(menuItemId)}
      >
        Thêm vào giỏ →
      </button>
    </>
  );
}

interface CheckoutFormProps {
  readonly totals: CartTotals;
  readonly busy: boolean;
  readonly error: string;
  readonly currentCustomer: CurrentCustomer | null;
  readonly onPlace: (
    contactName: string,
    contactPhone: string,
    fulfillmentType: FulfillmentType,
    paymentMethod: PaymentMethod,
    deliveryAddressLine?: string,
  ) => void;
  readonly onInvalid: () => void;
}

/** Prefilled from the signed-in customer's own profile, still editable - someone else may be picking up the order. */
function CheckoutForm({
  totals,
  busy,
  error,
  currentCustomer,
  onPlace,
  onInvalid,
}: CheckoutFormProps) {
  const [name, setName] = useState(currentCustomer?.name ?? '');
  const [phone, setPhone] = useState(currentCustomer?.phone ?? '');
  const [fulfillmentType, setFulfillmentType] = useState<FulfillmentType>('PICKUP');
  const [address, setAddress] = useState('');
  const [method, setMethod] = useState<PaymentMethod>('CASH');

  function submit() {
    if (busy) {
      return;
    }
    const trimmedName = name.trim();
    const trimmedPhone = phone.trim();
    const trimmedAddress = address.trim();
    const validContact = trimmedName.length >= 2 && /^[0-9+().\s-]{9,15}$/.test(trimmedPhone);
    const validAddress = fulfillmentType === 'PICKUP' || trimmedAddress.length >= 5;

    if (!validContact || !validAddress) {
      onInvalid();

      return;
    }
    onPlace(
      trimmedName,
      trimmedPhone,
      fulfillmentType,
      method,
      fulfillmentType === 'DELIVERY' ? trimmedAddress : undefined,
    );
  }

  return (
    <>
      <div className="form-row">
        <label htmlFor="customerName">Tên người nhận *</label>
        <input
          id="customerName"
          maxLength={100}
          placeholder="Họ và tên"
          value={name}
          onChange={(event) => setName(event.target.value)}
        />
      </div>
      <div className="form-row">
        <label htmlFor="customerPhone">Số điện thoại *</label>
        <input
          id="customerPhone"
          inputMode="tel"
          maxLength={15}
          placeholder="09xxxxxxxx"
          value={phone}
          onChange={(event) => setPhone(event.target.value)}
        />
      </div>
      <div className="form-row">
        <label htmlFor="fulfillmentType">Hình thức nhận hàng</label>
        <select
          id="fulfillmentType"
          value={fulfillmentType}
          onChange={(event) =>
            setFulfillmentType(event.target.value === 'DELIVERY' ? 'DELIVERY' : 'PICKUP')
          }
        >
          <option value="PICKUP">Nhận tại quán</option>
          <option value="DELIVERY">Giao hàng</option>
        </select>
      </div>
      {fulfillmentType === 'DELIVERY' ? (
        <div className="form-row">
          <label htmlFor="deliveryAddress">Địa chỉ giao hàng *</label>
          <input
            id="deliveryAddress"
            maxLength={255}
            placeholder="Số nhà, đường, phường/xã..."
            value={address}
            onChange={(event) => setAddress(event.target.value)}
          />
        </div>
      ) : null}
      <div className="form-row">
        <label htmlFor="payMethod">Phương thức thanh toán</label>
        <select
          id="payMethod"
          value={method}
          onChange={(event) =>
            setMethod(event.target.value === 'BANK_TRANSFER' ? 'BANK_TRANSFER' : 'CASH')
          }
        >
          <option value="CASH">Tiền mặt khi nhận hàng</option>
          <option value="BANK_TRANSFER">Chuyển khoản VietQR</option>
        </select>
      </div>
      {error === '' ? null : <p className="notice">{error}</p>}
      <div className="modal-total">{money(totals.subtotal)}</div>
      <button className="btn btn-yellow" type="button" disabled={busy} onClick={submit}>
        {busy ? 'Đang xử lý...' : 'Xác nhận đặt món →'}
      </button>
    </>
  );
}

interface AuthFormProps {
  readonly busy: boolean;
  readonly error: string;
  readonly onLogin: (phone: string, password: string) => void;
  readonly onRegister: (fullName: string, phone: string, password: string) => void;
}

/** Phone + password, registering or signing in - see `RegisterRequest`/`LoginRequest` on the backend. */
function AuthForm({ busy, error, onLogin, onRegister }: AuthFormProps) {
  const [mode, setMode] = useState<'login' | 'register'>('login');
  const [fullName, setFullName] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');

  function submit() {
    if (busy) {
      return;
    }
    if (mode === 'login') {
      onLogin(phone.trim(), password);
    } else {
      onRegister(fullName.trim(), phone.trim(), password);
    }
  }

  return (
    <>
      {mode === 'register' ? (
        <div className="form-row">
          <label htmlFor="authFullName">Họ và tên</label>
          <input
            id="authFullName"
            maxLength={150}
            placeholder="Họ và tên"
            value={fullName}
            onChange={(event) => setFullName(event.target.value)}
          />
        </div>
      ) : null}
      <div className="form-row">
        <label htmlFor="authPhone">Số điện thoại</label>
        <input
          id="authPhone"
          inputMode="tel"
          maxLength={20}
          placeholder="09xxxxxxxx"
          value={phone}
          onChange={(event) => setPhone(event.target.value)}
        />
      </div>
      <div className="form-row">
        <label htmlFor="authPassword">Mật khẩu</label>
        <input
          id="authPassword"
          type="password"
          maxLength={255}
          placeholder="Tối thiểu 8 ký tự"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />
      </div>
      {error === '' ? null : <p className="notice">{error}</p>}
      <button className="btn btn-yellow" type="button" disabled={busy} onClick={submit}>
        {mode === 'login' ? 'Đăng nhập' : 'Đăng ký'} →
      </button>
      <p className="modal-note">
        {mode === 'login' ? (
          <>
            Chưa có tài khoản?{' '}
            <button type="button" className="link-button" onClick={() => setMode('register')}>
              Đăng ký ngay
            </button>
          </>
        ) : (
          <>
            Đã có tài khoản?{' '}
            <button type="button" className="link-button" onClick={() => setMode('login')}>
              Đăng nhập
            </button>
          </>
        )}
      </p>
    </>
  );
}

interface ProfileFormProps {
  readonly customer: CurrentCustomer;
  readonly profileBusy: boolean;
  readonly profileError: string;
  readonly passwordBusy: boolean;
  readonly passwordError: string;
  readonly onUpdateName: (name: string) => void;
  readonly onChangePassword: (currentPassword: string, newPassword: string) => void;
  readonly onLogout: () => void;
}

/** A signed-in customer's own name, password and sign-out - see `ProfileController` on the backend. */
function ProfileForm({
  customer,
  profileBusy,
  profileError,
  passwordBusy,
  passwordError,
  onUpdateName,
  onChangePassword,
  onLogout,
}: ProfileFormProps) {
  const [name, setName] = useState(customer.name);
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const nameChanged = name.trim() !== customer.name && name.trim() !== '';
  const initial = (customer.name.trim()[0] ?? '?').toLocaleUpperCase('vi');

  return (
    <>
      <div className="profile-identity">
        <span className="profile-avatar" aria-hidden="true">
          {initial}
        </span>
        <div>
          <strong>{customer.name}</strong>
          <small>{customer.phone}</small>
        </div>
      </div>

      <Link href="/orders" className="btn btn-outline btn-centered">
        <IconBag size={17} /> Đơn hàng của tôi
      </Link>

      <div className="form-row">
        <label htmlFor="profileName">Họ và tên</label>
        <input
          id="profileName"
          maxLength={150}
          value={name}
          onChange={(event) => setName(event.target.value)}
        />
      </div>
      {profileError === '' ? null : <p className="notice">{profileError}</p>}
      <button
        className="btn btn-yellow"
        type="button"
        disabled={profileBusy || !nameChanged}
        onClick={() => onUpdateName(name.trim())}
      >
        Lưu thay đổi →
      </button>

      <div className="profile-section">
        <h4 className="modal-subheading">
          <IconShield size={15} /> Đổi mật khẩu
        </h4>
        <div className="form-row">
          <label htmlFor="profileCurrentPassword">Mật khẩu hiện tại</label>
          <input
            id="profileCurrentPassword"
            type="password"
            maxLength={255}
            value={currentPassword}
            onChange={(event) => setCurrentPassword(event.target.value)}
          />
        </div>
        <div className="form-row">
          <label htmlFor="profileNewPassword">Mật khẩu mới</label>
          <input
            id="profileNewPassword"
            type="password"
            maxLength={255}
            placeholder="Tối thiểu 8 ký tự"
            value={newPassword}
            onChange={(event) => setNewPassword(event.target.value)}
          />
        </div>
        {passwordError === '' ? null : <p className="notice">{passwordError}</p>}
        <button
          type="button"
          className="btn btn-outline btn-centered"
          disabled={passwordBusy || currentPassword === '' || newPassword === ''}
          onClick={() => {
            onChangePassword(currentPassword, newPassword);
            setCurrentPassword('');
            setNewPassword('');
          }}
        >
          Đổi mật khẩu
        </button>
      </div>

      <button
        type="button"
        className="btn btn-danger-outline btn-centered logout-button"
        onClick={onLogout}
      >
        <IconLogout size={17} /> Đăng xuất
      </button>
    </>
  );
}

/**
 * Copies a value to the clipboard with a toast, falling back to a prompt
 * dialog when the Clipboard API refuses (an insecure context, an older
 * browser) - the same thing a customer could do by hand, just one tap.
 */
function CopyButton({
  value,
  label,
  onToast,
}: {
  readonly value: string;
  readonly label: string;
  readonly onToast: (message: string) => void;
}) {
  function copy() {
    void navigator.clipboard
      .writeText(value)
      .then(() => onToast('Đã sao chép'))
      .catch(() => {
        try {
          window.prompt('Sao chép thông tin:', value);
        } catch {
          onToast(value);
        }
      });
  }

  return (
    <button type="button" className="copy-btn" aria-label={label} onClick={copy}>
      <IconCopy size={15} />
    </button>
  );
}

/**
 * Renders an EMVCo/VietQR payload client-side - the server sends the
 * payload, never an image - plus a download link off the same data URL, so
 * a customer can save the QR instead of keeping this tab open.
 */
function QrCode({
  payload,
  downloadName,
}: {
  readonly payload: string;
  readonly downloadName: string;
}) {
  const [dataUrl, setDataUrl] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    QRCode.toDataURL(payload, { width: 220, margin: 1 })
      .then((url) => {
        if (!cancelled) {
          setDataUrl(url);
        }
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [payload]);

  if (dataUrl === null) {
    return <div className="qr-placeholder" aria-hidden="true" />;
  }

  return (
    <>
      <img
        className="qr-code"
        src={dataUrl}
        alt="Mã QR chuyển khoản VietQR"
        width={220}
        height={220}
      />
      <a className="btn btn-outline qr-download" href={dataUrl} download={downloadName}>
        Tải mã QR
      </a>
    </>
  );
}

/**
 * "I have transferred", with an optional receipt photo - a notification for
 * staff, never proof (CLAUDE.md section 6, decision dated 2026-11-24): the
 * order stays exactly where it was until a staff member confirms the real
 * bank credit themselves. This form has no "paid" state to reach.
 */
function TransferReportForm({
  orderId,
  guestOrderToken,
  onReported,
}: {
  readonly orderId: string;
  readonly guestOrderToken: string | null;
  readonly onReported: () => void;
}) {
  const [receipt, setReceipt] = useState<File | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [reportedAt, setReportedAt] = useState<string | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (receipt === null) {
      setPreviewUrl(null);

      return undefined;
    }

    const url = URL.createObjectURL(receipt);
    setPreviewUrl(url);

    return () => {
      URL.revokeObjectURL(url);
    };
  }, [receipt]);

  if (reportedAt !== null) {
    return (
      <p className="modal-note transfer-reported" role="status">
        ✓ Đã gửi thông báo cho cửa hàng lúc{' '}
        {new Date(reportedAt).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })}.
        Nhân viên sẽ kiểm tra và xác nhận sau khi thấy tiền thật trong ngân hàng.
      </p>
    );
  }

  function submit() {
    setSubmitting(true);
    setError('');

    void reportTransfer(orderId, guestOrderToken, receipt)
      .then((at) => {
        setReportedAt(at ?? new Date().toISOString());
        onReported();
      })
      .catch(() => setError('Không gửi được thông báo. Vui lòng thử lại.'))
      .finally(() => setSubmitting(false));
  }

  return (
    <div className="transfer-report">
      <div className="confirm-actions">
        <label className="transfer-report-upload">
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            onChange={(event) => setReceipt(event.target.files?.[0] ?? null)}
          />
          Tải ảnh biên lai (không bắt buộc)
        </label>
        <button type="button" className="btn btn-yellow" disabled={submitting} onClick={submit}>
          {submitting ? 'Đang gửi...' : '✓ Tôi đã chuyển khoản'}
        </button>
      </div>
      {previewUrl === null ? null : (
        <div className="confirm-receipt-preview">
          <img src={previewUrl} alt="Ảnh biên lai xem trước" />
          <button type="button" className="btn btn-outline" onClick={() => setReceipt(null)}>
            Xóa ảnh
          </button>
        </div>
      )}
      {error === '' ? null : (
        <p className="modal-note" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

type ConfirmStepState = 'done' | 'active' | 'pending';

/**
 * Four display steps over the real order/payment state - never a status of
 * its own (CLAUDE.md section 6: order state and payment state stay separate
 * machines). The second step's label is whatever `confirmation.status`
 * actually is right now, so it reads correctly whichever status an order is
 * created in; only "paid" and "completed" are inferred, from the real
 * payment/order fields.
 */
function confirmSteps(
  confirmation: OrderConfirmation,
): readonly { readonly label: string; readonly state: ConfirmStepState }[] {
  const paid = confirmation.paymentStatus === 'PAID';
  const completed = confirmation.status === 'COMPLETED';

  return [
    { label: 'Đã đặt hàng', state: 'done' },
    {
      label: ORDER_STATUS_LABELS[confirmation.status] ?? confirmation.status,
      state: paid || completed ? 'done' : 'active',
    },
    { label: 'Đã thanh toán', state: paid ? 'done' : 'pending' },
    { label: 'Hoàn thành', state: completed ? 'done' : 'pending' },
  ];
}

/**
 * The one-line payment summary at the bottom - cash never needs a "chờ xác
 * nhận" state since nothing is collected until pickup/delivery; bank
 * transfer reads `transferReported` (set the moment this tab's own report
 * succeeds) ahead of a page reload actually bringing back `PROCESSING`.
 */
function paymentStatusText(confirmation: OrderConfirmation, transferReported: boolean): string {
  if (confirmation.paymentMethod === 'CASH') {
    return confirmation.cash?.collectAt === 'ON_DELIVERY'
      ? 'Tiền mặt khi nhận hàng giao tới'
      : 'Tiền mặt khi nhận hàng tại quán';
  }
  if (confirmation.paymentStatus === 'PAID') {
    return 'Đã thanh toán';
  }
  if (transferReported || confirmation.paymentStatus === 'PROCESSING') {
    return 'Chờ xác nhận thanh toán';
  }

  return 'Chưa thanh toán';
}

interface OrderConfirmedProps {
  readonly confirmation: OrderConfirmation;
  readonly onClose: () => void;
  readonly onToast: (message: string) => void;
}

/**
 * Its own dialog shell, not `ModalShell` - the approved design (vietqr-popup
 * mock) has a header, QR/bank grid and status tracker no other modal needs,
 * so it draws the `.modal` box itself instead of forcing that layout through
 * the generic icon+title header every other modal in this file shares.
 */
function OrderConfirmed({ confirmation, onClose, onToast }: OrderConfirmedProps) {
  const { bankTransfer, cash } = confirmation;
  const titleId = useId();
  const [transferReported, setTransferReported] = useState(false);
  const steps = confirmSteps(confirmation);
  const createdTime = new Date(confirmation.createdAt).toLocaleTimeString('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
  });

  return (
    <div className="modal confirm-modal" role="dialog" aria-modal="true" aria-labelledby={titleId}>
      <button type="button" className="close confirm-close" aria-label="Đóng" onClick={onClose}>
        ✕
      </button>

      <header className="confirm-head">
        <div className="confirm-badge" aria-hidden="true">
          <IconCheckCircle size={32} />
        </div>
        <div>
          <h3 id={titleId}>Đặt hàng thành công</h3>
          <p>
            Cảm ơn <strong>{confirmation.contactName}</strong>! Đơn hàng đã được ghi nhận.
          </p>
          <div className="confirm-order-chip">
            <span>Mã đơn hàng</span>
            <strong>{confirmation.orderCode}</strong>
            <CopyButton value={confirmation.orderCode} label="Sao chép mã đơn" onToast={onToast} />
          </div>
        </div>
      </header>

      <div className="confirm-body">
        {bankTransfer === null ? null : (
          <section className="confirm-section">
            <h4>Thanh toán chuyển khoản</h4>
            <p className="modal-note">Quét mã bằng app ngân hàng, hoặc chuyển khoản thủ công:</p>

            <div className="confirm-payment-grid">
              <div className="confirm-qr-col">
                {bankTransfer.qrPayload === null ? null : (
                  <QrCode
                    payload={bankTransfer.qrPayload}
                    downloadName={`vietqr-${confirmation.orderCode}.png`}
                  />
                )}
              </div>
              <div className="confirm-bank-col">
                <dl className="qr-details">
                  <div className="qr-detail-row">
                    <dt>Ngân hàng</dt>
                    <dd>{bankTransfer.bankName ?? bankTransfer.bankBin ?? '—'}</dd>
                  </div>
                  <div className="qr-detail-row">
                    <dt>Số tài khoản</dt>
                    <dd>
                      {bankTransfer.accountNumber ?? '—'}
                      {bankTransfer.accountNumber === null ? null : (
                        <CopyButton
                          value={bankTransfer.accountNumber}
                          label="Sao chép số tài khoản"
                          onToast={onToast}
                        />
                      )}
                    </dd>
                  </div>
                  <div className="qr-detail-row">
                    <dt>Chủ tài khoản</dt>
                    <dd>{bankTransfer.accountName ?? '—'}</dd>
                  </div>
                  <div className="qr-detail-row">
                    <dt>Nội dung CK</dt>
                    <dd>
                      {bankTransfer.transferReference}
                      <CopyButton
                        value={bankTransfer.transferReference}
                        label="Sao chép nội dung chuyển khoản"
                        onToast={onToast}
                      />
                    </dd>
                  </div>
                </dl>
                <div className="confirm-amount">
                  <span>Số tiền thanh toán</span>
                  <strong>{money(bankTransfer.amount)}</strong>
                  <CopyButton
                    value={String(bankTransfer.amount)}
                    label="Sao chép số tiền"
                    onToast={onToast}
                  />
                </div>
              </div>
            </div>

            <p className="confirm-info">
              Sau khi chuyển khoản, vui lòng xác nhận để cửa hàng kiểm tra và xác nhận thanh toán -
              mã QR không phải là bằng chứng đã thanh toán.
            </p>

            <div className="confirm-section-label">XÁC NHẬN THANH TOÁN</div>

            <TransferReportForm
              orderId={confirmation.orderId}
              guestOrderToken={confirmation.guestOrderToken}
              onReported={() => setTransferReported(true)}
            />
          </section>
        )}

        {cash === null ? null : (
          <section className="confirm-section">
            <h4>Thanh toán tiền mặt</h4>
            <p className="modal-note">
              {cash.collectAt === 'ON_DELIVERY'
                ? 'Thanh toán tiền mặt khi nhận hàng giao tới.'
                : 'Thanh toán tiền mặt khi nhận hàng tại quán.'}
            </p>
          </section>
        )}

        <section className="confirm-section confirm-status">
          <h4>Trạng thái đơn hàng</h4>
          <div className="confirm-steps">
            {steps.map((step, index) => (
              <div key={index} className={`confirm-step is-${step.state}`}>
                <i>{step.state === 'done' ? '✓' : null}</i>
                {step.label}
                {index === 0 ? <small>{createdTime}</small> : null}
              </div>
            ))}
          </div>
          <div className="confirm-payment-status">
            <span>Thanh toán</span>
            <strong>{paymentStatusText(confirmation, transferReported)}</strong>
          </div>
        </section>
      </div>

      <footer className="confirm-foot">
        <button className="btn btn-yellow" type="button" onClick={onClose}>
          Hoàn tất
        </button>
      </footer>
    </div>
  );
}

interface RewardListProps {
  readonly balance: number;
  readonly onRedeem: (name: string, cost: number) => void;
}

function RewardList({ balance: points, onRedeem }: RewardListProps) {
  return (
    <>
      <p>
        Bạn đang có <strong>{points} điểm</strong>. Chọn phần thưởng để đổi:
      </p>
      {DEMO_REWARDS.map((reward) => {
        const affordable = points >= reward.points;

        return (
          <div className="reward-item" key={reward.name}>
            <FoodIcon name={reward.icon} size={32} />
            <div>
              <strong>{reward.name}</strong>
              <small>{reward.points} điểm</small>
            </div>
            <button
              type="button"
              disabled={!affordable}
              onClick={() => onRedeem(reward.name, reward.points)}
            >
              {affordable ? 'Đổi món' : 'Chưa đủ'}
            </button>
          </div>
        );
      })}
      <p className="modal-note">
        Số điểm là thật, nhưng việc đổi thưởng ở đây vẫn là mô phỏng - chưa trừ điểm hay xác minh
        tồn kho trên máy chủ.
      </p>
    </>
  );
}

interface ModalLayerProps {
  readonly view: ModalView;
  readonly totals: CartTotals;
  readonly loyaltyBalance: LoyaltyStanding | null;
  readonly cardsByProductId: ReadonlyMap<string, MenuProductCard>;
  readonly currentCustomer: CurrentCustomer | null;
  readonly authBusy: boolean;
  readonly authError: string;
  readonly profileBusy: boolean;
  readonly profileError: string;
  readonly passwordBusy: boolean;
  readonly passwordError: string;
  readonly checkoutBusy: boolean;
  readonly checkoutError: string;
  readonly onClose: () => void;
  readonly onAddMenuItem: (menuItemId: string) => void;
  readonly onPlaceOrder: (
    contactName: string,
    contactPhone: string,
    fulfillmentType: FulfillmentType,
    paymentMethod: PaymentMethod,
    deliveryAddressLine?: string,
  ) => void;
  readonly onInvalidCheckout: () => void;
  readonly onRedeem: (name: string, cost: number) => void;
  readonly onLogin: (phone: string, password: string) => void;
  readonly onRegister: (fullName: string, phone: string, password: string) => void;
  readonly onUpdateName: (name: string) => void;
  readonly onChangePassword: (currentPassword: string, newPassword: string) => void;
  readonly onLogout: () => void;
  readonly onToast: (message: string) => void;
}

export function ModalLayer({
  view,
  totals,
  loyaltyBalance,
  cardsByProductId,
  currentCustomer,
  authBusy,
  authError,
  profileBusy,
  profileError,
  passwordBusy,
  passwordError,
  checkoutBusy,
  checkoutError,
  onClose,
  onAddMenuItem,
  onPlaceOrder,
  onInvalidCheckout,
  onRedeem,
  onLogin,
  onRegister,
  onUpdateName,
  onChangePassword,
  onLogout,
  onToast,
}: ModalLayerProps) {
  if (view.kind === 'variants') {
    const card = cardsByProductId.get(view.productId);
    if (card === undefined) {
      return null;
    }

    return (
      <ModalShell title={`Chọn quy cách · ${card.name}`} onClose={onClose}>
        <VariantPicker card={card} onConfirm={onAddMenuItem} />
      </ModalShell>
    );
  }

  if (view.kind === 'checkout') {
    return (
      <ModalShell title="Xác nhận đặt hàng" onClose={onClose}>
        <CheckoutForm
          totals={totals}
          busy={checkoutBusy}
          error={checkoutError}
          currentCustomer={currentCustomer}
          onPlace={onPlaceOrder}
          onInvalid={onInvalidCheckout}
        />
      </ModalShell>
    );
  }

  if (view.kind === 'rewards') {
    return (
      <ModalShell title="Đổi điểm lấy món ngon" icon={<IconGift size={20} />} onClose={onClose}>
        <RewardList balance={loyaltyBalance?.balance ?? 0} onRedeem={onRedeem} />
      </ModalShell>
    );
  }

  if (view.kind === 'login') {
    return (
      <ModalShell title="Tài khoản thành viên" onClose={onClose}>
        <AuthForm busy={authBusy} error={authError} onLogin={onLogin} onRegister={onRegister} />
      </ModalShell>
    );
  }

  if (view.kind === 'profile') {
    if (currentCustomer === null) {
      return null;
    }

    return (
      <ModalShell title="Hồ sơ của bạn" icon={<IconUser size={18} />} onClose={onClose}>
        <ProfileForm
          customer={currentCustomer}
          profileBusy={profileBusy}
          profileError={profileError}
          passwordBusy={passwordBusy}
          passwordError={passwordError}
          onUpdateName={onUpdateName}
          onChangePassword={onChangePassword}
          onLogout={onLogout}
        />
      </ModalShell>
    );
  }

  return <OrderConfirmed confirmation={view.confirmation} onClose={onClose} onToast={onToast} />;
}
