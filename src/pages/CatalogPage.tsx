import { useState } from 'react';
import { Link } from 'react-router-dom';
import { SlidersHorizontal, X } from 'lucide-react';
import type { Product, SortKey } from '@/types';
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

const screenSizes = ['50"', '55"', '65"', '75"', '77"', '85"'];
const resolutions = ['4K Ultra HD', '8K Ultra HD'];
const refreshRates = ['60 Гц', '120 Гц', '144 Гц'];
const ratings = [4.5, 4.7, 4.9];

export default function CatalogPage() {
  const { searchQuery } = useUI();
  const [activeCat, setActiveCat] = useState('');
  const [sort, setSort] = useState<SortKey>('default');
  const [filtersOpen, setFiltersOpen] = useState(false);

  const [minPrice, setMinPrice] = useState('');
  const [maxPrice, setMaxPrice] = useState('');
  const [selectedSizes, setSelectedSizes] = useState<string[]>([]);
  const [selectedResolutions, setSelectedResolutions] = useState<string[]>([]);
  const [selectedRates, setSelectedRates] = useState<string[]>([]);
  const [minRating, setMinRating] = useState<number | null>(null);

  const { products, loading, error } = useProducts({
    search: searchQuery || undefined,
    sort,
  });

  const toggle = (
    value: string,
    values: string[],
    setter: React.Dispatch<React.SetStateAction<string[]>>
  ) => {
    setter(
      values.includes(value)
        ? values.filter((item) => item !== value)
        : [...values, value]
    );
  };

  const resetFilters = () => {
    setMinPrice('');
    setMaxPrice('');
    setSelectedSizes([]);
    setSelectedResolutions([]);
    setSelectedRates([]);
    setMinRating(null);
  };

console.log('TELVORA PRODUCTS:', products);
  const filtered = products.filter((product: Product) => {
    const categoryMatch =
      !activeCat ||
      product.category.toLowerCase() === activeCat ||
      (activeCat === '8k' && product.category === '8K');

    const minPriceMatch =
      !minPrice || product.price >= Number(minPrice);

    const maxPriceMatch =
      !maxPrice || product.price <= Number(maxPrice);

    const sizeMatch =
      selectedSizes.length === 0 ||
      selectedSizes.includes(product.screenSize);

    const resolutionMatch =
      selectedResolutions.length === 0 ||
      selectedResolutions.includes(product.resolution);

    const rateMatch =
      selectedRates.length === 0 ||
      selectedRates.some((rate) => {
        const hz = rate.split(' ')[0];
        return product.specs.some(
          (spec) =>
            spec.label.includes('Частота') &&
            spec.value.includes(hz)
        );
      });

    const ratingMatch =
      minRating === null || product.rating >= minRating;

    return (
      categoryMatch &&
      minPriceMatch &&
      maxPriceMatch &&
      sizeMatch &&
      resolutionMatch &&
      rateMatch &&
      ratingMatch
    );
  });

  const filtersCount =
    selectedSizes.length +
    selectedResolutions.length +
    selectedRates.length +
    (minPrice ? 1 : 0) +
    (maxPrice ? 1 : 0) +
    (minRating !== null ? 1 : 0);

  return (
    <section className="pt-24 pb-20 bg-white dark:bg-graphite-900 min-h-screen">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">

        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-6 mb-8">
          <div>
            <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
              Каталог
            </span>

            <h1 className="font-display font-extrabold text-4xl sm:text-5xl text-white mt-2 tracking-tight">
              Выберите свой TELVORA
            </h1>

            <p className="text-graphite-400 mt-3 max-w-lg">
              От доступных LED-моделей до флагманских 8K. Найдите идеальный телевизор под ваш интерьер и бюджет.
            </p>
          </div>

          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => setFiltersOpen(true)}
              className="flex items-center gap-2 bg-white/5 border border-white/10 text-white text-sm rounded-xl px-4 py-2.5 hover:bg-white/10 transition-colors"
            >
              <SlidersHorizontal className="w-4 h-4" />
              Фильтры

              {filtersCount > 0 && (
                <span className="w-5 h-5 rounded-full bg-accent-500 text-white text-xs font-bold flex items-center justify-center">
                  {filtersCount}
                </span>
              )}
            </button>

            <select
              value={sort}
              onChange={(e) => setSort(e.target.value as SortKey)}
              className="bg-graphite-100 dark:bg-graphite-800 border border-white/10 text-white text-sm rounded-xl px-4 py-2.5 focus:outline-none focus:border-accent-500/50 cursor-pointer"
            >
              <option value="default">По умолчанию</option>
              <option value="price-asc">Сначала дешевле</option>
              <option value="price-desc">Сначала дороже</option>
              <option value="rating">По рейтингу</option>
            </select>
          </div>
        </div>

        <div className="flex items-center gap-2 mb-8 overflow-x-auto hide-scrollbar pb-1">
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

        {filtersCount > 0 && (
          <div className="flex items-center gap-4 mb-8">
            <span className="text-sm text-graphite-400">
              Найдено товаров: {filtered.length}
            </span>

            <button
              type="button"
              onClick={resetFilters}
              className="text-sm text-accent-500 hover:text-accent-400"
            >
              Сбросить фильтры
            </button>
          </div>
        )}

        <ProductGrid
          products={filtered}
          loading={loading}
          error={error}
        />

        {filtersOpen && (
          <div className="fixed inset-0 z-[70]">
            <div
              className="absolute inset-0 bg-black/70 backdrop-blur-sm"
              onClick={() => setFiltersOpen(false)}
            />

            <div className="absolute right-0 top-0 bottom-0 w-full sm:w-[420px] bg-graphite-100 dark:bg-graphite-800 border-l border-white/10 overflow-y-auto">

              <div className="sticky top-0 z-10 bg-graphite-100 dark:bg-graphite-800 border-b border-white/10 p-5 flex items-center justify-between">
                <div>
                  <h2 className="text-xl font-bold text-white">
                    Фильтры
                  </h2>

                  <p className="text-sm text-graphite-400 mt-1">
                    Найдено: {filtered.length}
                  </p>
                </div>

                <button
                  type="button"
                  onClick={() => setFiltersOpen(false)}
                  className="p-2 text-graphite-300 hover:text-white hover:bg-white/10 rounded-lg"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <div className="p-5 space-y-8">

                <div>
                  <h3 className="text-sm font-semibold text-white mb-3">
                    Цена, ₽
                  </h3>

                  <div className="grid grid-cols-2 gap-3">
                    <input
                      type="number"
                      placeholder="От"
                      value={minPrice}
                      onChange={(e) => setMinPrice(e.target.value)}
                      className="w-full bg-white/5 border border-white/10 rounded-xl px-3 py-2.5 text-white placeholder:text-graphite-500 outline-none"
                    />

                    <input
                      type="number"
                      placeholder="До"
                      value={maxPrice}
                      onChange={(e) => setMaxPrice(e.target.value)}
                      className="w-full bg-white/5 border border-white/10 rounded-xl px-3 py-2.5 text-white placeholder:text-graphite-500 outline-none"
                    />
                  </div>
                </div>

                <div>
                  <h3 className="text-sm font-semibold text-white mb-3">
                    Диагональ
                  </h3>

                  <div className="grid grid-cols-3 gap-2">
                    {screenSizes.map((size) => (
                      <button
                        key={size}
                        type="button"
                        onClick={() =>
                          toggle(size, selectedSizes, setSelectedSizes)
                        }
                        className={`px-3 py-2.5 rounded-xl text-sm border ${
                          selectedSizes.includes(size)
                            ? 'bg-white text-graphite-900 border-white'
                            : 'bg-white/5 text-graphite-300 border-white/10'
                        }`}
                      >
                        {size}
                      </button>
                    ))}
                  </div>
                </div>

                <div>
                  <h3 className="text-sm font-semibold text-white mb-3">
                    Разрешение
                  </h3>

                  <div className="grid grid-cols-2 gap-2">
                    {resolutions.map((resolution) => (
                      <button
                        key={resolution}
                        type="button"
                        onClick={() =>
                          toggle(
                            resolution,
                            selectedResolutions,
                            setSelectedResolutions
                          )
                        }
                        className={`px-3 py-2.5 rounded-xl text-sm border ${
                          selectedResolutions.includes(resolution)
                            ? 'bg-white text-graphite-900 border-white'
                            : 'bg-white/5 text-graphite-300 border-white/10'
                        }`}
                      >
                        {resolution.replace(' Ultra HD', '')}
                      </button>
                    ))}
                  </div>
                </div>

                <div>
                  <h3 className="text-sm font-semibold text-white mb-3">
                    Частота обновления
                  </h3>

                  <div className="grid grid-cols-3 gap-2">
                    {refreshRates.map((rate) => (
                      <button
                        key={rate}
                        type="button"
                        onClick={() =>
                          toggle(rate, selectedRates, setSelectedRates)
                        }
                        className={`px-3 py-2.5 rounded-xl text-sm border ${
                          selectedRates.includes(rate)
                            ? 'bg-white text-graphite-900 border-white'
                            : 'bg-white/5 text-graphite-300 border-white/10'
                        }`}
                      >
                        {rate}
                      </button>
                    ))}
                  </div>
                </div>

                <div>
                  <h3 className="text-sm font-semibold text-white mb-3">
                    Рейтинг
                  </h3>

                  <div className="grid grid-cols-3 gap-2">
                    {ratings.map((rating) => (
                      <button
                        key={rating}
                        type="button"
                        onClick={() =>
                          setMinRating(
                            minRating === rating ? null : rating
                          )
                        }
                        className={`px-3 py-2.5 rounded-xl text-sm border ${
                          minRating === rating
                            ? 'bg-white text-graphite-900 border-white'
                            : 'bg-white/5 text-graphite-300 border-white/10'
                        }`}
                      >
                        ★ {rating}+
                      </button>
                    ))}
                  </div>
                </div>

                <div className="flex gap-3 pb-6">
                  <button
                    type="button"
                    onClick={resetFilters}
                    className="flex-1 px-4 py-3 rounded-xl border border-white/10 text-white hover:bg-white/10"
                  >
                    Сбросить
                  </button>

                  <button
                    type="button"
                    onClick={() => setFiltersOpen(false)}
                    className="flex-1 px-4 py-3 rounded-xl bg-white text-graphite-900 font-semibold"
                  >
                    Показать {filtered.length}
                  </button>
                </div>

              </div>
            </div>
          </div>
        )}
      </div>
    </section>
  );
}