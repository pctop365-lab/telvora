import { useParams } from 'react-router-dom';
import { useProduct } from '@/hooks/useProduct';
import ProductDetail from '@/components/ProductDetail';
import { Loader2 } from 'lucide-react';

export default function ProductPage() {
  const { productSlug } = useParams<{ productSlug: string }>();
  const { product, loading, error } = useProduct(productSlug);

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-graphite-900">
        <Loader2 className="w-8 h-8 text-accent-500 animate-spin" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-graphite-900">
        <p className="text-graphite-400 text-lg">{error}</p>
      </div>
    );
  }

  if (!product) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center bg-graphite-900 gap-4">
        <p className="text-graphite-400 text-lg">Товар не найден</p>
        <a href="/catalog" className="px-6 py-3 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-colors">
          В каталог
        </a>
      </div>
    );
  }

  return <ProductDetail product={product} />;
}
