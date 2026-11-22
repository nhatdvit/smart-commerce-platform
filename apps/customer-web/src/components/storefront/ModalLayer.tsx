'use client';

import { useId, useState, type ReactNode } from 'react';

import type { MenuProductCard } from '@/lib/menu-api';
import { money } from '@/lib/money';

import { FoodIcon, IconCheckCircle, IconGift, type FoodIconName } from './icons';
import type { CartTotals, ModalView } from './use-storefront';

/**
 * Sample rewards for the points-redemption demo.
 *
 * Not real menu items: the Loyalty module's reward catalogue is its own thing
 * (CLAUDE.md section 5) and there is no customer account yet to read a real
 * balance from (delivery stage 4), so this stays illustrative until both
 * exist.
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

/** Local date in `yyyy-mm-dd`, for the `min` and default of the pickup date input. */
function localDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${date.getFullYear()}-${month}-${day}`;
}

interface CheckoutFormProps {
  readonly totals: CartTotals;
  readonly onPlace: (customerName: string, method: 'cash' | 'bank') => void;
  readonly onInvalid: () => void;
}

function CheckoutForm({ totals, onPlace, onInvalid }: CheckoutFormProps) {
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [method, setMethod] = useState<'cash' | 'bank'>('cash');

  // The date inputs default to tomorrow, as the design does. Computed on first
  // render of a client-only layer, so there is no server/client disagreement.
  const [dates] = useState(() => {
    const today = new Date();
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);

    return { min: localDate(today), value: localDate(tomorrow) };
  });

  function submit() {
    const trimmedName = name.trim();
    const trimmedPhone = phone.trim();
    if (trimmedName.length < 2 || !/^[0-9+().\s-]{9,15}$/.test(trimmedPhone)) {
      onInvalid();

      return;
    }
    onPlace(trimmedName, method);
  }

  return (
    <>
      <p className="modal-note">Đơn hàng demo — chưa kết nối thanh toán thực tế.</p>
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
      <div className="form-grid">
        <div className="form-row">
          <label htmlFor="pickupDate">Ngày nhận</label>
          <input type="date" id="pickupDate" min={dates.min} defaultValue={dates.value} />
        </div>
        <div className="form-row">
          <label htmlFor="pickupTime">Giờ nhận</label>
          <select id="pickupTime" defaultValue="07:00">
            <option>06:30</option>
            <option>07:00</option>
            <option>07:30</option>
            <option>08:00</option>
            <option>08:30</option>
            <option>09:00</option>
          </select>
        </div>
      </div>
      <div className="form-row">
        <label htmlFor="payMethod">Phương thức thanh toán</label>
        <select
          id="payMethod"
          value={method}
          onChange={(event) => setMethod(event.target.value === 'bank' ? 'bank' : 'cash')}
        >
          <option value="cash">Tiền mặt khi nhận hàng</option>
          <option value="bank">Chuyển khoản VietQR (demo)</option>
        </select>
      </div>
      <div className="form-row">
        <label htmlFor="orderNote">Ghi chú</label>
        <textarea id="orderNote" rows={2} placeholder="Ít cay, không hành..." />
      </div>
      <div className="modal-total">{money(totals.total)}</div>
      <button className="btn btn-yellow" type="button" onClick={submit}>
        Xác nhận đặt món →
      </button>
    </>
  );
}

interface RewardListProps {
  readonly points: number;
  readonly onRedeem: (name: string, cost: number) => void;
}

function RewardList({ points, onRedeem }: RewardListProps) {
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
        Điểm và đổi thưởng trong bản này chỉ là mô phỏng, chưa có tài khoản thành viên hoặc xác minh
        tồn kho từ máy chủ.
      </p>
    </>
  );
}

interface ModalLayerProps {
  readonly view: ModalView;
  readonly totals: CartTotals;
  readonly points: number;
  readonly cardsByProductId: ReadonlyMap<string, MenuProductCard>;
  readonly onClose: () => void;
  readonly onAddMenuItem: (menuItemId: string) => void;
  readonly onPlaceOrder: (customerName: string, method: 'cash' | 'bank') => void;
  readonly onInvalidCheckout: () => void;
  readonly onRedeem: (name: string, cost: number) => void;
}

export function ModalLayer({
  view,
  totals,
  points,
  cardsByProductId,
  onClose,
  onAddMenuItem,
  onPlaceOrder,
  onInvalidCheckout,
  onRedeem,
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
        <CheckoutForm totals={totals} onPlace={onPlaceOrder} onInvalid={onInvalidCheckout} />
      </ModalShell>
    );
  }

  if (view.kind === 'rewards') {
    return (
      <ModalShell title="Đổi điểm lấy món ngon" icon={<IconGift size={20} />} onClose={onClose}>
        <RewardList points={points} onRedeem={onRedeem} />
      </ModalShell>
    );
  }

  if (view.kind === 'login') {
    return (
      <ModalShell title="Tài khoản thành viên" onClose={onClose}>
        <p>
          Đăng nhập và quản lý điểm thưởng sẽ được kết nối với Laravel API trong giai đoạn triển
          khai Backend.
        </p>
        <button className="btn btn-yellow" type="button" onClick={onClose}>
          Đã hiểu
        </button>
      </ModalShell>
    );
  }

  return (
    <ModalShell
      title="Đã ghi nhận đơn hàng demo"
      icon={<IconCheckCircle size={20} />}
      onClose={onClose}
    >
      <p>
        Cảm ơn <strong>{view.customerName}</strong>! Đơn của bạn đã được lưu mô phỏng trên trình
        duyệt.
      </p>
      <p>
        Phương thức:{' '}
        <strong>
          {view.method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản VietQR (chưa kết nối ngân hàng)'}
        </strong>
      </p>
      <p>
        Điểm thưởng mô phỏng vừa nhận: <strong>+{view.earnedPoints} điểm</strong>.
      </p>
      <p className="modal-note">
        Bản demo không tạo giao dịch thật, không xác nhận thanh toán hoặc đặt hàng trên máy chủ.
      </p>
      <button className="btn btn-yellow" type="button" onClick={onClose}>
        Hoàn tất
      </button>
    </ModalShell>
  );
}
