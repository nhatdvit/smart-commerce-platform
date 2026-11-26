'use client';

import { useEffect, useMemo } from 'react';

import { useDashboardMetrics } from '@/hooks/useDashboardMetrics';
import { useIdentity } from '@/hooks/useIdentity';
import { comparisonLabel } from '@/lib/dashboard-range';
import { trend } from '@/lib/dashboard-metrics';
import { isLowStock, isSoldOut } from '@/lib/menu-api';
import { money } from '@/lib/money';
import { canSeeMoney } from '@/lib/session-api';
import { DEFAULT_ORDER_QUERY, type AdminOrder } from '@/lib/orders-api';

import { useAdminData } from '../AdminDataProvider';
import {
  IconAlert,
  IconBell,
  IconCart,
  IconClock,
  IconReceipt,
  IconTrendUp,
  IconWallet,
  IconXCircle,
} from '../icons';
import { BestSellingProducts } from '../dashboard/BestSellingProducts';
import { DashboardHeader } from '../dashboard/DashboardHeader';
import { MetricCard } from '../dashboard/MetricCard';
import { OperationalAlerts, type OperationalAlert } from '../dashboard/OperationalAlerts';
import { OrderStatusChart } from '../dashboard/OrderStatusChart';
import { PaymentOverview } from '../dashboard/PaymentOverview';
import { RecentOrders } from '../dashboard/RecentOrders';
import { RevenueChart } from '../dashboard/RevenueChart';
import { ServiceCapacity, type PickupSlot } from '../dashboard/ServiceCapacity';
import { DashboardEmptyState, DashboardErrorState, DashboardSkeleton } from '../dashboard/states';

/** How many of the live queue's orders the table shows. */
const RECENT_LIMIT = 6;

/** Orders arriving within this window are "sắp đến giờ nhận". */
const SOON_MS = 30 * 60 * 1000;

const SLOT_MS = 30 * 60 * 1000;

/** Statuses that still have a pickup ahead of them. */
function isOpen(order: AdminOrder): boolean {
  return order.status !== 'COMPLETED' && order.status !== 'CANCELLED' && order.status !== 'EXPIRED';
}

function clockLabel(at: number): string {
  return new Date(at).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
}

/**
 * The next busy pickup windows, from the orders already in hand.
 *
 * Half-hour buckets, because that is how a counter plans: "three orders at
 * half past eight" is actionable, a list of seventeen exact minutes is not.
 */
function pickupSlots(orders: readonly AdminOrder[], now: number): readonly PickupSlot[] {
  const counts = new Map<number, number>();

  for (const order of orders) {
    if (!isOpen(order) || order.scheduledAt === null) {
      continue;
    }

    const at = new Date(order.scheduledAt).getTime();

    if (Number.isNaN(at) || at < now) {
      continue;
    }

    const slot = Math.floor(at / SLOT_MS) * SLOT_MS;
    counts.set(slot, (counts.get(slot) ?? 0) + 1);
  }

  return [...counts.entries()]
    .sort(([a], [b]) => a - b)
    .slice(0, 3)
    .map(([slot, count]) => ({
      key: String(slot),
      label: `${clockLabel(slot)} – ${clockLabel(slot + SLOT_MS)}`,
      count,
    }));
}

