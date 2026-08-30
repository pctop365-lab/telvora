import { Link } from 'react-router-dom';
import { ArrowLeft, Star, ShoppingBag, Check, Cpu, Monitor, Volume2, Zap, ChevronRight } from 'lucide-react';
import type { Product, ProductVariant } from '@/types';
import { formatPrice } from '@/lib/format';
import { useCart } from '@/store/cart';
import { useUI } from '@/store/ui';
import { getCategorySlugForProduct } from '@/services/productService';
import { useState } from 'react';
import { Helmet } from 'react-helmet-async';

type ProductDetailProps = {
  product: Product;
};

export default function ProductDetail({ product }: ProductDetailProps) {
  const [added, setAdded] = useState(false);

  const activeVariants: ProductVariant[] = (product.variants || []).filter(
    (variant) =>
      Boolean(variant.country) &&
      Number(variant.price) > 0 &&
      variant.isActive !== false
  );

  const [selectedVariant, setSelectedVariant] = useState<ProductVariant | undefined>(
    activeVariants[0]
  );

  const currentPrice = selectedVariant
  ? Number(selectedVariant.price)
  : 0;

const currentOldPrice = selectedVariant?.oldPrice
  ? Number(selectedVariant.oldPrice)
  : undefined;
  const { addToCart } = useCart();
  const { openCart } = useUI();
  const discount =
  currentOldPrice && currentPrice > 0
    ? Math.round(
        ((currentOldPrice - currentPrice) / currentOldPrice) * 100
      )
    : 0;
  const categorySlug = getCategorySlugForProduct(product);
const seoTitle = `${product.name} — купить ${product.category} | TELVORA`;

const seoDescription =
  `${product.name} — ${product.screenSize}, ${product.resolution}. ` +
  `${product.description} Купить в интернет-магазине TELVORA. ` +
  `Официальная гарантия, доставка и профессиональная установка.`;

const productUrl =
  `https://telvora.ru/catalog/${categorySlug}/${product.slug}`;

  const handleAdd = () => {
  addToCart(product, 1, selectedVariant);
    setAdded(true);
    setTimeout(() => setAdded(false), 1500);
  };

  const handleBuyNow = () => {
  addToCart(product, 1, selectedVariant);
    openCart();
  };

  return (
  <>
    <Helmet>
      <title>{seoTitle}</title>
      <meta name="description" content={seoDescription} />
      <link rel="canonical" href={productUrl} />

      <meta property="og:type" content="product" />
      <meta property="og:title" content={seoTitle} />
      <meta property="og:description" content={seoDescription} />
      <meta property="og:url" content={productUrl} />
      <meta property="og:site_name" content="TELVORA" />

      <meta name="twitter:card" content="summary_large_image" />
      <meta name="twitter:title" content={seoTitle} />
      <meta name="twitter:description" content={seoDescription} />
    </Helmet>

    <div className="pt-24 pb-20 bg-white dark:bg-graphite-900 min-h-screen">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Breadcrumbs */}
        <nav className="flex items-center gap-2 text-sm text-graphite-400 mb-8 flex-wrap">
          <Link to="/" className="hover:text-white transition-colors">Главная</Link>
          <ChevronRight className="w-4 h-4" />
          <Link to="/catalog" className="hover:text-white transition-colors">Каталог</Link>
          <ChevronRight className="w-4 h-4" />
          <Link to={`/catalog/${categorySlug}`} className="hover:text-white transition-colors">
            {product.category}
          </Link>
          <ChevronRight className="w-4 h-4" />
          <span className="text-white truncate">{product.name}</span>
        </nav>

        <Link
          to={`/catalog/${categorySlug}`}
          className="inline-flex items-center gap-2 text-sm text-graphite-400 hover:text-white transition-colors mb-8"
        >
          <ArrowLeft className="w-4 h-4" />
          Назад к {product.category}
        </Link>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12">
          {/* Image */}
          <div className="relative rounded-3xl overflow-hidden bg-graphite-100 dark:bg-graphite-800 border border-white/5">
            <div className="aspect-[4/3] relative overflow-hidden">
              <img
                src={product.image}
                alt={product.name}
                className="w-full h-full object-cover"
              />
              <div className="absolute inset-0 bg-gradient-to-t from-graphite-800/40 to-transparent" />
            </div>
            <div className="absolute top-4 left-4 flex flex-col gap-2">
              {product.badge && (
                <span className="px-3 py-1.5 text-xs font-bold rounded-lg bg-accent-500 text-white">
                  {product.badge}
                </span>
              )}
              {discount > 0 && (
                <span className="px-3 py-1.5 text-xs font-bold rounded-lg bg-white text-graphite-900">
                  Скидка {discount}%
                </span>
              )}
            </div>
          </div>

          {/* Info */}
          <div className="flex flex-col">
            <div className="flex items-center gap-2 mb-2">
              <span className="text-xs font-semibold text-accent-500 uppercase tracking-wider">
                {product.series}
              </span>
              <span className="text-graphite-600">·</span>
              <span className="text-xs text-graphite-400">
                {product.category} · {product.screenSize}
              </span>
            </div>

            <h1 className="font-display font-extrabold text-3xl sm:text-4xl text-white leading-tight">
              {product.name}
            </h1>

            <div className="flex items-center gap-2 mt-4">
              <div className="flex items-center gap-0.5">
                {[1, 2, 3, 4, 5].map((s) => (
                  <Star
                    key={s}
                    className={`w-5 h-5 ${
                      s <= Math.round(product.rating)
                        ? 'fill-accent-500 text-accent-500'
                        : 'text-graphite-600'
                    }`}
                  />
                ))}
              </div>
              <span className="text-sm font-semibold text-white">{product.rating}</span>
              <span className="text-sm text-graphite-400">· {product.reviews} отзывов</span>
            </div>

            <p className="mt-6 text-graphite-300 leading-relaxed text-lg">
              {product.description}
            </p>

            {/* Highlights */}
            <div className="grid grid-cols-2 gap-3 mt-6">
              {product.highlights.map((h) => (
                <div key={h} className="flex items-center gap-2 text-sm text-graphite-200">
                  <div className="w-5 h-5 rounded-md bg-accent-500/10 flex items-center justify-center shrink-0">
                    <Check className="w-3 h-3 text-accent-500" />
                  </div>
                  {h}
                </div>
              ))}
            </div>

            {/* Spec icons */}
            <div className="grid grid-cols-4 gap-3 mt-6">
              {[
                { icon: Monitor, label: product.screenSize },
                { icon: Cpu, label: product.resolution.includes('8K') ? '8K' : '4K+' },
                { icon: Zap, label: '120Гц+' },
                { icon: Volume2, label: 'Atmos' },
              ].map((s, i) => (
                <div key={i} className="flex flex-col items-center gap-1.5 p-3 bg-graphite-100 dark:bg-graphite-800 rounded-xl border border-white/5">
                  <s.icon className="w-5 h-5 text-accent-500" />
                  <span className="text-xs font-medium text-graphite-300">{s.label}</span>
                </div>
              ))}
            </div>

            {/* Assembly country */}
            {activeVariants.length > 0 && (
              <div className="mt-6 p-5 bg-graphite-100 dark:bg-graphite-800 rounded-2xl border border-white/5">
                <div className="text-sm font-semibold text-white mb-3">
                  Страна сборки
                </div>

                <select
                  value={selectedVariant?.country || ''}
                  onChange={(e) => {
                    const variant = activeVariants.find(
                      (item) => item.country === e.target.value
                    );

                    setSelectedVariant(variant);
                  }}
                  className="w-full px-4 py-3 rounded-xl bg-graphite-900 border border-white/10 text-white outline-none focus:border-accent-500/50"
                >
                  {activeVariants.map((variant) => (
                    <option
                      key={variant.country}
                      value={variant.country}
                    >
                      {variant.country} — {formatPrice(Number(variant.price))}
                    </option>
                  ))}
                </select>
              </div>
            )}
            {/* Price */}
            <div className="mt-8 p-6 bg-graphite-100 dark:bg-graphite-800 rounded-2xl border border-white/5">
              <div className="flex items-end justify-between gap-4">
                <div>
                  {currentOldPrice && currentOldPrice > currentPrice && (
  <div className="text-sm text-graphite-500 line-through">
    {formatPrice(currentOldPrice)}
  </div>
)}
                  <div className="text-4xl font-bold text-white">
                    {formatPrice(currentPrice)}
                  </div>
                </div>
                {discount > 0 && (
                  <span className="px-3 py-1.5 text-sm font-bold rounded-lg bg-accent-500/10 text-accent-500">
                    Выгода {formatPrice((Number(currentOldPrice) || 0) - currentPrice)}
                  </span>
                )}
              </div>

              <div className="flex flex-col sm:flex-row gap-3 mt-6">
                <button
                  onClick={handleAdd}
                  className={`flex-1 flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl font-semibold transition-all ${
                    added
                      ? 'bg-green-500 text-white'
                      : 'bg-white/5 hover:bg-white/10 border border-white/10 text-white'
                  }`}
                >
                  {added ? (
                    <>
                      <Check className="w-5 h-5" />
                      Добавлено
                    </>
                  ) : (
                    <>
                      <ShoppingBag className="w-5 h-5" />
                      В корзину
                    </>
                  )}
                </button>
                <button
                  onClick={handleBuyNow}
                  className="flex-1 flex items-center justify-center gap-2 px-6 py-3.5 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-all shadow-lg shadow-accent-500/30"
                >
                  Купить сейчас
                </button>
              </div>
            </div>
          </div>
        </div>

        {/* Detailed specs */}
        <div className="mt-16">
          <h2 className="font-display font-bold text-2xl text-white mb-6">Характеристики</h2>
          <div className="bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-white/5 overflow-hidden">
            {product.specs.map((spec, i) => (
              <div
                key={spec.label}
                className={`flex flex-col sm:flex-row sm:justify-between gap-2 px-6 py-4 text-sm ${
                  i !== product.specs.length - 1 ? 'border-b border-white/5' : ''
                } ${i % 2 === 0 ? 'bg-white/[0.02]' : ''}`}
              >
                <span className="text-graphite-400">{spec.label}</span>
                <span className="text-white font-medium sm:text-right">{spec.value}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  </>
  );
}
