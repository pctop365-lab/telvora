import { usePrerender } from '@/store/prerender';
import { useState, useEffect } from 'react';
import type { Product } from '@/types';
import { fetchProductBySlug } from '@/services/productService';

export function useProduct(slug: string | undefined) {
  const snapshot = usePrerender();
  const [product, setProduct] = useState<Product | null>(snapshot?.products.find(p => p.slug === slug) ?? null);
  const [loading, setLoading] = useState(!snapshot?.products.some(p => p.slug === slug));
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!slug) {
      setProduct(null);
      setLoading(false);
      return;
    }

    let cancelled = false;
    if (product?.slug !== slug) setLoading(true);
    setError(null);

    fetchProductBySlug(slug)
      .then((data) => {
        if (!cancelled) {
          setProduct(data);
          setLoading(false);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setError('Не удалось загрузить товар');
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [slug]);

  return { product, loading: loading || (product !== null && product.slug !== slug), error };
}
