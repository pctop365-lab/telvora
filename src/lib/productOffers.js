const availabilityUrl = {
  in_stock: 'https://schema.org/InStock',
  out_of_stock: 'https://schema.org/OutOfStock',
  expected: 'https://schema.org/PreOrder',
};

/** Build buyer-facing Product offers from the same effective variant prices used by the storefront. */
export function buildProductOffers(variants, productUrl, publicationStatus) {
  if (['draft', 'publish_failed', 'unpublished'].includes(publicationStatus)) return undefined;
  if (!Array.isArray(variants)) return undefined;

  const offers = variants.flatMap((variant) => {
    const price = Number(variant?.price);
    if (variant?.isActive === false || !String(variant?.country || '').trim() || !Number.isFinite(price) || price <= 0) return [];

    const availability = variant?.availability;
    const confirmedAvailability = availability && (
      (availability.status === 'in_stock' && availability.orderable === true) ||
      availability.status === 'out_of_stock' ||
      (availability.status === 'expected' && availability.orderable === true)
    ) ? availabilityUrl[availability.status] : undefined;

    return [{
      '@type': 'Offer',
      url: productUrl,
      priceCurrency: 'RUB',
      price,
      ...(variant.country ? { name: variant.country } : {}),
      ...(confirmedAvailability ? { availability: confirmedAvailability } : {}),
    }];
  });

  if (offers.length === 0) return undefined;
  return offers.length === 1 ? offers[0] : offers;
}
