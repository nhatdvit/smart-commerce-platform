'use client';

import { useEffect, useState } from 'react';

import { money } from '@/lib/money';

import { useAdminData } from '../AdminDataProvider';
import { IconDownload } from '../icons';
import { PageHeading } from '../primitives';

/**
 * Milliseconds to wait before searching.
 *
 * Matches the orders screen's own debounce: long enough that typing a full
 * name or phone is one request, short enough that it still feels like typing.
 */
const SEARCH_DEBOUNCE_MS = 300;

/** "20/11/2026", or a dash when there is none. */
function dateLabel(iso: string | null): string {
  if (iso === null) {
    return '—';
  }

  const at = new Date(iso);

  return Number.isNaN(at.getTime()) ? '—' : at.toLocaleDateString('vi-VN');
}

export function CustomersScreen() {
  const {
    customers,
    customersBusy,
    customersError,
    hasMoreCustomers,
    loadCustomers,
    loadMoreCustomers,
    notify,
  } = useAdminData();

  const [query, setQuery] = useState('');
  /** The debounced copy of `query`; only this one reaches the server. */
  const [search, setSearch] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setSearch(query.trim()), SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [query]);

  useEffect(() => {
    loadCustomers(search);
  }, [loadCustomers, search]);

  return (
    <>
      <PageHeading
        title="Khách hàng"
        description="Lịch sử mua hàng của khách, theo số điện thoại đã đặt."
        actions={
          <button
            type="button"
            className="btn"
            onClick={() => notify('Xuất file sẽ được triển khai cùng màn hình báo cáo.')}
          >
            <IconDownload size={15} /> Xuất danh sách
          </button>
        }
      />

      <div className="search-row">
        <input
          className="search"
          placeholder="Tìm theo tên hoặc số điện thoại..."
          aria-label="Tìm khách hàng theo tên hoặc số điện thoại"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
        />
      </div>

      <section className="panel">
        <div className="panel-title">
          <h3>Khách hàng gần đây</h3>
          <span className="panel-note" aria-live="polite">
            {customersBusy ? 'Đang tải...' : `${customers.length} khách`}
          </span>
        </div>

        {customersError !== '' ? (
          <div className="empty">
            <strong>Không tải được danh sách</strong>
            {customersError}
          </div>
        ) : (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>KHÁCH HÀNG</th>
                  <th>SỐ ĐIỆN THOẠI</th>
                  <th>SỐ ĐƠN</th>
                  <th>TỔNG CHI TIÊU</th>
                  <th>ĐƠN GẦN NHẤT</th>
                </tr>
              </thead>
              <tbody>
                {customers.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="empty">
                      {customersBusy
                        ? 'Đang tải...'
                        : search === ''
                          ? 'Chưa có khách hàng nào'
                          : 'Không tìm thấy khách hàng phù hợp'}
                    </td>
                  </tr>
                ) : (
                  customers.map((customer) => (
                    <tr key={customer.phone}>
                      <td>
                        <strong>{customer.name}</strong>
                      </td>
                      <td>{customer.phone}</td>
                      <td>{customer.orderCount}</td>
                      <td>
                        <strong>{money(customer.totalSpent)}</strong>
                      </td>
                      <td>{dateLabel(customer.lastOrderAt)}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        )}

        {!hasMoreCustomers ? null : (
          <button
            type="button"
            className="btn"
            onClick={loadMoreCustomers}
            disabled={customersBusy}
          >
            Tải thêm
          </button>
        )}
      </section>

      <p className="notice">
        Danh sách này là lịch sử đơn hàng thật, gộp theo số điện thoại khách đã đặt - chưa có tài
        khoản khách hàng đăng ký nên "khách hàng" ở đây chính là số điện thoại đó. Tổng chi tiêu chỉ
        tính các đơn đã xác nhận thanh toán; đơn chưa thu tiền chưa được tính là đã chi tiêu. Điểm
        tích lũy và hạng thành viên thuộc tính năng Tích điểm, hiển thị riêng khi tính năng đó có dữ
        liệu thật để tính.
      </p>
    </>
  );
}
