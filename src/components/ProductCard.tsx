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
      className="group relative bg-graphite-100 dark:bg-graphite-800 rounded-3xl overflow-hidden border border-white/5 hover:border-white/10 transition-all duration-500 hover:-translate-y-1 hover:shadow-2xl hover:shadow-black/40 animate-fade-up flex flex-col"
      style={{
        animationDelay: `${delay}s`,
        opacity: 0,
      }}
    >
      <div className="relative aspect-[4/3] overflow-hidden bg-white dark:bg-graphite-900">
        <img
          src={product.image}
          alt={product.name}
          loading="lazy"
          className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105"
        />

        <div className="absolute inset-0 bg-gradient-to-t from-graphite-800 via-transparent to-transparent opacity-60" />

        <div className="absolute top-4 left-4 flex flex-col gap-2">
          {product.badge && (
            <span
              className={`px-3 py-1.5 text-xs font-bold rounded-lg backdrop-blur-md ${
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
            <span className="px-3 py-1.5 text-xs font-bold rounded-lg bg-accent-500 text-white">
              −{discount}%
            </span>
          )}
        </div>

        <span className="absolute bottom-4 left-4 px-3 py-1 bg-black/40 backdrop-blur-md text-xs font-medium text-graphite-100 rounded-lg border border-white/10">
          {product.category} · {product.screenSize}
        </span>
      </div>

      <div className="p-5 flex flex-col flex-1">
        <div className="flex items-start justify-between gap-2 mb-2">
          <div>
            <span className="text-xs font-medium text-accent-500 uppercase tracking-wider">
              {product.series}
            </span>

            <h3 className="font-display font-bold text-lg text-white leading-tight mt-0.5">
              {product.name}
            </h3>
          </div>

          <div className="flex items-center gap-1 shrink-0">
            <Star className="w-4 h-4 fill-accent-500 text-accent-500" />
            <span className="text-sm font-semibold text-white">
              {product.rating}
            </span>
          </div>
        </div>

        <p className="text-sm text-graphite-400 line-clamp-2 mb-4 flex-1">
          {product.description}
        </p>

        <div className="flex items-end justify-between gap-3 mt-auto">
          <div>
            {minVariantOldPrice &&
              minVariantOldPrice > minVariantPrice && (
                <div className="text-sm text-graphite-500 line-through">
                  {formatPrice(minVariantOldPrice)}
                </div>
              )}

            <div className="text-xl font-bold text-white">
              {hasVariants && (
                <span className="text-sm font-medium text-graphite-400 mr-1">
                  от
                </span>
              )}

              {formatPrice(minVariantPrice)}
            </div>
          </div>

          <button
            onClick={handleAdd}
            disabled={!cardAvailability?.orderable}
            className={`flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold transition-all shrink-0 ${
              added
                ? 'bg-green-500 text-white'
                : cardAvailability?.orderable
                  ? 'bg-accent-500 hover:bg-accent-600 text-white shadow-lg shadow-accent-500/20 hover:shadow-accent-500/30'
                  : 'bg-graphite-300 dark:bg-white/10 text-graphite-500 cursor-not-allowed'
            }`}
          >
            {added ? (
              <>
                <Check className="w-4 h-4" />
                Добавлено
              </>
            ) : (
              <>
                <ShoppingBag className="w-4 h-4" />
                В корзину
              </>
            )}
          </button>
        </div>
        <div className="mt-3">
          {cardAvailability ? <AvailabilityStatus availability={cardAvailability} compact /> : <span className="text-sm text-graphite-500">Выберите вариант, чтобы проверить наличие</span>}
        </div>
      </div>
    </Link>
  );
}
