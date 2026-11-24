'use client';

import { useEffect, useState } from 'react';
import QRCode from 'qrcode';

import { money } from '@/lib/money';
import { reportTransfer, type BankTransferInstructions } from '@/lib/order-api';

import { IconCopy } from './icons';

/**
 * Copies a value to the clipboard with a toast, falling back to a prompt
 * dialog when the Clipboard API refuses (an insecure context, an older
 * browser) - the same thing a customer could do by hand, just one tap.
 *
 * Shared between the post-checkout confirmation screen and "Đơn hàng của
 * tôi", which both show the same bank/amount/reference rows.
 */
export function CopyButton({
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
export function QrCode({
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
 * The QR/bank-details/amount grid - shared by the post-checkout confirmation
 * screen and the "Thanh toán ngay" action on "Đơn hàng của tôi", which both
 * show the exact same instructions for the exact same backend shape
 * (`OrderPaymentInfo.bankTransfer`).
 */
export function BankTransferPanel({
  bankTransfer,
  orderCode,
  onToast,
}: {
  readonly bankTransfer: BankTransferInstructions;
  readonly orderCode: string;
  readonly onToast: (message: string) => void;
}) {
  return (
    <div className="confirm-payment-grid">
      <div className="confirm-qr-col">
        {bankTransfer.qrPayload === null ? null : (
          <QrCode payload={bankTransfer.qrPayload} downloadName={`vietqr-${orderCode}.png`} />
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
  );
}

/**
 * "I have transferred", with an optional receipt photo - a notification for
 * staff, never proof (CLAUDE.md section 6, decision dated 2026-11-24): the
 * order stays exactly where it was until a staff member confirms the real
 * bank credit themselves. This form has no "paid" state to reach.
 */
export function TransferReportForm({
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
