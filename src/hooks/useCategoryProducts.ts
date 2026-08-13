import { useState, useEffect } from 'react';
import type { Product } from '@/types';
import { fetchProductsByCategorySlug } from '@/services/productService';

export function useCategoryProducts(categorySlug: string | undefined) {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!categorySlug) {
      setProducts([]);
      setLoading(false);
      return;
    }

    let cancelled = false;
    setLoading(true);
    setError(null);

    fetchProductsByCategorySlug(categorySlug)
      .then((data) => {
        if (!cancelled) {
          setProducts(data);
          setLoading(false);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setError('Не удалось загрузить товары');
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [categorySlug]);

  return { products, loading, error };
}