export function DashboardScreen() {
  const { orders, products, pendingCount, newOrderIds, openModal, loadOrders } = useAdminData();

  const identity = useIdentity();
  const dashboard = useDashboardMetrics();
  const { metrics, previous, range } = dashboard;

  // The live table shows the orders that just came in, whatever the orders
  // screen was last filtered to. Asking for the default list is how it says
  // so; an identical request is not re-sent.
  useEffect(() => {
    loadOrders(DEFAULT_ORDER_QUERY);
  }, [loadOrders]);

  const showMoney = identity !== null && canSeeMoney(identity.role);

  const slots = useMemo(() => pickupSlots(orders, Date.now()), [orders]);

  const awaitingReconciliation = metrics?.payment.awaitingReconciliationCount ?? 0;

  const alerts = useMemo<readonly OperationalAlert[]>(() => {
    const soldOut = products.filter(isSoldOut).length;
    const low = products.filter(isLowStock).length;
    const now = Date.now();
    const soon = orders.filter(
      (order) =>
        isOpen(order) &&
        order.scheduledAt !== null &&
        new Date(order.scheduledAt).getTime() - now <= SOON_MS &&
        new Date(order.scheduledAt).getTime() >= now,
    ).length;

    const list: OperationalAlert[] = [];

    if (pendingCount > 0) {
      list.push({
        key: 'pending',
        tone: 'danger',
        icon: <IconBell size={15} />,
        count: pendingCount,
        label: 'đơn chờ xác nhận',
        href: '/orders/new',
      });
    }

    /*
     * Transfers the customer says they have made, from the window on screen.
     * A payment in `PROCESSING` is waiting on a person either way, so the
     * count is true whatever the range - it is simply blind to orders placed
     * outside it, which is why the figure is also spelled out in the payment
     * panel rather than only here.
     */
    if (showMoney && awaitingReconciliation > 0) {
      list.push({
        key: 'reconcile',
        tone: 'warn',
        icon: <IconWallet size={15} />,
        count: awaitingReconciliation,
        label: 'chuyển khoản chờ đối soát',
        href: '/payments',
      });
    }

    if (soon > 0) {
      list.push({
        key: 'soon',
        tone: 'warn',
        icon: <IconClock size={15} />,
        count: soon,
        label: 'đơn sắp đến giờ nhận',
        href: '/orders',
      });
    }

    if (soldOut > 0) {
      list.push({
        key: 'sold-out',
        tone: 'danger',
        icon: <IconXCircle size={15} />,
        count: soldOut,
        label: 'món đã hết hàng',
        href: '/inventory',
      });
    }

    if (low > 0) {
      list.push({
        key: 'low',
        tone: 'info',
        icon: <IconAlert size={15} />,
        count: low,
        label: 'món sắp hết suất',
        href: '/inventory',
      });
    }

    return list;
  }, [products, orders, pendingCount, awaitingReconciliation, showMoney]);

  const booting = metrics === null && dashboard.status === 'loading';
  const failed = metrics === null && dashboard.status === 'error';
  const empty = metrics !== null && metrics.receivedCount === 0;
  const comparison = comparisonLabel(range.preset);

  return (
    <div className="dashboard">
      <DashboardHeader
        identity={identity}
        pendingCount={pendingCount}
        range={range}
        onPreset={dashboard.selectPreset}
        onCustom={dashboard.selectCustom}
      />

      <OperationalAlerts alerts={alerts} />

      {dashboard.error === '' || metrics === null ? null : (
        <p className="dash-warning" role="status">
          Số liệu có thể chưa mới nhất: {dashboard.error}
        </p>
      )}

      {dashboard.capped ? (
        <p className="dash-warning" role="status">
          Kỳ này có rất nhiều đơn. Số liệu được tính trên 1.000 đơn gần nhất trong khoảng đã chọn.
        </p>
      ) : null}

      {failed ? (
        <DashboardErrorState message={dashboard.error} onRetry={dashboard.reload} />
      ) : booting ? (
        <DashboardSkeleton />
      ) : metrics === null ? null : (
        <div className={dashboard.status === 'loading' ? 'dash-body is-busy' : 'dash-body'}>
          {dashboard.status === 'loading' ? (
            <span className="dash-progress" role="status" aria-label="Đang tải số liệu" />
          ) : null}

          <section className="kpi-grid" aria-label="Chỉ số chính">
            {showMoney ? (
              <MetricCard
                label="Doanh thu bán hàng"
                value={money(metrics.revenue)}
                icon={<IconTrendUp size={17} />}
                tone="gold"
                hint="Tổng tiền của các đơn đã được tiếp nhận trong kỳ (không tính đơn đã hủy, hết hạn và đơn chưa chuyển khoản). Không bao gồm tiền tip hay tiền khách chuyển thừa."
                trend={trend(metrics.revenue, previous?.revenue ?? null)}
                trendLabel={comparison}
                foot="Chưa có kỳ trước để so sánh"
                spark={metrics.series.map((point) => point.revenue)}
              />
            ) : null}

            <MetricCard
              label="Tổng đơn hàng"
              value={`${metrics.orderCount} đơn`}
              icon={<IconReceipt size={17} />}
              tone="blue"
              hint="Số đơn đã được tiếp nhận trong kỳ: đã đặt, đã xác nhận, đang chuẩn bị, sẵn sàng hoặc hoàn thành."
              trend={trend(metrics.orderCount, previous?.orderCount ?? null)}
              trendLabel={comparison}
              foot="Chưa có kỳ trước để so sánh"
              spark={metrics.series.map((point) => point.orders)}
            />

            {showMoney ? (
              <MetricCard
                label="Giá trị đơn trung bình"
                value={metrics.averageOrderValue === null ? '—' : money(metrics.averageOrderValue)}
                icon={<IconCart size={17} />}
                tone="teal"
                hint="Doanh thu bán hàng chia cho số đơn đã tiếp nhận trong kỳ."
                trend={trend(metrics.averageOrderValue ?? 0, previous?.averageOrderValue ?? null)}
                trendLabel={comparison}
                foot="Chưa có kỳ trước để so sánh"
              />
            ) : null}

            <MetricCard
              label="Đơn chờ xác nhận"
              value={`${pendingCount} đơn`}
              icon={<IconClock size={17} />}
              tone="navy"
              hint="Đơn đang chờ nhân viên xác nhận ngay lúc này, tính trên toàn cửa hàng - không phụ thuộc khoảng thời gian đang chọn."
              trend={null}
              foot="Số liệu hiện tại, không theo kỳ"
              action={{ href: '/orders/new', label: 'Xử lý ngay' }}
            />
          </section>

          {empty ? (
            <DashboardEmptyState rangeLabel={range.label} />
          ) : (
            <>
              <div className="dash-row dash-row-chart">
                {showMoney ? (
                  <RevenueChart
                    points={metrics.series}
                    total={metrics.revenue}
                    previousTotal={previous?.revenue ?? null}
                    comparisonLabel={comparison}
                    rangeLabel={range.label}
                  />
                ) : null}
                <OrderStatusChart
                  slices={metrics.statusMix}
                  total={metrics.receivedCount}
                  rangeLabel={range.label}
                />
              </div>

              <div className="dash-row dash-row-split">
                {showMoney ? (
                  <PaymentOverview payment={metrics.payment} rangeLabel={range.label} />
                ) : null}
                <BestSellingProducts
                  items={metrics.bestsellers}
                  products={products}
                  rangeLabel={range.label}
                  canSeePrices={showMoney}
                />
              </div>
            </>
          )}
        </div>
      )}

      <RecentOrders
        orders={orders.slice(0, RECENT_LIMIT)}
        newOrderIds={newOrderIds}
        onOpen={(orderId) => openModal({ kind: 'order', orderId })}
        canSeeMoney={showMoney}
      />

      <ServiceCapacity products={products} slots={slots} />
    </div>
  );
}
