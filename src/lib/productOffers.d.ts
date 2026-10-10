import type { ProductVariant } from '@/types';

export function buildProductOffers(
  variants: ProductVariant[] | undefined,
  productUrl: string,
  publicationStatus?: string,
): Record<string, string | number>[] | Record<string, string | number> | undefined;
