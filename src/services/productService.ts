import type { Product, ProductCategory, Category, SortKey } from '@/types';
import {
  getSeedCategories,
  getCategorySlug,
  parseCategorySlug,
} from '@/data/seed';

const PRODUCTS_API = 'https://telvora.ru/products.php';

type ApiProduct = {
  id: number | string;
  slug: string;
  name: string;
  series: string;
  country?: string;
  category: ProductCategory;
  screen_size?: string | number;
  resolution?: string;
  price?: number | string;
  old_price?: number | string | null;
  image?: string;
  badge?: string;
  rating?: number | string;
  reviews?: number | string;
  description?: string;
  specs?: Array<{
    label?: string;
    value?: string;
  }>;
  highlights?: string[];
  is_active?: boolean | number;
  variants?: Array<{
    country?: string;
    price?: number | string;
    old_price?: number | string | null;
    is_active?: boolean | number;
  }>;
  storefront_variants?: Array<{
    product_variant_id: number;
    country: string;
    display_name?: string | null;
    price: number | string;
    old_price?: number | string | null;
    is_active?: boolean | number;
    availability: {
      product_variant_id: number;
      status: 'in_stock' | 'out_of_stock' | 'expected' | 'unknown';
      orderable: boolean;
      expected_arrival_at?: string | null;
    };
  }>;
};

type ProductsResponse = {
  success: boolean;
  count?: number;
  products?: ApiProduct[];
  message?: string;
};

function normalizeProduct(product: ApiProduct): Product {
  return {
    id: String(product.id),
    slug: String(product.slug || ''),
    name: String(product.name || ''),
    series: String(product.series || ''),
    category: product.category,
    screenSize: String(product.screen_size ?? ''),
    resolution: String(product.resolution || ''),
    price: Number(product.price || 0),
    oldPrice:
      product.old_price !== null &&
      product.old_price !== undefined
        ? Number(product.old_price)
        : undefined,
    image: String(product.image || ''),
    badge: product.badge
      ? String(product.badge)
      : undefined,
    rating: Number(product.rating || 0),
    reviews: Number(product.reviews || 0),
    description: String(product.description || ''),
    specs: Array.isArray(product.specs)
      ? product.specs.map((item) => ({
          label: String(item.label || ''),
          value: String(item.value || ''),
        }))
      : [],
    highlights: Array.isArray(product.highlights)
      ? product.highlights.map((item) => String(item))
      : [],
    variants: Array.isArray(product.storefront_variants)
      ? product.storefront_variants.map((variant) => ({
          productVariantId: Number(variant.product_variant_id),
          country: String(variant.country || ''),
          displayName: variant.display_name ? String(variant.display_name) : undefined,
          price: Number(variant.price || 0),
          oldPrice:
            variant.old_price !== null &&
            variant.old_price !== undefined
              ? Number(variant.old_price)
              : undefined,
          isActive:
            variant.is_active === undefined
              ? true
              : Boolean(variant.is_active),
          availability: {
            productVariantId: Number(variant.availability.product_variant_id),
            status: variant.availability.status,
            orderable: Boolean(variant.availability.orderable),
            expectedArrivalAt: variant.availability.expected_arrival_at ?? null,
          },
        }))
      : [],
  };
}

async function loadProductsFromApi(): Promise<Product[]> {
  const response = await fetch(`${PRODUCTS_API}?action=list`, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
    },
  });

  if (!response.ok) {
    throw new Error(`Ошибка загрузки товаров: ${response.status}`);
  }

  const data: ProductsResponse = await response.json();

  if (!data.success) {
    throw new Error(data.message || 'Не удалось загрузить товары');
  }

  const products = Array.isArray(data.products)
    ? data.products
    : [];

  return products
    .filter(
      (product) =>
        product.is_active === undefined ||
        Boolean(product.is_active)
    )
    .map(normalizeProduct);
}

export async function fetchProducts(filters?: {
  category?: ProductCategory;
  search?: string;
  sort?: SortKey;
}): Promise<Product[]> {
  let items = await loadProductsFromApi();

  if (filters?.category) {
    items = items.filter(
      (p) => p.category === filters.category
    );
  }

  if (filters?.search) {
    const q = filters.search.toLowerCase().trim();

    items = items.filter(
      (p) =>
        p.name.toLowerCase().includes(q) ||
        p.category.toLowerCase().includes(q) ||
        p.series.toLowerCase().includes(q)
    );
  }

  if (filters?.sort === 'price-asc') {
    items = [...items].sort(
      (a, b) => a.price - b.price
    );
  } else if (filters?.sort === 'price-desc') {
    items = [...items].sort(
      (a, b) => b.price - a.price
    );
  } else if (filters?.sort === 'rating') {
    items = [...items].sort(
      (a, b) => b.rating - a.rating
    );
  }

  return items;
}

export async function fetchProductBySlug(
  slug: string
): Promise<Product | null> {
  const items = await loadProductsFromApi();

  return (
    items.find((p) => p.slug === slug) ?? null
  );
}

export async function fetchProductsByCategorySlug(
  categorySlug: string
): Promise<Product[]> {
  const category = parseCategorySlug(categorySlug);

  if (!category) {
    return [];
  }

  const items = await loadProductsFromApi();

  return items.filter(
    (p) => p.category === category
  );
}

export async function fetchCategories(): Promise<Category[]> {
  const cats = getSeedCategories().map((c) => ({
    slug: c.slug,
    label: c.label,
    description: c.description,
  }));

  return cats;
}

export async function fetchFeaturedProducts(
  limit = 3
): Promise<Product[]> {
  const items = await loadProductsFromApi();

  return items
    .filter(
      (p) =>
        p.badge === 'Хит продаж' ||
        p.badge === 'Новинка' ||
        p.badge === 'Премиум'
    )
    .slice(0, limit);
}

export function getCategorySlugForProduct(
  product: Product
): string {
  return getCategorySlug(product.category);
}
