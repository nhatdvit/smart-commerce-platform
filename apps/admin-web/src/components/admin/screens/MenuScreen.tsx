'use client';

import { useState } from 'react';

import { money } from '@/lib/money';

import { useAdminData } from '../AdminDataProvider';
import { PageHeading } from '../primitives';

export function MenuScreen() {
  const { products, openModal, notifyAction } = useAdminData();
  const [query, setQuery] = useState('');
  const [category, setCategory] = useState('all');

  const categories = [...new Set(products.map((product) => product.cat))];
  const needle = query.toLowerCase();
  const visible = products.filter(
    (product) =>
      (category === 'all' || product.cat === category) &&
      product.name.toLowerCase().includes(needle),
  );

  return (
    <>
      <PageHeading
        title="Thực đơn & món ăn"
        description="Cập nhật món bán hôm nay, giá và số suất còn lại."
        actions={
          <>
            <button type="button" className="btn" onClick={() => notifyAction('schedule')}>
              ◷ Lịch thực đơn
            </button>
            <button
              type="button"
              className="btn btn-primary"
              onClick={() => openModal({ kind: 'add-product' })}
            >
              ＋ Thêm món mới
            </button>
          </>
        }
      />

      <div className="search-row">
        <input
          className="search"
          placeholder="Tìm tên món..."
          value={query}
          onChange={(event) => setQuery(event.target.value)}
        />
        <select
          className="filter"
          value={category}
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

      <div className="product-grid">
        {visible.map((product) => (
          <article className="product-card" key={product.id}>
            <div className="food-thumb">{product.emoji}</div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <h3>{product.name}</h3>
              <p>{product.cat}</p>
              <b>{money(product.price)}</b>
              <span className={product.stock < 10 ? 'stock low' : 'stock'}>
                Còn {product.stock}/{product.capacity} suất
              </span>
              <button
                type="button"
                className="table-action"
                style={{ marginTop: 10, padding: 0 }}
                onClick={() => openModal({ kind: 'product', productId: product.id })}
              >
                Chỉnh sửa →
              </button>
            </div>
          </article>
        ))}
      </div>
    </>
  );
}
