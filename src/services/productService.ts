import type { Product, ProductCategory, Category, SortKey } from '@/types';
import {
  getSeedProducts,
  getSeedCategories,
  getCategorySlug,
  parseCategorySlug,
} from '@/data/seed';

/**
 * Data service layer.
 *
 * All UI components and hooks call these functions — never the seed data directly.
 * To switch to a real backend (MySQL via REST/GraphQL), replace the bodies
 * of these functions with `fetch` calls. The function signatures stay the same,
 * so no component code needs to change.
 */

const SIMULATED_LATENCY = 120;

function delay<T>(value: T, ms = SIMULATED_LATENCY): Promise<T> {
  return new Promise((resolve) => setTimeout(() => resolve(value), ms));
}

export async function fetchProducts(filters?: {
  category?: ProductCategory;
  search?: string;
  sort?: SortKey;
}): Promise<Product[]> {
  let items = getSeedProducts();

  if (filters?.category) {
    items = items.filter((p) => p.category === filters.category);
  }

  if (filters?.search) {
    const q = filters.search.toLowerCase();
    items = items.filter(
      (p) =>
        p.name.toLowerCase().includes(q) ||
        p.category.toLowerCase().includes(q) ||
        p.series.toLowerCase().includes(q)
    );
  }

  if (filters?.sort === 'price-asc') {
    items = [...items].sort((a, b) => a.price - b.price);
  } else if (filters?.sort === 'price-desc') {
    items = [...items].sort((a, b) => b.price - a.price);
  } else if (filters?.sort === 'rating') {
    items = [...items].sort((a, b) => b.rating - a.rating);
  }

  // In production: return delay(fetch('/api/products?…').then(r => r.json()));
  return delay(items);
}

export async function fetchProductBySlug(slug: string): Promise<Product | null> {
  const items = getSeedProducts();
  const product = items.find((p) => p.slug === slug) ?? null;
  return delay(product);
}

export async function fetchProductsByCategorySlug(
  categorySlug: string
): Promise<Product[]> {
  const category = parseCategorySlug(categorySlug);
  if (!category) return delay([]);

  const items = getSeedProducts().filter((p) => p.category === category);
  return delay(items);
}

export async function fetchCategories(): Promise<Category[]> {
  const cats = getSeedCategories().map((c) => ({
    slug: c.slug,
    label: c.label,
    description: c.description,
  }));
  return delay(cats);
}

export async function fetchFeaturedProducts(limit = 3): Promise<Product[]> {
  const items = getSeedProducts()
    .filter((p) => p.badge === 'Хит продаж' || p.badge === 'Новинка' || p.badge === 'Премиум')
    .slice(0, limit);
  return delay(items);
}

export function getCategorySlugForProduct(product: Product): string {
  return getCategorySlug(product.category);
}
