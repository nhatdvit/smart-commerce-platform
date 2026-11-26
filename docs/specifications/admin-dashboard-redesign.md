# Admin Dashboard Redesign — Phase 1 (Audit) & Phase 2 (Design Proposal)

> Trạng thái: **đã được phê duyệt ngày 2026-11-25 và đã triển khai** trên nhánh
> `feat/SCP-119-admin-dashboard-redesign`. Cả ba quyết định ở §4 đều được chọn theo
> phương án khuyến nghị (A / A / làm). Phần §1 (audit) và §2 (thiết kế) giữ nguyên như
> lúc trình duyệt; những điều chỉnh phát sinh trong lúc làm được ghi ở §7 cuối tài liệu.
> Phạm vi: màn hình `/` (Tổng quan) của `apps/admin-web`, cộng phần chrome dùng chung
> (sidebar + topbar) mà brief §7A/§7B yêu cầu nâng cấp.
> Ngày: 2026-11-25.

---

## 0. Tóm tắt điều hành

Dashboard hiện tại là bản port trung thành của gói thiết kế `breakfast-admin-responsive-v1`.
Nó sạch sẽ nhưng có ba vấn đề lớn:

1. **Một nửa số liệu là giả.** Doanh thu, tổng đơn, tỷ lệ khách quay lại, biểu đồ 7 ngày và
   lượt bán từng món đều là hằng số hardcode trong `src/data/admin-demo.ts`. Màn hình có ghi
   chú thú nhận điều đó ở cuối trang — trung thực, nhưng không dùng được để điều hành.
2. **Có lỗi thật đang hiển thị trên production-like build**: ảnh món vỡ, alt text tràn ra
   ngoài card ở mọi breakpoint, kèm 404 trong console.
3. **Không có bộ lọc thời gian, không có trạng thái loading/empty/error**, và không phân quyền
   hiển thị — một tài khoản bếp (`KITCHEN_STAFF`, không có `payment.read`) vẫn thấy khối doanh thu.

Đề xuất: **redesign toàn bộ dashboard, dùng 100% dữ liệu thật từ các endpoint đã có, không
thêm thư viện, không đổi API contract, không đổi nghiệp vụ.** Những chỉ số không thể suy ra
trung thực (tiền tip, số tiền thực nhận ở mức payment, số tiền hoàn) **không được hiển thị** và
được ghi vào danh sách đề xuất backend ở §5.

---

## 1. PHASE 1 — AUDIT

### 1.1 Stack thực tế (đã kiểm chứng, không phải giả định)

| Hạng mục      | Thực tế trong repo                                                                                                                 |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Framework     | Next.js **15.5.29** App Router, React **19.0.0**, TypeScript **5.7.2** (`strict`)                                                  |
| CSS           | Tailwind **3.4.17** có cấu hình, **nhưng** giao diện admin chạy bằng `src/styles/admin-shell.css` — 2.732 dòng CSS viết tay        |
| shadcn/ui     | **Không có.** `packages/ui` chỉ export đúng một hàm `cn()`. Token shadcn trong `globals.css` tồn tại nhưng không màn hình nào dùng |
| Chart library | **Không có.** Không recharts, không chart.js, không d3                                                                             |
| Icon          | Bộ SVG line tự vẽ trong `components/admin/icons.tsx` — 28 icon, stroke đồng nhất, có `FoodIcon` fallback                           |
| Font          | Be Vietnam Pro, nạp bằng `<link>` trong `app/layout.tsx`, fallback Arial                                                           |
| State         | Một context duy nhất: `AdminDataProvider.tsx` (1.686 dòng)                                                                         |
| Realtime      | Polling `/admin/orders` mỗi **5 giây**, tự dừng khi tab ẩn                                                                         |
| Backend       | Laravel modular monolith, 9 module. **Không có module Reporting**                                                                  |
| CI            | `.github/workflows/ci.yml` → `pnpm lint`, `pnpm typecheck`, `pnpm build`, OpenAPI conformance, pnpm audit, docker build            |

Kết luận quan trọng: **"dùng component hiện có" ở dự án này nghĩa là viết CSS vào
`admin-shell.css` theo đúng token sẵn có, không phải lắp shadcn/ui.** Chuyển dashboard sang
Tailwind utilities sẽ tạo ra hai hệ thống style song song trong cùng một shell.

### 1.2 Dashboard hiện tại — cấu trúc

`DashboardScreen.tsx` (170 dòng) render thẳng 6 khối, không tách component:
PageHeading → welcome banner → 4 `Metric` → bar chart giả + Món bán chạy → Đơn hàng gần đây → ghi chú.

### 1.3 Dữ liệu: thật vs giả

| Khối                               | Nguồn                                                                  | Thật?                                 |
| ---------------------------------- | ---------------------------------------------------------------------- | ------------------------------------- |
| Đơn chờ xử lý                      | `orderCounts.PLACED + awaitingPaymentConfirmationCount` (server đếm)   | ✅ Thật                               |
| Đơn hàng gần đây                   | `GET /admin/orders?sort=newest_first&limit=50`                         | ✅ Thật                               |
| Món bán chạy (tên, giá, ảnh)       | `GET /admin/menus`                                                     | ✅ Thật                               |
| **Doanh thu hôm nay — 1.285.000đ** | hardcode                                                               | ❌ **Giả**                            |
| **Tổng đơn hàng — 48 đơn**         | hardcode                                                               | ❌ **Giả**                            |
| **Khách hàng quay lại — 67%**      | hardcode                                                               | ❌ **Giả**                            |
| **Mọi % "so với hôm qua"**         | hardcode                                                               | ❌ **Giả**                            |
| **Biểu đồ 7 ngày**                 | `REVENUE_CHART = [46,66,58,82,64,93,75]` — chiều cao cột tính bằng `%` | ❌ **Giả, và đúng thứ brief §7F cấm** |
| **"38 lượt bán hôm nay"**          | `BESTSELLER_COUNTS = [38,32,25,21]` gán theo index                     | ❌ **Giả**                            |
| **"8.950.000đ / tuần"**            | hardcode                                                               | ❌ **Giả**                            |

