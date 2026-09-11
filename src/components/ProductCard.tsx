import { Star, ShoppingBag, Check } from 'lucide-react';
import { Link } from 'react-router-dom';
import type { Product } from '@/types';
import { formatPrice } from '@/lib/format';
import { useCart } from '@/store/cart';
import { getCategorySlugForProduct } from '@/services/productService';
import { useState } from 'react';
import AvailabilityStatus from './AvailabilityStatus';

type ProductCardProps = {
  product: Product;
  delay?: number;
};

export default function ProductCard({
  product,
  delay = 0,
}: ProductCardProps) {
  const [added, setAdded] = useState(false);
  const { addToCart } = useCart();

  const activeVariants = (product.variants || []).filter(
    (variant) =>
      Boolean(variant.country) &&
      Number(variant.price) > 0 &&
      variant.isActive !== false
  );

  const minVariantPrice =
    activeVariants.length > 0
      ? Math.min(
          ...activeVariants.map((variant) =>
            Number(variant.price)
          )
        )
      : product.price;

  const variantOldPrices = activeVariants
  .map((variant) =>
    variant.oldPrice
      ? Number(variant.oldPrice)
      : 0
  )
  .filter((price) => price > 0);

const minVariantOldPrice =
  variantOldPrices.length > 0
    ? Math.min(...variantOldPrices)
    : undefined;

  const hasVariants = activeVariants.length > 0;
  const singleVariant = activeVariants.length === 1 ? activeVariants[0] : undefined;
  const cardAvailability = singleVariant?.availability;

  const discount =
    minVariantOldPrice &&
    minVariantOldPrice > minVariantPrice
      ? Math.round(
          ((minVariantOldPrice - minVariantPrice) /
            minVariantOldPrice) *
            100
        )
      : 0;

  const handleAdd = (e: React.MouseEvent) => {
    e.preventDefault();
    e.stopPropagation();

    if (!singleVariant?.availability.orderable) return;
    addToCart(product, 1, singleVariant);

    setAdded(true);
    setTimeout(() => setAdded(false), 1500);
  };

  const categorySlug = getCategorySlugForProduct(product);
  const productUrl = `/catalog/${categorySlug}/${product.slug}`;

  return (
    <Link
      to={productUrl}
      className="group relative bg-graphite-100 dark:bg-graphite-800 rounded-2xl sm:rounded-3xl overflow-hidden border border-white/5 hover:border-white/10 transition-all duration-500 hover:-translate-y-1 hover:shadow-2xl hover:shadow-black/40 animate-fade-up flex flex-col min-w-0"
      style={{
        animationDelay: `${delay}s`,
        opacity: 0,
      }}
    >
      <div className="relative aspect-square sm:aspect-[4/3] overflow-hidden bg-white dark:bg-graphite-900">
        <img
          src={product.image}
          alt={product.name}
          loading="lazy"
          className="w-full h-full object-contain sm:object-cover transition-transform duration-700 group-hover:scale-105"
        />

        <div className="absolute inset-0 bg-gradient-to-t from-graphite-800 via-transparent to-transparent opacity-60" />

        <div className="absolute top-2 left-2 sm:top-4 sm:left-4 flex flex-col gap-1 sm:gap-2">
          {product.badge && (
            <span
              className={`max-w-[7.5rem] truncate px-2 py-1 text-[10px] font-bold rounded-md backdrop-blur-md sm:max-w-none sm:overflow-visible sm:text-clip sm:whitespace-normal sm:px-3 sm:py-1.5 sm:text-xs sm:rounded-lg ${
                product.badge.includes('%')
                  ? 'bg-accent-500 text-white'
                  : product.badge === 'Новинка'
                  ? 'bg-white text-graphite-900'
                  : 'bg-white/10 text-white border border-white/20'
              }`}
            >
              {product.badge}
            </span>
          )}

          {discount > 0 && !product.badge?.includes('%') && (
            <span className="px-2 py-1 text-[10px] font-bold rounded-md bg-accent-500 text-white sm:px-3 sm:py-1.5 sm:text-xs sm:rounded-lg">
              −{discount}%
            </span>
          )}
        </div>

        <span className="absolute bottom-2 left-2 max-w-[calc(100%-1rem)] truncate px-2 py-1 bg-black/40 backdrop-blur-md text-[10px] font-medium text-graphite-100 rounded-md border border-white/10 sm:bottom-4 sm:left-4 sm:max-w-none sm:overflow-visible sm:text-clip sm:whitespace-normal sm:px-3 sm:text-xs sm:rounded-lg">
          {product.category} · {product.screenSize}
        </span>
      </div>

      <div className="p-3 sm:p-5 flex flex-col flex-1 min-w-0">
        <div className="flex flex-col items-start gap-1 mb-2 sm:flex-row sm:justify-between sm:gap-2">
          <div className="min-w-0 w-full">
            <span className="block truncate text-[10px] font-medium text-accent-500 uppercase tracking-wider sm:overflow-visible sm:text-clip sm:whitespace-normal sm:text-xs">
              {product.series}
            </span>

            <h3 className="font-display font-bold text-sm text-white leading-tight mt-0.5 line-clamp-2 min-h-[2.5rem] sm:line-clamp-none sm:min-h-0 sm:text-lg">
              {product.name}
            </h3>
          </div>

          <div className="flex items-center gap-1 shrink-0">
            <Star className="w-3.5 h-3.5 fill-accent-500 text-accent-500 sm:w-4 sm:h-4" />
            <span className="text-xs font-semibold text-white sm:text-sm">
              {product.rating}
            </span>
          </div>
        </div>

        <p className="text-xs text-graphite-400 line-clamp-2 mb-3 flex-1 sm:text-sm sm:mb-4">
          {product.description}
        </p>

        <div className="flex flex-col items-stretch gap-2 mt-auto sm:flex-row sm:items-end sm:justify-between sm:gap-3">
          <div className="min-w-0">
            {minVariantOldPrice &&
              minVariantOldPrice > minVariantPrice && (
                <div className="text-xs text-graphite-500 line-through whitespace-nowrap sm:text-sm">
                  {formatPrice(minVariantOldPrice)}
                </div>
              )}

            <div className="text-base font-bold text-white whitespace-nowrap sm:text-xl">
              {hasVariants && (
                <span className="text-xs font-medium text-graphite-400 mr-1 sm:text-sm">
                  от
                </span>
              )}

              {formatPrice(minVariantPrice)}
            </div>
          </div>

          <button
            onClick={handleAdd}
            disabled={!cardAvailability?.orderable}
            className={`flex min-h-10 w-full items-center justify-center gap-1 px-2 py-2 rounded-lg text-xs font-semibold transition-all shrink-0 sm:min-h-0 sm:w-auto sm:gap-2 sm:px-4 sm:py-2.5 sm:rounded-xl sm:text-sm ${
              added
                ? 'bg-green-500 text-white'
                : cardAvailability?.orderable
                  ? 'bg-accent-500 hover:bg-accent-600 text-white shadow-lg shadow-accent-500/20 hover:shadow-accent-500/30'
                  : 'bg-graphite-300 dark:bg-white/10 text-graphite-500 cursor-not-allowed'
            }`}
          >
            {added ? (
              <>
                <Check className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                Добавлено
              </>
            ) : (
              <>
                <ShoppingBag className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                В корзину
              </>
            )}
          </button>
        </div>
        <div className="mt-2 min-w-0 text-xs sm:mt-3 sm:text-sm [&>div]:text-xs sm:[&>div]:text-sm">
          {cardAvailability ? <AvailabilityStatus availability={cardAvailability} compact /> : <span className="line-clamp-2 text-xs text-graphite-500 sm:text-sm">Выберите вариант, чтобы проверить наличие</span>}
        </div>
      </div>
    </Link>
  );
}
