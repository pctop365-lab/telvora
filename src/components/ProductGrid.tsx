import type { Product } from '@/types';
import ProductCard from './ProductCard';
import { Loader2 } from 'lucide-react';

type ProductGridProps = {
  products: Product[];
  loading: boolean;
  error?: string | null;
};

export default function ProductGrid({ products, loading, error }: ProductGridProps) {
  if (loading) {
    return (
      <div className="flex items-center justify-center py-24">
        <Loader2 className="w-8 h-8 text-accent-500 animate-spin" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="text-center py-20">
        <p className="text-graphite-400 text-lg">{error}</p>
      </div>
    );
  }

  if (products.length === 0) {
    return (
      <div className="text-center py-20">
        <p className="text-graphite-400 text-lg">Ничего не найдено. Попробуйте изменить запрос.</p>
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
      {products.map((product, i) => (
        <ProductCard key={product.id} product={product} delay={i * 0.08} />
      ))}
    </div>
  );
}