### 1.4 API hiện có (đã đọc routes + Form Requests + controller)

| Endpoint              | Tham số dùng được                                                                                                                                                                                                           | Ghi chú                                                                               |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `GET /admin/orders`   | `status[]`, `q`, `order_code`, `fulfillment_type`, **`placed_from`**, **`placed_to`** (ISO UTC), `sort`, `limit` ≤ **100**, `cursor`                                                                                        | Client hiện **chưa dùng** `placed_from/to`. Đã test trực tiếp: hoạt động.             |
| ↳ response            | `items[]` (có `total`, `payment_status`, `payment_method`, `items[]{product_name, variant_name, quantity}`, `scheduled_at`, `placed_at`), `status_counts`, `has_more`, `next_cursor`, `awaiting_payment_confirmation_count` | `status_counts` là **toàn store, chỉ trạng thái mở**, không theo khoảng thời gian lọc |
| `GET /admin/menus`    | —                                                                                                                                                                                                                           | `available_quantity`, `daily_capacity`, `is_available`, ảnh, giá, danh mục            |
| `GET /admin/payments` | `status[]`, `method`, `needs_attention`, `transfer_reference`, `cursor`, `limit`                                                                                                                                            | **Không có lọc theo ngày. Không có số tổng hợp nào.**                                 |
| `GET /admin/store`    | —                                                                                                                                                                                                                           | trả `timezone` — cần cho mốc ngày theo giờ cửa hàng                                   |
| `GET /me`             | —                                                                                                                                                                                                                           | trả `store_memberships[].role` → **client biết được vai trò**                         |
| Reporting             | —                                                                                                                                                                                                                           | **Không tồn tại.** Permission `report.read` đã được seed sẵn cho OWNER/MANAGER        |

### 1.5 Lỗi và nợ kỹ thuật phát hiện được (có bằng chứng thực tế)

Chạy thật trên `http://admin.localhost:8080` với stack Docker đang chạy:

| #   | Mức độ     | Vấn đề                                                                                                                                                                                                                                                                | Bằng chứng                                          |
| --- | ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| 1   | **Cao**    | **Ảnh món vỡ + alt text tràn layout.** `/assets/food-1.jpg`, `/assets/food-2.jpg` → **404**. `ProductThumb` không có `onError`, không `object-fit`, không khung cố định → chữ alt tiếng Việt tràn ra ngoài panel, chồng lên khối "Đơn hàng gần đây" (rõ nhất ở 390px) | Screenshot 1440px & 390px, network log              |
| 2   | **Cao**    | **2 console error** mỗi lần tải trang (chính là 2 ảnh 404) — vi phạm Definition of Done                                                                                                                                                                               | `read_console_messages`                             |
| 3   | **Cao**    | Số liệu tài chính giả trên màn hình điều hành                                                                                                                                                                                                                         | §1.3                                                |
| 4   | **Cao**    | **Không phân quyền hiển thị.** `KITCHEN_STAFF` không có `payment.read` / `report.read` nhưng vẫn thấy khối doanh thu                                                                                                                                                  | `RolePermissionSeeder.php` vs `DashboardScreen.tsx` |
| 5   | Trung bình | **Không có loading/skeleton/empty/error state.** Khi `session === 'checking'` toàn trang là `<div className="signin-shell" />` trắng trơn                                                                                                                             | `AdminShell.tsx:44`                                 |
| 6   | Trung bình | **Không có bộ lọc thời gian** — brief §7C bắt buộc                                                                                                                                                                                                                    |
| 7   | Trung bình | **Dead control**: nút "7 ngày gần nhất" chỉ gọi `notifyAction('demo')` → hiện toast "demo"                                                                                                                                                                            | `DashboardScreen.tsx`                               |
| 8   | Trung bình | Biểu đồ hardcode chiều cao cột `%`, không trục giá trị, không tooltip — đúng thứ brief §14 cấm                                                                                                                                                                        | `DashboardScreen.tsx`                               |
| 9   | Trung bình | Dashboard gọi `loadOrders(DEFAULT_ORDER_QUERY)` lên **state toàn cục dùng chung**; nếu thêm truy vấn theo kỳ vào đây sẽ phá bộ lọc của màn Đơn hàng và xung đột với polling 5s                                                                                        | `AdminDataProvider.tsx`                             |
| 10  | Thấp       | Sidebar không collapse được; không tooltip khi thu gọn                                                                                                                                                                                                                | `AdminShell.tsx`                                    |
| 11  | Thấp       | Ở ≤760px, pill "Đang nhận đơn" rút còn một chấm xanh **không có nhãn** — màu là dấu hiệu duy nhất (vi phạm WCAG 1.4.1)                                                                                                                                                | Screenshot 390px                                    |
| 12  | Thấp       | Số tiền chưa dùng `font-variant-numeric: tabular-nums`                                                                                                                                                                                                                | `admin-shell.css`                                   |
| 13  | Thấp       | `.metric` không có `:focus-visible`, không phải link/button dù dẫn đến hành động                                                                                                                                                                                      | `admin-shell.css`                                   |
| 14  | Thấp       | Emoji dùng làm icon ở màn Đơn hàng mới (`⚡`, `🕒`) — ngoài phạm vi, chỉ ghi nhận                                                                                                                                                                                     | `NewOrdersScreen.tsx`                               |

