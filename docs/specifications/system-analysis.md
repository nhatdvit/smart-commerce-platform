# Phân tích hệ thống và lộ trình

Ngày: 2026-11-10. Đối chiếu trạng thái thực tế của repository với `CLAUDE.md`
và `docs/database/DATABASE_SPEC.md`.

Tài liệu này giải thích **còn thiếu gì, theo thứ tự nào, và rủi ro ở đâu**.
Danh sách công việc chi tiết nằm ở GitHub Issues; đây là bản đồ tổng thể.

---

## 1. Hiện trạng

Phase 01 (Foundation) đã xong và được CI xác nhận. Cụ thể:

| Hạng mục | Có | Theo spec | Tỉ lệ |
|---|---|---|---|
| Module nghiệp vụ | Identity, Store | 16 (§5) | 2/16 |
| Bảng dữ liệu | 19 (nền tảng + identity + store) | ~47 (DATABASE_SPEC §2) | ~40% |
| Endpoint nghiệp vụ | 0 | 15 (§8) | 0/15 |
| Test | 39 (4 file) | — | — |

Nền tảng đã có và **tái sử dụng được cho mọi module sau**:

- Phân quyền theo store (`store.resolve` + `store.can`) — hai điều kiện độc lập,
  đã có 10 test bao phủ các đường vòng.
- `Money` — số nguyên đồng, số học chính xác, serialize thành chuỗi.
- `ErrorCode` + một envelope lỗi duy nhất, không lộ stack trace.
- Correlation ID xuyên suốt request và log.
- Validate cấu hình lúc khởi động; container từ chối chạy nếu cấu hình sai.
- Ba bảng hạ tầng: `audit_logs`, `outbox_events`, `idempotency_records`.

> **Điểm cần lưu ý:** ba bảng hạ tầng trên **đã có schema nhưng chưa có code
> dùng tới**. Chúng là tiền đề cho Order và Payment, không phải tính năng đã
> hoàn thành. Việc đầu tiên của Phase 02 là biến chúng thành cơ chế chạy thật.

---

## 2. Thứ tự phụ thuộc

Không thể làm song song tuỳ ý. Mũi tên là "phải có trước":

```text
Auth ──► (mọi thứ cần người dùng đăng nhập)

Catalog ──► Menu ──┐
                   ├──► Cart ──► Pricing ──► Order ──► Payment ──► Fulfillment
Inventory ─────────┘                            ▲
                                                │
        Outbox dispatcher ─ Idempotency ─ Audit ┘   (bắt buộc có trước Order)
```

Ba thứ ở dòng dưới cùng là **điều kiện cần của Order**, không phải việc làm sau:

- **Idempotency** — §6 bắt buộc checkout phải idempotent. Nếu làm Order trước
  rồi mới thêm, mọi đường tạo đơn phải viết lại.
- **Outbox dispatcher** — §6 yêu cầu ghi trạng thái nghiệp vụ và sự kiện trong
  *cùng một transaction*. Thêm sau nghĩa là phải sửa lại toàn bộ transaction đã viết.
- **Audit** — §6 yêu cầu xác nhận thanh toán phải ghi vết. Bổ sung sau sẽ để
  lại khoảng trống lịch sử không thể khôi phục.

---

## 3. Rủi ro xếp theo mức độ

### R1 — Thanh toán chuyển khoản đối soát thủ công (cao nhất)

MVP chốt chỉ `CASH` và `BANK_TRANSFER` với VietQR động và nhân viên xác nhận
tay (§6). Phần sinh QR thì dễ; phần khó là các nhánh ngoại lệ mà §6 liệt kê rõ:
chuyển thiếu, chuyển thừa, sai nội dung, chuyển trùng, chuyển sau khi đơn hết
hạn, huỷ đơn sau khi đã nhận tiền.

Điều nguy hiểm nhất là nhầm lẫn khái niệm: **sinh QR không phải là xác nhận đã
thanh toán**. Ảnh chụp màn hình, việc QR hiện ra, hay khách bấm "tôi đã chuyển"
đều không phải bằng chứng có tiền. Chỉ nhân viên có quyền, sau khi nhìn thấy
tiền vào tài khoản thật, mới được đánh dấu `PAID`.

