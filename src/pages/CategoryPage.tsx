import { SlidersHorizontal } from 'lucide-react';
import type { SortKey } from '@/types';
import { useCategoryProducts } from '@/hooks/useCategoryProducts';
import ProductGrid from '@/components/ProductGrid';
import { useState } from 'react';

const categoryInfo: Record<string, { label: string; description: string }> = {
  oled: { label: 'OLED', description: 'Идеальный чёрный, бесконечный контраст. Каждый пиксель светится самостоятельно.' },
  qled: { label: 'QLED', description: 'Квантовые точки для 100% цветового объёма. Яркий и живой цвет.' },
  '8k': { label: '8K', description: '33 миллиона пикселей. Разрешение, опережающее время.' },
  led: { label: 'LED', description: 'Доступные 4K-модели с отличным качеством для любого помещения.' },
};

type Props = {
  categorySlug: string;
};

export default function CategoryPage({ categorySlug }: Props) {
  const [sort, setSort] = useState<SortKey>('default');
  const { products, loading, error } = useCategoryProducts(categorySlug);

  let sorted = products;
  if (sort === 'price-asc') sorted = [...products].sort((a, b) => a.price - b.price);
  if (sort === 'price-desc') sorted = [...products].sort((a, b) => b.price - a.price);
  if (sort === 'rating') sorted = [...products].sort((a, b) => b.rating - a.rating);

  const info = categoryInfo[categorySlug] ?? { label: 'Каталог', description: '' };

  return (
    <section className="pt-24 pb-20 bg-graphite-900 min-h-screen">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-6 mb-10">
          <div>
            <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
              Категория
            </span>
            <h1 className="font-display font-extrabold text-4xl sm:text-5xl text-white mt-2 tracking-tight">
              {info.label} телевизоры
            </h1>
            <p className="text-graphite-400 mt-3 max-w-lg">{info.description}</p>
          </div>

          <div className="flex items-center gap-3">
            <SlidersHorizontal className="w-4 h-4 text-graphite-400" />
            <select
              value={sort}
              onChange={(e) => setSort(e.target.value as SortKey)}
              className="bg-graphite-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2.5 focus:outline-none focus:border-accent-500/50 cursor-pointer"
            >
              <option value="default">По умолчанию</option>
              <option value="price-asc">Сначала дешевле</option>
              <option value="price-desc">Сначала дороже</option>
              <option value="rating">По рейтингу</option>
            </select>
          </div>
        </div>

        <ProductGrid products={sorted} loading={loading} error={error} />
      </div>
    </section>
  );
}