**Điểm tốt cần giữ**: không có horizontal overflow toàn trang ở 390px (đã đo
`documentElement.scrollWidth === clientWidth === 390`); bảng đã có `.table-wrap` cuộn cục bộ;
`prefers-reduced-motion` đã được tôn trọng; polling đã tự dừng khi tab ẩn.

### 1.6 Accessibility & performance hiện trạng

- Có: `role="status"` cho toast, một số `aria-label`, `prefers-reduced-motion`.
- Thiếu: landmark/heading hierarchy nhất quán, focus ring hiển thị rõ, mô tả thay thế cho biểu đồ,
  trạng thái chỉ-bằng-màu (#11), target cảm ứng của `.table-action` nhỏ hơn 44px.
- Performance: bundle nhẹ (không chart lib), nhưng mỗi lần poll 5s re-render **toàn bộ** cây
  dashboard vì tất cả đi qua một context duy nhất và `value` được tạo lại.

---

## 2. PHASE 2 — DESIGN PROPOSAL

### 2.1 Nguyên tắc

> **Premium Minimal Commerce Dashboard**: một màn hình trả lời đúng ba câu hỏi theo thứ tự —
> _“Có gì cần xử lý ngay?” → “Hôm nay/kỳ này bán ra sao?” → “Chi tiết ở đâu?”_

- Thông tin cần hành động đặt **trên** thông tin cần chiêm ngưỡng.
- Mỗi con số phải trả lời được: **lấy từ đâu, tính thế nào, trong khoảng thời gian nào**.
- Không có con số nào không truy ngược được về API. Thiếu dữ liệu → **ẩn khối**, không hiển thị `0` giả.
- Giữ nguyên ngôn ngữ thị giác của shell (navy + gold + nền xám lạnh), nâng cấp độ tinh,
  không đổi thương hiệu.

### 2.2 Design tokens

Đề xuất **giữ một bộ token duy nhất ở `:root` trong `admin-shell.css`**, bổ sung các token còn
thiếu và tinh chỉnh nhẹ theo palette brief đưa ra. Các giá trị hiện tại và brief gần như trùng nhau:

| Token               | Hiện tại              | Brief                 | Đề xuất                                                                      |
| ------------------- | --------------------- | --------------------- | ---------------------------------------------------------------------------- |
| `--navy`            | `#162b47`             | `#14243B`             | `#14243B` (đậm hơn ~2%, tăng contrast)                                       |
| `--navy-deep`       | —                     | `#0B172A`             | thêm mới (sidebar active, chart axis)                                        |
| `--yellow`          | `#ffc842`             | `#F5B82E`             | `#F5B82E`                                                                    |
| `--yellow-hover`    | —                     | `#E5A51B`             | thêm mới                                                                     |
| `--bg`              | `#f5f7fb`             | `#F7F8FA`             | giữ `#f5f7fb` (lệch không đáng kể, đổi sẽ khiến mọi màn nhấp nháy khác tông) |
| `--line`            | `#e9edf3`             | `#E5E9F0`             | `#E5E9F0`                                                                    |
| `--ink`             | `#23314a`             | `#17243A`             | `#17243A`                                                                    |
| `--muted`           | `#8290a4`             | `#64748B`             | thêm `--muted-strong: #64748B` cho text phụ, giữ `--muted` cho text mờ       |
| `--green` / `--red` | `#1a9d6c` / `#e25757` | `#059669` / `#DC2626` | `#059669` / `#DC2626` (đạt 4.5:1 trên nền trắng)                             |
| `--amber`           | —                     | `#D97706`             | thêm mới (cảnh báo)                                                          |
| `--blue`            | —                     | `#2563EB`             | thêm mới (thông tin)                                                         |
| `--gold-text`       | `#bc850f` (hardcode)  | —                     | đưa vào token, dùng cho link "Xem tất cả"                                    |

> ⚠️ **Đây là thay đổi cần phê duyệt (§4.1)**: sửa token ở `:root` sẽ làm **mọi màn admin**
> đổi tông nhẹ. Phương án thay thế là bọc token mới trong `.dashboard-scope`, nhưng khi đó hệ
> thống có hai palette — trái với yêu cầu "nhất quán toàn hệ thống" của chính brief.

**Typography** (Be Vietnam Pro, giữ nguyên font):

| Vai trò         | Size / weight                                    |
| --------------- | ------------------------------------------------ |
| Page title      | 28px / 700, mobile 24px                          |
| Section title   | 18px / 700                                       |
| Card title      | 14px / 600                                       |
| Body            | 13px / 400–500                                   |
| Secondary       | 12px / 400                                       |
| KPI value       | 30px / 800, `font-variant-numeric: tabular-nums` |
| Mọi con số tiền | `tabular-nums` bắt buộc                          |

**Spacing**: 4 / 8 / 12 / 16 / 20 / 24 / 32 / 40 / 48 → token `--sp-1..--sp-9`.
**Radius**: `--r-sm 8px`, `--r-md 12px`, `--r-lg 16px`.
**Elevation**: đúng 2 mức — `--shadow-card` (hiện có) và `--shadow-pop` cho dropdown/tooltip.
**Motion**: 150ms (hover/color), 200ms (layout), ease-out; tắt hoàn toàn dưới `prefers-reduced-motion`.

### 2.3 Layout — Desktop ≥1280px

```
┌────────────┬──────────────────────────────────────────────────────────────────────┐
│ SIDEBAR    │ TOPBAR  Quản trị / Tổng quan      ● Đang nhận đơn  🔔  [AD ▾]        │
│ (254px,    ├──────────────────────────────────────────────────────────────────────┤
│  thu gọn   │ Thứ Tư, 25 tháng 11, 2026                                            │
│  →72px)    │ Tổng quan kinh doanh          [Hôm nay ▾] [Xử lý đơn mới] [Thực đơn] │
│            │ Chào buổi sáng, Demo Manager                                          │
│ TỔNG QUAN  ├──────────────────────────────────────────────────────────────────────┤
│  Tổng quan │ ⚠ CẦN XỬ LÝ   2 đơn chờ xác nhận ·  1 giao dịch chờ đối soát ·       │
│  Đơn mới ② │               3 món sắp hết suất          (một dải mảnh, chỉ khi có)  │
│  Đơn hàng  ├────────────┬────────────┬────────────┬────────────────────────────────┤
│  Thực đơn  │ Doanh thu  │ Tổng đơn   │ TB mỗi đơn │ Chờ xác nhận                   │
│  Số suất   │ 1.285.000đ │ 48 đơn     │ 26.770đ    │ 2 đơn                          │
│            │ ↑12,8% ▁▃▅ │ ↑8,2%  ▁▂▄ │ ↓1,1%      │ cần xử lý ngay →               │
│ KINH DOANH ├────────────┴────────────┴────┬───────┴────────────────────────────────┤
│  Thanh toán│ Doanh thu theo ngày          │ Cơ cấu đơn hàng                        │
│  Mã ưu đãi │ ╭──────────── area chart ──╮ │   ╭───╮  Chờ xác nhận      2          │
│  Tích điểm │ │ có trục, lưới, tooltip   │ │   │ ◕ │  Đang chuẩn bị    11          │
│  Khách hàng│ │ so sánh kỳ trước (nét đứt)│ │   ╰───╯  Hoàn thành        3          │
│            │ ╰──────────────────────────╯ │          Đã hủy / hết hạn  1          │
│ HỆ THỐNG   ├──────────────────────────────┴────────────────────────────────────────┤
│  Giao diện │ Thanh toán trong kỳ          │ Món bán chạy                           │
│  Cài đặt   │ Đã thu        980.000đ       │ 1 ▢ Xôi gà xé        Xôi    38 phần    │
│            │  ├ Tiền mặt   520.000đ       │ 2 ▢ Bánh mì pate     Bánh mì 32 phần   │
│ [? Hỗ trợ] │  └ Chuyển khoản 460.000đ     │ 3 ▢ ...                                │
│            │ Chờ thu       305.000đ       │                                        │
│            │  └ 1 đơn khách đã báo CK     │                                        │
│            ├──────────────────────────────┴────────────────────────────────────────┤
│            │ Đơn hàng gần đây                                   [Xem tất cả →]     │
│            │ MÃ ĐƠN │ KHÁCH │ MÓN │ GIỜ NHẬN │ TỔNG │ ĐƠN │ THANH TOÁN │ ⋯        │
│            ├──────────────────────────────────────────────────────────────────────┤
│            │ Khả năng phục vụ hôm nay   (chỉ hiện với cửa hàng có số suất)         │
│            │ ▮ Hết hàng 1 · ▮ Sắp hết 3 · Còn lại 86/120 suất · 2 đơn nhận 11:00  │
└────────────┴──────────────────────────────────────────────────────────────────────┘
```

**Grid**: 12 cột, gap 20px, max-width nội dung 1600px, padding ngang 28px (1440) / 20px (1280).
Hàng chart = 7/5; hàng thanh toán + bán chạy = 5/7.

### 2.4 Layout — Tablet & Mobile

**Tablet 768–1024px**: sidebar thu gọn thành icon-rail 72px (có tooltip), KPI 2×2,
chart full-width, donut xuống dưới chart, bảng đơn hàng cuộn ngang cục bộ.

**Mobile 360–430px**:

```
┌───────────────────────────────┐
│ ☰  Tổng quan      ● 🔔  [AD]  │  topbar 56px, pill có CHỮ không chỉ chấm
├───────────────────────────────┤
│ Thứ Tư, 25 tháng 11           │
│ Tổng quan kinh doanh          │
│ [Hôm nay ▾]        (sticky)   │
├───────────────────────────────┤
│ ⚠ 2 đơn chờ xác nhận      →   │  alert xếp dọc, mỗi dòng là một link
│ ⚠ 3 món sắp hết suất      →   │
├───────────────────────────────┤
│ Doanh thu      │ Tổng đơn     │  KPI 2 cột
│ 1.285.000đ     │ 48 đơn       │
├────────────────┼──────────────┤
│ TB mỗi đơn     │ Chờ xác nhận │
├───────────────────────────────┤
│ Doanh thu theo ngày           │  chart cao 180px, tooltip = tap
├───────────────────────────────┤
│ Cơ cấu đơn → danh sách, bỏ donut dưới 400px
├───────────────────────────────┤
│ Thanh toán trong kỳ           │
├───────────────────────────────┤
│ Món bán chạy (top 5)          │
├───────────────────────────────┤
│ Đơn gần đây → CARD, không bảng│  mỗi đơn một card 3 dòng, nút chi tiết 44px
└───────────────────────────────┘
```

Breakpoints: `1280 / 1024 / 768 / 480`. Sidebar drawer giữ nguyên cơ chế hiện có.

### 2.5 Từng khối — nguồn dữ liệu và công thức

> Ký hiệu: **R** = khoảng thời gian đang chọn, quy đổi sang UTC từ **giờ cửa hàng**
> (`GET /admin/store` → `timezone`, mặc định `Asia/Ho_Chi_Minh`).
> `A` = tập đơn trong R có `status ∈ {PLACED, CONFIRMED, PREPARING, READY, COMPLETED}`
> (đơn đã được nhận — loại `PENDING_PAYMENT` chưa chốt, `CANCELLED`, `EXPIRED`).

| Khối                                      | Công thức                                                                                                                                  | Nguồn                                                                       | Trạng thái                                                                                  |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| **Doanh thu bán hàng**                    | `Σ total(A)`                                                                                                                               | `/admin/orders?placed_from&placed_to`                                       | ✅ thật                                                                                     |
| **Tổng đơn hàng**                         | `count(A)`                                                                                                                                 | ↑                                                                           | ✅ thật                                                                                     |
| **TB mỗi đơn (AOV)**                      | `Σ total(A) / count(A)`, 0 → `—`                                                                                                           | ↑                                                                           | ✅ thật                                                                                     |
| **Chờ xác nhận**                          | `status_counts.PLACED + awaiting_payment_confirmation_count`                                                                               | ↑ (server đếm, **toàn store, thời điểm hiện tại** — nhãn ghi rõ "hiện tại") | ✅ thật                                                                                     |
| **So kỳ trước**                           | lặp lại truy vấn với R′ dài bằng R liền trước; `(now−prev)/prev`                                                                           | ↑                                                                           | ✅ thật, **ẩn trend nếu R′ lỗi hoặc bị cắt trang**                                          |
| **Sparkline KPI**                         | doanh thu/đơn theo từng bucket của R                                                                                                       | ↑                                                                           | ✅ thật                                                                                     |
| **Biểu đồ doanh thu**                     | nhóm `A` theo **ngày giờ cửa hàng** (R = hôm nay → theo giờ)                                                                               | ↑                                                                           | ✅ thật                                                                                     |
| **Cơ cấu đơn hàng**                       | gom toàn bộ đơn trong R (kể cả hủy) theo 5 nhóm `statusTone`                                                                               | ↑                                                                           | ✅ thật                                                                                     |
| **Thanh toán — Đã thu**                   | `Σ total(đơn trong R có payment_status = PAID)`, tách theo `payment_method`                                                                | ↑                                                                           | ✅ thật (mức đơn hàng)                                                                      |
| **Thanh toán — Chờ thu**                  | `Σ total(đơn trong R, không hủy/hết hạn, payment ∈ {PENDING, PROCESSING})`; tách riêng dòng "khách đã báo CK, chờ đối soát" = `PROCESSING` | ↑                                                                           | ✅ thật — **PROCESSING không bao giờ cộng vào "Đã thu"**                                    |
| **Đang/đã hoàn tiền**                     | **chỉ đếm số đơn** ở `PARTIALLY_REFUNDED`/`REFUNDED`, ẩn nếu = 0                                                                           | ↑                                                                           | ⚠️ chỉ có số đơn, **không có số tiền** → §5                                                 |
| **Tiền tip / chuyển thừa / số thực nhận** | —                                                                                                                                          | —                                                                           | ❌ **không hiển thị** (chỉ có ở `payments.confirmed_amount`, không lọc được theo ngày) → §5 |
| **Món bán chạy**                          | `Σ quantity` theo `product_name` từ `items[]` của đơn trong `A`; ghép ảnh/danh mục bằng tên với snapshot `/admin/menus`                    | ↑ + `/admin/menus`                                                          | ✅ số lượng thật; **doanh thu theo món không hiển thị** (summary không có đơn giá) → §5     |
| **Đơn hàng gần đây**                      | 5 đơn mới nhất từ danh sách polling sẵn có; ưu tiên đơn cần xử lý                                                                          | state có sẵn, **0 request thêm**                                            | ✅ thật, mở lại modal chi tiết hiện có                                                      |
| **Khả năng phục vụ**                      | hết hàng / sắp hết (<20% suất đã chuẩn bị) / tổng suất còn lại — dùng đúng ngưỡng `LOW_STOCK_SHARE` của màn Tồn kho                        | `/admin/menus` (đã tải sẵn)                                                 | ✅ thật                                                                                     |
| **Khung giờ nhận sắp tới**                | gom `scheduled_at` của đơn đang mở theo khung 30 phút                                                                                      | state có sẵn                                                                | ✅ thật                                                                                     |
| **Cảnh báo vận hành**                     | 4 nguồn trên, chỉ hiện dòng nào > 0                                                                                                        | —                                                                           | ✅ thật                                                                                     |
| **Khách hàng quay lại**                   | —                                                                                                                                          | —                                                                           | ❌ **bỏ khỏi dashboard** (cần truy vấn toàn lịch sử) → §5                                   |

### 2.6 Kiến trúc frontend

```
src/components/admin/dashboard/
├── DashboardScreen.tsx        # chỉ lắp ráp + layout, < 120 dòng
├── DashboardHeader.tsx        # tiêu đề, ngày, lời chào, quick actions theo quyền
├── DateRangeFilter.tsx        # Hôm nay / Hôm qua / 7 ngày / 30 ngày / Tháng này / Tùy chọn
├── OperationalAlerts.tsx
├── MetricCard.tsx             # 1 KPI: label, value, icon, trend, sparkline, tooltip
├── MetricGrid.tsx
├── RevenueChart.tsx           # SVG area chart tự vẽ
├── OrderStatusChart.tsx       # SVG donut + legend bấm được
├── PaymentOverview.tsx
├── BestSellingProducts.tsx
├── RecentOrders.tsx           # desktop: table | mobile: card
├── ServiceCapacity.tsx
└── states/{DashboardSkeleton,EmptyState,ErrorState}.tsx

src/lib/
├── dashboard-range.ts         # khoảng thời gian ↔ UTC theo timezone cửa hàng
├── dashboard-metrics.ts       # THUẦN TÚY: (orders[]) => metrics. Không fetch, không React
└── dashboard-api.ts           # fetch có phân trang + AbortController + cap

src/hooks/useDashboardMetrics.ts   # state riêng của dashboard
```

**Ba quyết định kiến trúc:**

1. **Không đụng vào `AdminDataProvider` cho số liệu theo kỳ.** Dashboard có fetch riêng
   (`useDashboardMetrics`) nên không phá bộ lọc dùng chung của màn Đơn hàng và không xung đột
   với polling 5s (lỗi #9).
2. **`dashboard-metrics.ts` là hàm thuần**, nhận `AdminOrder[]` trả về số liệu → **test được
   bằng unit test**, và là đúng một chỗ duy nhất phải sửa khi `/admin/reports/overview` ra đời.
3. **Chart tự vẽ bằng SVG** (~180 dòng cho cả area + donut): repo không có chart lib; thêm
   recharts là +180KB gzip cho hai biểu đồ. SVG tự vẽ cho phép dùng đúng token màu, SSR an toàn,
   và gắn `<title>`/bảng dữ liệu ẩn cho screen reader.

**Chiến lược tải dữ liệu**: `limit=100` + `cursor`, tối đa **10 trang (1.000 đơn)/kỳ**. Nếu chạm
trần, hiển thị nhãn _"dựa trên 1.000 đơn gần nhất trong kỳ"_ thay vì im lặng báo sai. Kết quả
cache theo khóa `range` trong `useRef`, đổi kỳ không gọi lại cái đã có. Polling 5s **không**
chạm vào số liệu theo kỳ; chỉ "Đơn hàng gần đây" + cảnh báo là realtime, cập nhật không gây
layout shift (chiều cao cố định).

### 2.7 Trạng thái UX (bắt buộc cho mọi khối)

| Trạng thái              | Xử lý                                                                                                                                                                 |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Loading lần đầu         | Skeleton đúng hình dạng thật (KPI, chart, bảng), không spinner toàn trang                                                                                             |
| Đang đổi kỳ             | Giữ số cũ + mờ 60% + thanh progress mảnh trên cùng → không nhảy layout                                                                                                |
| Empty (kỳ không có đơn) | Minh họa line-art nhẹ + "Chưa có đơn nào trong khoảng này" + nút đổi kỳ                                                                                               |
| Error                   | Thông báo ngắn + nút **Thử lại**, giữ nguyên bộ lọc                                                                                                                   |
| Capped                  | Nhãn giải thích như trên                                                                                                                                              |
| Thiếu quyền             | **Ẩn khối**, không hiện khối rỗng                                                                                                                                     |
| Ảnh lỗi/null/chậm       | Khung vuông cố định + `object-fit: cover` + `loading="lazy"` + `decoding="async"` + **`onError` → fallback `FoodIcon`**, `alt=""` khi đã có tên bên cạnh (sửa lỗi #1) |
| Tên món dài             | `line-clamp: 2` + `title` đầy đủ                                                                                                                                      |
| Tooltip                 | Mô tả cách tính từng KPI, mở được bằng bàn phím                                                                                                                       |

### 2.8 Accessibility (WCAG 2.2 AA)

- `<main>` + `<section aria-labelledby>` cho từng khối, heading đúng cấp h1→h2→h3.
- Biểu đồ: `role="img"` + `aria-label` tóm tắt, kèm `<table class="sr-only">` số liệu đầy đủ.
- Trạng thái **không bao giờ chỉ bằng màu**: luôn kèm chữ và/hoặc icon (sửa lỗi #11).
- Focus ring 2px `--navy` offset 2px trên mọi phần tử tương tác; KPI card là `<a>` thật.
- Target cảm ứng ≥ 44×44px trên mobile.
- Contrast: text phụ chuyển sang `#64748B` (4.6:1), gold chỉ dùng làm nền với chữ navy.
- Tôn trọng `prefers-reduced-motion` (đã có sẵn, mở rộng cho chart).

### 2.9 Performance

- `React.memo` cho từng khối + `useMemo` cho kết quả tổng hợp → polling 5s chỉ re-render
  "Đơn hàng gần đây" (sửa lỗi #9/perf).
- Không thêm dependency nào. Chart SVG, không canvas, không animation chạy liên tục.
- `content-visibility: auto` cho hai khối cuối.
- Ảnh: lazy + kích thước cố định → CLS ≈ 0.
- Mục tiêu đo bằng Lighthouse sau khi xong: LCP < 2.0s, CLS < 0.05, 0 console error.

### 2.10 Phân quyền hiển thị (dùng `GET /me`, không đổi API)

| Khối                                              | OWNER | MANAGER | CASHIER                | KITCHEN             |
| ------------------------------------------------- | ----- | ------- | ---------------------- | ------------------- |
| KPI doanh thu, AOV, biểu đồ doanh thu, Thanh toán | ✅    | ✅      | ✅ (có `payment.read`) | ❌ ẩn               |
| Tổng đơn, Cơ cấu đơn, Đơn gần đây, Cảnh báo       | ✅    | ✅      | ✅                     | ✅                  |
| Món bán chạy                                      | ✅    | ✅      | ✅                     | ✅ (không kèm tiền) |
| Khả năng phục vụ                                  | ✅    | ✅      | ✅                     | ✅                  |
| Quick action "Quản lý thực đơn"                   | ✅    | ✅      | ❌                     | ❌                  |

Client chỉ **ẩn cho gọn**; server vẫn là nơi quyết định (403 nếu gọi sai) — không có thay đổi
nào về authorization.

### 2.11 Khác biệt có chủ đích so với brief

1. **Cơ cấu đơn hàng dùng 5 nhóm, không phải 4.** Brief §7G yêu cầu không bịa thêm trạng thái;
   hệ thống này **thực sự có** `PREPARING` và `READY` và màn Đơn hàng đã hiển thị chúng. Gộp
   chúng vào "Đã xác nhận" sẽ giấu trạng thái thật. Đề xuất dùng đúng 5 nhóm tông màu đã tồn tại
   trong `statusTone()`: Chờ xác nhận · Đang chuẩn bị · Sẵn sàng · Hoàn thành · Đã hủy/Hết hạn.
2. **Bỏ KPI "Khách hàng quay lại"**, thay bằng **"TB mỗi đơn"** (brief §7E cũng liệt kê AOV).
   Tỷ lệ quay lại cần toàn bộ lịch sử khách — không có API, và đó chính là số đang bị bịa.
3. **Không hiển thị tiền tip / số thực nhận / số tiền hoàn** (brief §7H nêu "nếu hệ thống hỗ
   trợ" — hiện chưa tổng hợp được). Ghi vào §5.
4. **Giữ `--bg` hiện tại** thay vì `#F7F8FA` (chênh lệch không nhìn thấy, đổi thì mọi màn hình
   lệch tông so với ảnh thiết kế gốc).

---

## 3. Ngoài phạm vi (sẽ KHÔNG làm nếu không được yêu cầu)

- Không sửa nghiệp vụ đơn hàng / thanh toán / xác nhận chuyển khoản.
- Không đổi route, không đổi API contract, không đổi authentication.
- Không sửa nội dung các màn hình khác (chỉ sidebar/topbar dùng chung được nâng cấp theo §7A/B).
- Không thêm thư viện chart/UI nào.
- Không tạo module Reporting ở backend trong lần này.

---

## 4. Cần phê duyệt trước khi sang Phase 3

**4.1 — Token màu ở `:root`.** Đề xuất: **sửa ở `:root`** (một hệ thống duy nhất), chấp nhận mọi
màn admin đổi tông nhẹ (navy/gold/line/green/red đậm hơn chút, contrast tốt hơn).
_Phương án B:_ chỉ đổi trong phạm vi dashboard → hai palette song song. **Khuyến nghị: A.**

**4.2 — Cách lấy số liệu.** Đề xuất: **tổng hợp phía client** từ `/admin/orders` (có sẵn
`placed_from/placed_to`), đóng gói trong một hàm thuần duy nhất để sau này thay bằng endpoint
báo cáo mà không phải sửa component. _Phương án B:_ làm `GET /admin/reports/overview` ở backend
trước (đúng kiến trúc hơn — module Reporting + quyền `report.read` đã có trong thiết kế — nhưng
là một task riêng: module mới + OpenAPI + Pest test). **Khuyến nghị: A bây giờ, B là việc tiếp theo.**

**4.3 — Nâng cấp sidebar/topbar dùng chung** (collapse + tooltip + pill có nhãn) sẽ hiển thị trên
**mọi** màn hình admin, dù nội dung các màn khác không đổi. **Khuyến nghị: làm**, vì brief §7A/§7B
yêu cầu trực tiếp.

---

## 5. Đề xuất backend (ghi nhận, không tự làm)

| #   | Đề xuất                                                                                                                                                                 | Lý do                                                                      | Ưu tiên                                                                   |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| 1   | `GET /api/v1/admin/reports/overview?from&to` — tổng hợp phía server theo giờ cửa hàng: doanh thu, số đơn, AOV, chuỗi theo ngày, cơ cấu trạng thái, thu theo phương thức | Bỏ hẳn việc tổng hợp tài chính ở frontend; bỏ trần 1.000 đơn               | Cao                                                                       |
| 2   | `GET /api/v1/admin/reports/bestsellers?from&to` — kèm `product_id` và doanh thu theo món                                                                                | Hiện phải ghép theo **tên món**, và không có đơn giá trong summary         | Cao                                                                       |
| 3   | Thêm `product_id` vào `items[]` của `OrderSummaryResource` (bổ sung, tương thích ngược)                                                                                 | Bỏ ghép theo tên                                                           | Trung bình                                                                |
| 4   | Thêm lọc theo ngày + số tổng hợp cho `GET /admin/payments` (`confirmed_from/to`, tổng `confirmed_amount`, tổng chênh lệch, tổng hoàn)                                   | Mới hiển thị được **số thực nhận**, tip/chuyển thừa, tiền hoàn — brief §7H | Trung bình                                                                |
| 5   | `GET /admin/payments` trả `needs_attention_count`                                                                                                                       | Cảnh báo "giao dịch chờ đối soát" đang phải suy ra gián tiếp               | Thấp                                                                      |
| 6   | Chỉ số khách quay lại trong Reporting                                                                                                                                   | KPI brief §7E muốn, hiện không có nguồn                                    | Thấp                                                                      |
| 7   | Seed/chuẩn hóa ảnh demo: `/assets/food-*.jpg` đang 404                                                                                                                  | Gây console error và ảnh vỡ                                                | Cao (sẽ được che bằng fallback ở frontend, nhưng gốc vẫn là dữ liệu seed) |

---

## 6. Kế hoạch Phase 3 → Phase 5

Nhánh mới cắt từ `origin/main` theo CLAUDE.md §13 (**không** stack lên
`fix/SCP-118-...` hiện tại): `feat/SCP-119-admin-dashboard-redesign`.

| Bước | Nội dung                                                                                                                                                                          |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 3.1  | Token + nền tảng CSS (`:root`, tiện ích skeleton/sr-only/tabular-nums)                                                                                                            |
| 3.2  | `dashboard-range.ts` + `dashboard-metrics.ts` + unit test cho công thức                                                                                                           |
| 3.3  | `useDashboardMetrics` + `dashboard-api.ts` (phân trang, abort, cap)                                                                                                               |
| 3.4  | Các component trình bày + CSS                                                                                                                                                     |
| 3.5  | Sửa `ProductThumb` (onError/object-fit/aspect-ratio) — ảnh hưởng mọi màn, chỉ sửa theo hướng an toàn hơn                                                                          |
| 3.6  | Sidebar collapse + topbar                                                                                                                                                         |
| 4    | QA: `pnpm lint`, `pnpm typecheck`, `pnpm build`; chụp màn hình 1920/1440/1366/1024/768/390/360; kiểm tra console; kiểm tra bàn phím; đổi vai trò đăng nhập để kiểm tra phân quyền |
| 5    | Tự review (UI/UX + frontend), sửa, rồi báo cáo: file đã đổi, lệnh đã chạy, kết quả, vấn đề còn lại                                                                                |

**Không claim bất kỳ kết quả kiểm thử nào nếu chưa chạy thật.**

---

## 7. Phát sinh trong lúc triển khai (cập nhật 2026-11-26)

Ba điểm khác với bản thiết kế đã duyệt, đều do thực tế mã nguồn chứ không phải đổi ý:

1. **Cảnh báo "chuyển khoản chờ đối soát" lấy từ số liệu của kỳ đang xem**, không phải
   từ `awaiting_payment_confirmation_count`. Trường đó chỉ tồn tại trên nhánh
   `fix/SCP-118-...` chưa merge vào `main`; nhánh này cắt từ `main` theo đúng quy tắc
   §13 CLAUDE.md nên không có nó. Số đếm vẫn là số thật (các đơn có `payment.status =
PROCESSING`), chỉ giới hạn trong khoảng thời gian đang chọn.
2. **Bổ sung dropdown tài khoản ở topbar** (brief §7B yêu cầu, bản thiết kế §2.3 chỉ vẽ
   avatar). Nó cũng giải phóng chỗ trên topbar ở màn hình nhỏ, nơi nút "Đăng xuất" dạng
   chữ làm vỡ hàng ở 390px.
3. **Ba sửa lỗi chung cho cả shell** ngoài phạm vi dashboard, vì chúng hiện ra ngay trên
   dashboard: `ProductThumb` có fallback khi ảnh lỗi, `.live-pill` lấy lại nhãn chữ ở
   màn hình nhỏ, và breadcrumb không còn xuống dòng ở 768px.

### Vẫn còn tồn tại

- **404 ảnh món trong console.** Dữ liệu demo đang lưu `image_url = /assets/food-N.jpg`,
  là đường dẫn chỉ tồn tại trong `apps/customer-web/public`; trên host admin nó không
  resolve được. Giao diện đã xử lý đúng (hiện icon thay thế, không vỡ layout) nhưng
  request vẫn 404. Khắc phục tận gốc thuộc về dữ liệu seed hoặc một route `/assets` trên
  host admin — xem đề xuất backend số 7.
- **"Đơn đặt trước ngày mai"** (brief §7K) mới chỉ hiện dưới dạng "khung giờ nhận sắp
  tới", lấy từ danh sách đơn đang mở mà shell đã tải (tối đa 50 đơn mới nhất). Một con số
  đầy đủ cần endpoint lọc theo `scheduled_at` — chưa có.
- **Không có unit test cho `dashboard-metrics.ts`.** Workspace frontend chưa có test
  runner nào (CI chỉ chạy lint, typecheck, build); thêm Vitest là một quyết định hạ tầng
  riêng, không gộp vào PR giao diện này. Hàm đã được viết thuần và tách riêng đúng để
  test được ngay khi có runner.
