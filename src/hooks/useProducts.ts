import { useState, useEffect, useCallback } from 'react';
import type { Product, ProductCategory, SortKey } from '@/types';
import { fetchProducts } from '@/services/productService';

type Options = {
  category?: ProductCategory;
  search?: string;
  sort?: SortKey;
};

export function useProducts(options: Options = {}) {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const { category, search, sort } = options;

  const load = useCallback(async () => {
    setLoading(true);
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
