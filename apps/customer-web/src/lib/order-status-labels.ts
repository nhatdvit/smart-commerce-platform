/** Matches admin-web's `ORDER_STATUS_LABELS` wording, so a customer and a staff member read the same word. */
export const ORDER_STATUS_LABELS: Readonly<Record<string, string>> = {
  PENDING_PAYMENT: 'Chờ chuyển khoản',
  PLACED: 'Chờ xác nhận',
  CONFIRMED: 'Đã xác nhận',
  PREPARING: 'Đang chuẩn bị',
  READY: 'Sẵn sàng',
  COMPLETED: 'Hoàn thành',
  CANCELLED: 'Đã hủy',
  EXPIRED: 'Hết hạn',
};

const ORDER_DATE_FORMAT = new Intl.DateTimeFormat('vi-VN', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
});

export function formatOrderDate(iso: string): string {
  return ORDER_DATE_FORMAT.format(new Date(iso));
}
