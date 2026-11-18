'use client';

import { useState } from 'react';

import { money } from '@/lib/money';

import { useAdminData } from '../AdminDataProvider';
import { FoodIcon, IconCalendar, IconPlus } from '../icons';
import { PageHeading } from '../primitives';

export function MenuScreen() {
  const { products, openModal, notifyAction } = useAdminData();
  const [query, setQuery] = useState('');
  const [category, setCategory] = useState('all');

  const categories = [...new Set(products.map((product) => product.cat))];

  // Deleting the last item of a category takes the category with it, leaving
  // the filter pointing at something that no longer exists. Fall back to "all"
  // here, before filtering, so the grid and the select cannot disagree: using
  // it for the select alone would show "Tất cả danh mục" over an empty grid.
  const activeCategory = categories.includes(category) ? category : 'all';

  const needle = query.trim().toLocaleLowerCase('vi');
  const visible = products.filter(
    (product) =>
      (activeCategory === 'all' || product.cat === activeCategory) &&
      product.name.toLocaleLowerCase('vi').includes(needle),
  );

  return (
    <>
      <PageHeading
        title="Thực đơn & món ăn"
        description="Cập nhật món bán hôm nay, giá và số suất còn lại."
        actions={
          <>
            <button type="button" className="btn" onClick={() => notifyAction('lịch thực đơn')}>
              <IconCalendar size={15} /> Lịch thực đơn
            </button>
            <button
              type="button"
              className="btn btn-primary"
              onClick={() => openModal({ kind: 'add-product' })}
            >
              <IconPlus size={15} /> Thêm món mới
            </button>
          </>
        }
      />

      <div className="search-row">
        <input
          className="search"
          placeholder="Tìm tên món..."
          aria-label="Tìm tên món"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
        />
        <select
          className="filter"
          aria-label="Lọc theo danh mục"
          value={activeCategory}
          onChange={(event) => setCategory(event.target.value)}
        >
          <option value="all">Tất cả danh mục</option>
          {categories.map((name) => (
            <option key={name} value={name}>
              {name}
            </option>
          ))}
        </select>
      </div>

      <p className="result-count" aria-live="polite">
        {visible.length}/{products.length} món
      </p>

      {visible.length === 0 ? (
        <div className="panel empty">
          <strong>
            {products.length === 0 ? 'Thực đơn chưa có món nào' : 'Không có món nào phù hợp'}
          </strong>
          {products.length === 0
            ? 'Bấm "Thêm món mới" để tạo món đầu tiên.'
            : 'Thử bỏ bộ lọc danh mục hoặc xoá từ khoá tìm kiếm.'}
        </div>
      ) : (
        <div className="product-grid">
          {visible.map((product) => (
            <article className="product-card" key={product.id}>
              <div className="food-thumb">
                <FoodIcon name={product.icon} size={32} />
              </div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <h3>{product.name}</h3>
                <p>{product.cat}</p>
                <b>{money(product.price)}</b>
                <span className={product.stock < 10 ? 'stock low' : 'stock'}>
                  Còn {product.stock}/{product.capacity} suất
                </span>
                <div className="card-actions">
                  <button
                    type="button"
                    className="table-action"
                    onClick={() => openModal({ kind: 'product', productId: product.id })}
                  >
                    Chỉnh sửa →
                  </button>
                  <button
                    type="button"
                    className="table-action danger"
                    onClick={() => openModal({ kind: 'delete-product', productId: product.id })}
                  >
                    Xoá
                  </button>
                </div>
              </div>
            </article>
          ))}
        </div>
      )}
    </>
  );
}
