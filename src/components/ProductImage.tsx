import { useState, type ImgHTMLAttributes } from 'react';
import type { ProductImageVariants } from '@/types';

type Props = Omit<ImgHTMLAttributes<HTMLImageElement>, 'srcSet'> & {
  src: string;
  variants?: ProductImageVariants;
  roleSize: 'thumbnail' | 'catalog' | 'large' | 'zoom';
};

export default function ProductImage({ src, variants, roleSize, sizes, className, ...props }: Props) {
  const [failedSource, setFailedSource] = useState<string | null>(null);
  const maximum = { thumbnail: 320, catalog: 800, large: 1920, zoom: 2560 }[roleSize];
  const sources = variants?.sources || [];
  const smallest = Math.min(...sources.map(item => Math.max(item.width, item.height)));
  const candidates = sources.filter(item => Math.max(item.width, item.height) <= Math.max(maximum, smallest));
  const modern = candidates.filter(item => item.type === 'image/webp');
  const fallback = candidates.filter(item => item.type !== 'image/webp');
  const set = (items: typeof sources) => items.map(item => `${item.src} ${item.width}w`).join(', ');
  const optimized = failedSource !== src && fallback.length > 0;
  const defaultImage = fallback[fallback.length - 1];
  return <picture className="contents">
    {optimized && modern.length > 0 && <source type="image/webp" srcSet={set(modern)} sizes={sizes} />}
    <img {...props} src={optimized ? defaultImage.src : src}
      srcSet={optimized ? set(fallback) : undefined} sizes={optimized ? sizes : undefined}
      width={variants?.width || 1600} height={variants?.height || 900}
      decoding="async" className={className}
      onError={() => { if (optimized) setFailedSource(src); }} />
  </picture>;
}
