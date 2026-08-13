import { useState } from 'react';
import { Link } from 'react-router-dom';
import { SlidersHorizontal } from 'lucide-react';
import type { SortKey } from '@/types';
import { useProducts } from '@/hooks/useProducts';
import { useUI } from '@/store/ui';
import ProductGrid from '@/components/ProductGrid';

const categoryTabs = [
  { slug: '', label: 'Все' },
  { slug: 'oled', label: 'OLED' },
  { slug: 'qled', label: 'QLED' },
  { slug: '8k', label: '8K' },
  { slug: 'led', label: 'LED' },
];

export default function CatalogPage() {
  const { searchQuery } = useUI();
  const [activeCat, setActiveCat] = useState('');
  const [sort, setSort] = useState<SortKey>('default');

  const { products, loading, error } = useProducts({
    search: searchQuery || undefined,
    sort,
  });

  const filtered = activeCat
    ? products.filter((p) => p.category.toLowerCase() === activeCat.toUpperCase() || (activeCat === '8k' && p.category === '8K'))
    : products;

  return (
    <section className="pt-24 pb-20 bg-graphite-900 min-h-screen">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-6 mb-10">
          <div>
            <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
              Каталог
            </span>
            <h1 className="font-display font-extrabold text-4xl sm:text-5xl text-white mt-2 tracking-tight">
              Выберите свой TELVORA
            </h1>
            <p className="text-graphite-400 mt-3 max-w-lg">
              От доступных LED-моделей до флагманских 8K. Найдите идеальный телевизор
              под ваш интерьер и бюджет.
            </p>
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

        <div className="flex items-center gap-2 mb-10 overflow-x-auto hide-scrollbar pb-1">
          {categoryTabs.map((cat) => (
            <Link
              key={cat.slug || 'all'}
              to={cat.slug ? `/catalog/${cat.slug}` : '/catalog'}
              onClick={() => setActiveCat(cat.slug)}
              className={`px-5 py-2.5 text-sm font-semibold rounded-xl whitespace-nowrap transition-all ${
                activeCat === cat.slug
                  ? 'bg-white text-graphite-900'
                  : 'bg-white/5 text-graphite-300 hover:bg-white/10 hover:text-white border border-white/10'
              }`}
            >
              {cat.label}
            </Link>
          ))}
        </div>

        <ProductGrid products={filtered} loading={loading} error={error} />
      </div>
    </section>
  );
}
