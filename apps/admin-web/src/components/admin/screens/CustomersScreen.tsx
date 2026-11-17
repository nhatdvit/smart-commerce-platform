'use client';

import { DEMO_CUSTOMERS } from '@/data/admin-demo';
import { money } from '@/lib/money';

import { useAdminData } from '../AdminDataProvider';
import { IconDownload, IconStar } from '../icons';
import { PageHeading } from '../primitives';

export function CustomersScreen() {
  const { notify } = useAdminData();

  return (
    <>
      <PageHeading
        title="Khách hàng"
        description="Quản lý khách mua thường xuyên và thành viên tích điểm."
        actions={
          <button
            type="button"
            className="btn"
            onClick={() => notify('Xuất file sẽ được triển khai khi kết nối API Laravel.')}
          >
            <IconDownload size={15} /> Xuất danh sách
          </button>
        }
      />

      <section className="panel">
        <div className="panel-title">
          <h3>Khách hàng gần đây</h3>
        </div>
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>KHÁCH HÀNG</th>
                <th>SỐ ĐƠN</th>
                <th>TỔNG CHI TIÊU</th>
                <th>ĐIỂM HIỆN CÓ</th>
                <th>HẠNG</th>
              </tr>
            </thead>
            <tbody>
              {DEMO_CUSTOMERS.map((customer) => (
                <tr key={customer.name}>
                  <td>
                    <strong>{customer.name}</strong>
                  </td>
                  <td>{customer.orders}</td>
                  <td>{money(customer.spent)}</td>
                  <td>
                    <strong>
                      <IconStar size={13} /> {customer.points}
                    </strong>
                  </td>
                  <td>
                    <span className="status ready">{customer.tier}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </>
  );
}
