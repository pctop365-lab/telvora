import { usePrerender } from '@/store/prerender';
import { useState, useEffect } from 'react';
import type { Product } from '@/types';
import { fetchProductBySlug } from '@/services/productService';

export function useProduct(slug: string | undefined) {
  const snapshot = usePrerender();
  const snapshotProduct = snapshot?.products.find(p => p.slug === slug) ?? null;
  const [product, setProduct] = useState<Product | null>(snapshotProduct);
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
          // Keep the build-time active-product snapshot indexable if API revalidation fails.
          if (!product && snapshotProduct) setProduct(snapshotProduct);
          if (!product && !snapshotProduct) setError('\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0442\u043e\u0432\u0430\u0440');
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [slug]);

  return { product, loading: loading || (product !== null && product.slug !== slug), error };
}
