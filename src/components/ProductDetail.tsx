import { Link } from 'react-router-dom';
import { ArrowLeft, ShoppingBag, Check, Cpu, Monitor, Volume2, Zap, ChevronRight } from 'lucide-react';
import type { Product, ProductVariant } from '@/types';
import { formatPrice } from '@/lib/format';
import { useCart } from '@/store/cart';
import { useUI } from '@/store/ui';
import { getCategorySlugForProduct } from '@/services/productService';
import { useEffect, useRef, useState } from 'react';
import AvailabilityStatus from './AvailabilityStatus';
import ProductGallery from './ProductGallery';
import AmazonRating from './AmazonRating';
import SeoMetadata from './SeoMetadata';
import { absoluteTelvoraUrl } from '@/lib/seo';
import { buildProductOffers } from '@/lib/productOffers';

export function formatVariantProductName(productName: string, modelCode?: string | null): string {
  const code = modelCode?.trim();
  if (!code) return productName;
  const modelToken = productName.match(/\b(?=[A-Za-zА-Яа-я0-9-]*[A-Za-zА-Яа-я])(?=[A-Za-zА-Яа-я0-9-]*\d)[A-Za-zА-Яа-я0-9-]{5,}\b/)?.[0];
  if (!modelToken) return productName;
  // A model code can include a suffix (e.g. "50E7S PRO") already in the name.
  // Replace that suffix together with the model token instead of appending it twice.
  const escapeRegex = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const suffix = code.split(/\s+/).slice(1);
  const suffixPattern = suffix.length
    ? `(?:\\s+${suffix.map(escapeRegex).join('\\s+')}\\b)?`
    : '';
  return productName.replace(new RegExp(`\\b${escapeRegex(modelToken)}${suffixPattern}`, 'i'), () => code);
}

type ProductDetailProps = {
  product: Product;
};

export default function ProductDetail({ product }: ProductDetailProps) {
  const [added, setAdded] = useState(false);
  const [descriptionExpanded, setDescriptionExpanded] = useState(false);
  const [descriptionHasOverflow, setDescriptionHasOverflow] = useState(false);
  const [descriptionMaxHeight, setDescriptionMaxHeight] = useState(240);
  const descriptionRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setDescriptionExpanded(false);
    const measureDescription = () => {
      const element = descriptionRef.current;
      if (!element) return;
      const collapsedHeight = window.matchMedia('(min-width: 640px)').matches ? 330 : 240;
      setDescriptionMaxHeight(collapsedHeight);
      setDescriptionHasOverflow(element.scrollHeight > collapsedHeight + 1);
    };

    measureDescription();
    const observer = typeof ResizeObserver !== 'undefined'
      ? new ResizeObserver(measureDescription)
      : null;
    if (descriptionRef.current && observer) observer.observe(descriptionRef.current);
    window.addEventListener('resize', measureDescription);
    return () => {
      observer?.disconnect();
      window.removeEventListener('resize', measureDescription);
    };
  }, [product.description]);

  const activeVariants: ProductVariant[] = (product.variants || []).filter(
    (variant) =>
      Boolean(variant.country) &&
      Number(variant.price) > 0 &&
      variant.isActive !== false
  );

  // Keep only the choice, so API revalidation cannot leave a stale price/availability object.
  const [selectedVariantId, setSelectedVariantId] = useState<number | undefined>(activeVariants[0]?.productVariantId);
  const selectedVariant = activeVariants.find(variant => variant.productVariantId === selectedVariantId) ?? activeVariants[0];
  const displayedProductName = formatVariantProductName(product.name, selectedVariant?.modelCode);

  const currentPrice = selectedVariant
  ? Number(selectedVariant.price)
  : 0;

const currentOldPrice = selectedVariant?.oldPrice
  ? Number(selectedVariant.oldPrice)
  : undefined;
  const currentAvailability = selectedVariant?.availability;
  const { addToCart } = useCart();
  const { openCart } = useUI();
  const discount =
  currentOldPrice && currentPrice > 0
    ? Math.round(
        ((currentOldPrice - currentPrice) / currentOldPrice) * 100
      )
    : 0;
  const categorySlug = getCategorySlugForProduct(product);
const seoTitle = `${product.name} — купить в TELVORA`;
const detailTokens = [product.screenSize, product.series, product.resolution].filter(Boolean).join(', ');
const descriptionText = product.description.trim();
const priceText = currentPrice > 0 ? ` Цена: ${formatPrice(currentPrice)}.` : '';
const seoDescription = `${product.name}${detailTokens ? ` — ${detailTokens}` : ''}.${priceText} ${descriptionText}`.trim().slice(0, 300);

