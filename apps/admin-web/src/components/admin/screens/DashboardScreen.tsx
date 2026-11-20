'use client';

import Link from 'next/link';
import { useEffect } from 'react';

import { BESTSELLER_COUNTS, REVENUE_CHART, WEEKDAY_LABELS } from '@/data/admin-demo';
import { money } from '@/lib/money';
import { DEFAULT_ORDER_QUERY } from '@/lib/orders-api';

import { useAdminData } from '../AdminDataProvider';
import {
  IconChevronDown,
  IconClock,
  IconHeart,
  IconMenuBook,
  IconReceipt,
  IconStar,
  IconTrendUp,
} from '../icons';
import { Metric, OrdersTable, PageHeading } from '../primitives';
import { ProductThumb } from '../ProductThumb';

export function DashboardScreen() {
  const { orders, products, pendingCount, notifyAction, loadOrders } = useAdminData();

  // This screen shows the orders that just came in, whatever the orders screen
  // was last filtered to. Asking for the default list is how it says so; an
  // identical request is not re-sent.
  useEffect(() => {
    loadOrders(DEFAULT_ORDER_QUERY);
  }, [loadOrders]);

  return (
    <>
      <PageHeading
        title="Tổng quan hôm nay"
        description="Theo dõi hoạt động kinh doanh và chuẩn bị đơn hàng hôm nay."
        actions={
          <>
            <Link className="btn" href="/menu">
              <IconMenuBook size={15} /> Quản lý thực đơn
            </Link>
            <Link className="btn btn-primary" href="/orders">
              <IconReceipt size={15} /> Xem đơn hàng
            </Link>
          </>
        }
      />

      <div className="welcome">
        <div>
          <h2>Bắt đầu ca sáng</h2>
          <p>
            Bạn có <b>{pendingCount} đơn đang chờ xác nhận</b>. Hãy kiểm tra để khách nhận bữa sáng
            đúng giờ.
          </p>
        </div>
        <Link className="btn btn-primary" href="/orders">
          Xử lý đơn ngay →
        </Link>
      </div>

      <div className="metrics">
        <Metric
          title="Doanh thu hôm nay"
          value="1.285.000đ"
          icon={<IconTrendUp size={18} />}
          color="#fff3d9"
          foot={
            <>
              <b>↑ 12,8%</b> so với hôm qua
            </>
          }
        />
        <Metric
          title="Tổng đơn hàng"
          value="48 đơn"
          icon={<IconReceipt size={18} />}
          color="#e9f0ff"
          foot={
            <>
              <b>↑ 8,2%</b> so với hôm qua
            </>
          }
        />
        <Metric
          title="Đơn chờ xử lý"
          value={`${pendingCount} đơn`}
          icon={<IconClock size={18} />}
          color="#fff0e9"
          foot="Cần xác nhận sớm"
        />
        <Metric
          title="Khách hàng quay lại"
          value="67%"
          icon={<IconHeart size={18} />}
          color="#e9f8f0"
          foot={
            <>
              <b>↑ 5,4%</b> so với tuần trước
            </>
          }
        />
      </div>

      <div className="two-col">
        <section className="panel">
          <div className="panel-title">
            <h3>Doanh thu 7 ngày gần đây</h3>
            <button type="button" onClick={() => notifyAction('demo')}>
              7 ngày gần nhất <IconChevronDown size={13} />
            </button>
          </div>
          <div className="chart-wrap">
            {REVENUE_CHART.map((height, index) => (
              <div className="bar-col" key={WEEKDAY_LABELS[index] ?? index}>
                <div
                  className={index === 5 ? 'bar highlight' : 'bar'}
                  style={{ height: `${height}%` }}
                />
                <small style={{ left: `calc(${((index + 0.5) / 7) * 100}% - 10px)` }}>
                  {WEEKDAY_LABELS[index]}
                </small>
              </div>
            ))}
          </div>
          <div className="chart-foot">
            <span>Doanh thu minh họa</span>
            <strong>8.950.000đ / tuần</strong>
          </div>
        </section>

        <section className="panel">
          <div className="panel-title">
            <h3>
              <IconStar size={15} /> Món bán chạy
            </h3>
            <Link href="/menu">Xem tất cả →</Link>
          </div>
          {products.slice(0, 4).map((product, index) => (
            <div className="popular" key={product.id}>
              <div className="food-thumb">
                <ProductThumb product={product} size={25} />
              </div>
              <div className="popular-info">
                <strong>{product.name}</strong>
                <small>{BESTSELLER_COUNTS[index]} lượt bán hôm nay</small>
              </div>
              <b>{money(product.price)}</b>
            </div>
          ))}
        </section>
      </div>

      <section className="panel">
        <div className="panel-title">
          <h3>Đơn hàng gần đây</h3>
          <Link href="/orders">Xem tất cả →</Link>
        </div>
        <OrdersTable orders={orders.slice(0, 4)} />
      </section>

      <p className="notice">
        * Danh sách đơn hàng, số đơn chờ xử lý và thực đơn là dữ liệu thật từ API. Doanh thu, biểu
        đồ 7 ngày, tổng đơn hàng, tỷ lệ khách quay lại và lượt bán của từng món vẫn là số minh họa:
        các số này thuộc module Báo cáo, chưa được triển khai.
      </p>
    </>
  );
}