### R2 — Bán quá số lượng khi có đồng thời

§6 yêu cầu chống overselling bằng transaction PostgreSQL với row lock hoặc
conditional update, **không được chỉ dựa vào Redis lock**. Đây là lỗi chỉ xuất
hiện khi có tải thật, nên phải có test đồng thời ngay từ đầu, không để sau.

### R3 — Cô lập giữa các store

Nền tảng đã đúng, nhưng mỗi endpoint mới là một cơ hội để rò rỉ. Mọi truy vấn
admin phải lọc theo store đã resolve, không tin `store_id` từ client.

### R4 — Giá do server quyết định

§6: giá client gửi lên không bao giờ là căn cứ. Phải tính lại giá, modifier,
lịch menu, tồn kho tại thời điểm checkout.

### R5 — Quan sát hệ thống còn trống

§10 yêu cầu metrics, tracing, Sentry. Hiện chưa có gì ngoài log có cấu trúc.
Chưa cấp bách bằng R1–R4, nhưng khi có sự cố thanh toán mà không có số liệu
thì rất khó truy.

---

## 4. Quyết định nghiệp vụ đang chặn

§15 liệt kê sáu quyết định chưa chốt. Hai trong số đó **chặn trực tiếp** việc
viết code đúng:

| Quyết định | Chặn | Vì sao không thể đoán |
|---|---|---|
| Tài khoản ngân hàng nhận tiền, cửa sổ hết hạn, chính sách ngoại lệ/hoàn tiền | Payment | Sai là mất tiền thật |
| Hình thức giao hàng (pickup / delivery / dine-in) | Fulfillment, phí trong Pricing | Quyết định cấu trúc đơn hàng |
| Chính sách định danh khách vãng lai (OTP hay không) | Cart, Order | Ảnh hưởng luồng checkout |
| Tồn kho theo thành phẩm hay nguyên liệu | Inventory | Khác hẳn mô hình dữ liệu |
| Thuế và phí | Pricing | Ảnh hưởng công thức tổng tiền |
| Một store hay nhiều store khi ra mắt | Store context | Đã để cấu hình được |

Chưa có câu trả lời thì giữ nguyên tắc của §15: **để cấu hình được, không tự
bịa chính sách**. Đó là lý do `config/commerce.php` tồn tại.

---

## 5. Lộ trình

**Phase 02 — MVP** (mục tiêu: khách đặt được hàng, nhân viên xử lý được đơn)

Enablers trước: Idempotency, Outbox dispatcher, Audit, Auth.
Sau đó: Catalog → Menu → Inventory → Cart → Pricing → Order → Payment →
Fulfillment → Customer → Notification.

**Phase 03 — Operations**

Kitchen display, realtime (Reverb), promotions, reporting, tinh chỉnh RBAC.

**Phase 04 — Nền tảng vận hành**

Observability đầy đủ (§10), backup tự động + **kiểm thử khôi phục** (§9),
pipeline release và deploy staging (§9).

**Chưa làm:** AI (§11). Giữ use case gọi được không qua HTTP để sau này dùng lại.

---

## 6. Nguyên tắc không được vi phạm khi làm Phase 02

Trích từ `CLAUDE.md`, nhắc lại vì đây là chỗ dễ sai nhất:

1. Tiền là số nguyên đồng, dùng `Money`. Không bao giờ dùng số thực.
2. Mỗi endpoint admin kiểm tra **cả** quyền **và** membership của store.
3. Trạng thái đơn và trạng thái thanh toán là hai máy trạng thái tách biệt.
   `COMPLETED` không bao giờ đồng nghĩa `PAID`.
4. Ghi trạng thái nghiệp vụ và outbox event trong cùng một `DB::transaction()`.
5. `Domain/` không biết gì về Eloquent, facade hay HTTP — test kiến trúc sẽ chặn.
6. Test chạy trên PostgreSQL thật, không dùng SQLite.
7. Mỗi thay đổi đi kèm test, migration và cập nhật OpenAPI nếu có.