const productUrl =
  `https://telvora.ru/catalog/${categorySlug}/${product.slug}`;
  const productOffers = buildProductOffers(activeVariants, productUrl, product.publicationStatus);
  const productJsonLd: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: product.name,
    image: (product.images?.length ? product.images : [product.image]).filter(Boolean).map(absoluteTelvoraUrl),
    description: descriptionText || seoDescription,
    sku: product.id,
    url: productUrl,
    ...(product.brand ? { brand: { '@type': 'Brand', name: product.brand } } : {}),
    ...(productOffers ? { offers: productOffers } : {}),
  };
  const breadcrumbJsonLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: 'Главная', item: 'https://telvora.ru/' },
      { '@type': 'ListItem', position: 2, name: 'Каталог', item: 'https://telvora.ru/catalog' },
      { '@type': 'ListItem', position: 3, name: product.category, item: `https://telvora.ru/catalog/${categorySlug}` },
      { '@type': 'ListItem', position: 4, name: product.name, item: productUrl },
    ],
  };

  const handleAdd = () => {
  if (!selectedVariant || !currentAvailability?.orderable) return;
  addToCart(product, 1, selectedVariant);
    setAdded(true);
    setTimeout(() => setAdded(false), 1500);
  };

  const handleBuyNow = () => {
  if (!selectedVariant || !currentAvailability?.orderable) return;
  addToCart(product, 1, selectedVariant);
    openCart();
  };

  return (
  <>
    <SeoMetadata title={seoTitle} description={seoDescription} path={`/catalog/${categorySlug}/${product.slug}`} type="product" image={product.image || undefined} jsonLd={[productJsonLd, breadcrumbJsonLd]} />

    <div className="pt-24 pb-20 bg-white dark:bg-graphite-900 min-h-screen">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Breadcrumbs */}
        <nav className="flex items-center gap-2 text-sm text-graphite-500 dark:text-graphite-400 mb-8 flex-wrap">
          <Link to="/" className="hover:text-graphite-950 dark:hover:text-white transition-colors">Главная</Link>
          <ChevronRight className="w-4 h-4" />
          <Link to="/catalog" className="hover:text-graphite-950 dark:hover:text-white transition-colors">Каталог</Link>
          <ChevronRight className="w-4 h-4" />
          <Link to={`/catalog/${categorySlug}`} className="hover:text-graphite-950 dark:hover:text-white transition-colors">
            {product.category}
          </Link>
          <ChevronRight className="w-4 h-4" />
          <span className="text-graphite-900 dark:text-white truncate">{product.name}</span>
        </nav>

        <Link
          to={`/catalog/${categorySlug}`}
          className="inline-flex items-center gap-2 text-sm text-graphite-600 dark:text-graphite-400 hover:text-graphite-950 dark:hover:text-white transition-colors mb-8"
        >
          <ArrowLeft className="w-4 h-4" />
          Назад к {product.category}
        </Link>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12">
          <ProductGallery images={product.images} imageVariants={product.imageVariants} legacyImage={product.image} productName={product.name} badge={product.badge} discount={discount} />

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

            <h1 className="font-display font-extrabold text-3xl sm:text-4xl text-graphite-950 dark:text-white leading-tight">
              {displayedProductName}
            </h1>
            {selectedVariant?.modelCode && displayedProductName === product.name && (
              <div className="mt-2 text-sm text-graphite-500 dark:text-graphite-400">Модель: <span className="font-medium text-graphite-800 dark:text-graphite-200">{selectedVariant.modelCode}</span></div>
            )}

            <AmazonRating rating={product.rating} />

            <div className="mt-6">
              <div
                ref={descriptionRef}
                data-testid="product-description"
                className="relative overflow-hidden text-lg leading-relaxed text-graphite-700 dark:text-graphite-300 transition-[max-height] duration-300 ease-out whitespace-pre-line"
                style={{ maxHeight: descriptionExpanded ? `${descriptionRef.current?.scrollHeight ?? descriptionMaxHeight}px` : `${descriptionMaxHeight}px` }}
              >
                {product.description}
                {descriptionHasOverflow && !descriptionExpanded && (
                  <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-white dark:from-graphite-900 to-transparent"
                  />
                )}
              </div>
              {descriptionHasOverflow && (
                <button
                  type="button"
                  data-testid="product-description-toggle"
                  onClick={() => setDescriptionExpanded((expanded) => !expanded)}
                  className="mt-3 inline-flex items-center rounded-lg px-3 py-2 text-sm font-semibold text-accent-500 transition-colors hover:bg-accent-500/10 focus:outline-none focus:ring-2 focus:ring-accent-500/40"
                  aria-expanded={descriptionExpanded}
                >
                  {descriptionExpanded ? 'Свернуть' : 'Показать полностью'}
                </button>
              )}
            </div>

            {/* Highlights */}
            <div className="grid grid-cols-2 gap-3 mt-6">
              {product.highlights.map((h) => (
                <div key={h} className="flex items-center gap-2 text-sm text-graphite-800 dark:text-graphite-200">
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
                <div key={i} className="flex flex-col items-center gap-1.5 p-3 bg-white dark:bg-graphite-800 rounded-xl border border-graphite-200 dark:border-white/5">
                  <s.icon className="w-5 h-5 text-accent-500" />
                  <span className="text-xs font-medium text-graphite-700 dark:text-graphite-300">{s.label}</span>
                </div>
              ))}
            </div>

            {/* Assembly country */}
            {activeVariants.length > 0 && (
              <div className="mt-6 p-5 bg-white dark:bg-graphite-800 rounded-2xl border border-graphite-200 dark:border-white/5">
              <div className="text-sm font-semibold text-graphite-900 dark:text-white mb-3">
                  Страна сборки
                </div>

                <select
                  value={selectedVariant?.country || ''}
                  onChange={(e) => {
                    const variant = activeVariants.find(
                      (item) => item.country === e.target.value
                    );

                    setSelectedVariantId(variant?.productVariantId);
                  }}
                  className="w-full px-4 py-3 rounded-xl border border-graphite-200 bg-white text-graphite-900 outline-none transition focus:border-accent-500 focus:ring-2 focus:ring-accent-500/20 dark:border-white/10 dark:bg-graphite-900 dark:text-white dark:focus:border-accent-500/50"
                >
                  {activeVariants.map((variant) => (
                    <option
                      key={variant.country}
                      value={variant.country}
                      className="bg-white text-graphite-900 dark:bg-graphite-900 dark:text-white"
                    >
                      {variant.country} — {formatPrice(Number(variant.price))}
                    </option>
                  ))}
                </select>
              </div>
            )}
            {/* Price */}
            <div className="mt-8 p-6 bg-white dark:bg-graphite-800 rounded-2xl border border-graphite-200 dark:border-white/5">
              <div className="flex items-end justify-between gap-4">
                <div>
                  {currentOldPrice && currentOldPrice > currentPrice && (
  <div className="text-sm text-graphite-500 line-through">
    {formatPrice(currentOldPrice)}
  </div>
)}
                  <div className="text-4xl font-bold text-graphite-950 dark:text-white">
                    {formatPrice(currentPrice)}
                  </div>
                </div>
                {discount > 0 && (
                  <span className="px-3 py-1.5 text-sm font-bold rounded-lg bg-accent-500/10 text-accent-500">
                    Выгода {formatPrice((Number(currentOldPrice) || 0) - currentPrice)}
                  </span>
                )}
              </div>
              {currentAvailability && <div className="mt-4"><AvailabilityStatus availability={currentAvailability} /></div>}

              <div className="flex flex-col sm:flex-row gap-3 mt-6">
                <button
                  onClick={handleAdd}
                  disabled={!currentAvailability?.orderable}
                  className={`flex-1 flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl font-semibold transition-all ${
                    added
                      ? 'bg-green-500 text-white'
                      : 'bg-graphite-50 hover:bg-graphite-100 border border-graphite-200 text-graphite-900 dark:bg-white/5 dark:hover:bg-white/10 dark:border-white/10 dark:text-white'
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
                  disabled={!currentAvailability?.orderable}
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
          <h2 className="font-display font-bold text-2xl text-graphite-950 dark:text-white mb-6">Характеристики</h2>
          <div className="bg-white dark:bg-graphite-800 rounded-3xl border border-graphite-200 dark:border-white/5 overflow-hidden">
            {product.specs.map((spec, i) => (
              <div
                key={spec.label}
                className={`flex flex-col sm:flex-row sm:justify-between gap-2 px-6 py-4 text-sm ${
                  i !== product.specs.length - 1 ? 'border-b border-graphite-200 dark:border-white/5' : ''
                } ${i % 2 === 0 ? 'bg-graphite-50/70 dark:bg-white/[0.02]' : ''}`}
              >
                  <span className="text-graphite-600 dark:text-graphite-300">{spec.label}</span>
                <span className="text-graphite-950 dark:text-white font-medium sm:text-right">{spec.value}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  </>
  );
}
