import { usePrerender } from '@/store/prerender';
import { useLocation } from 'react-router-dom';
import { useState, useEffect, useCallback } from 'react';
import type { Product, ProductCategory, SortKey } from '@/types';
import { fetchProducts } from '@/services/productService';

type Options = {
  category?: ProductCategory;
  search?: string;
  sort?: SortKey;
};

export function useProducts(options: Options = {}) {
  const snapshot = usePrerender();
  const { pathname } = useLocation();
  const seeded = snapshot?.path === pathname;
  const [products, setProducts] = useState<Product[]>(seeded ? snapshot.products : []);
  const [loading, setLoading] = useState(!seeded);
  const [error, setError] = useState<string | null>(null);

  const { category, search, sort } = options;

  const load = useCallback(async () => {
    if (!seeded) setLoading(true);
    setError(null);
    try {
      const data = await fetchProducts({ category, search, sort });
      setProducts(data);
    } catch {
      setError('Не удалось загрузить товары');
    } finally {
      setLoading(false);
    }
  }, [category, search, sort]);

  useEffect(() => {
    load();
  }, [load]);

  return { products, loading, error, reload: load };
}
