import Hero from '@/components/Hero';
import TechSection from '@/components/TechSection';
import { useProducts } from '@/hooks/useProducts';
import ProductGrid from '@/components/ProductGrid';
import { Link } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';

export default function HomePage() {
  const { products, loading, error } = useProducts();
  const featured = products
    .filter((product) => product.homepage_position !== null && product.homepage_position !== undefined)
    .sort((a, b) => (a.homepage_position as number) - (b.homepage_position as number))
    .slice(0, 9);

  return (
    <>
      <Hero />

      {/* Featured products preview */}
      <section id="catalog" className="py-20 sm:pt-8 sm:pb-28 bg-white dark:bg-graphite-900">
        <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-6 mb-10">
            <div>
              <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
                Хиты продаж
              </span>
              <h2 className="font-display font-extrabold text-4xl sm:text-5xl text-white mt-2 tracking-tight">
                Популярные модели
              </h2>
              <p className="text-graphite-400 mt-3 max-w-lg">
                Самые востребованные телевизоры TELVORA — проверенные покупателями и временем.
              </p>
            </div>
            <Link
              to="/catalog"
              className="group inline-flex items-center gap-2 text-sm font-semibold text-white hover:text-accent-500 transition-colors"
            >
              Весь каталог
              <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
            </Link>
          </div>

          <ProductGrid products={featured} loading={loading} error={error} />
        </div>
      </section>

      <TechSection />
    </>
  );
}
