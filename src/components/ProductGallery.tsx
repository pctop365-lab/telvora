import { ChevronLeft, ChevronRight, X, ZoomIn } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

type Props = { images?: string[]; legacyImage: string; productName: string; badge?: string; discount?: number };

export default function ProductGallery({ images, legacyImage, productName, badge, discount = 0 }: Props) {
  const items = useMemo(() => Array.from(new Set((images?.length ? images : [legacyImage]).filter(Boolean))), [images, legacyImage]);
  const [index, setIndex] = useState(0);
  const [zoomed, setZoomed] = useState(false);
  useEffect(() => { setIndex(0); setZoomed(false); }, [productName]);
  const multiple = items.length > 1;
  const move = useCallback((delta: number) => setIndex((current) => (current + delta + items.length) % items.length), [items.length]);
  useEffect(() => {
    if (!zoomed) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setZoomed(false);
      if (multiple && event.key === 'ArrowLeft') move(-1);
      if (multiple && event.key === 'ArrowRight') move(1);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [zoomed, multiple, move]);
  if (!items.length) return null;
  const controls = multiple && <>
    <button type="button" onClick={() => move(-1)} aria-label="Предыдущее изображение" className="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white"><ChevronLeft /></button>
    <button type="button" onClick={() => move(1)} aria-label="Следующее изображение" className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white"><ChevronRight /></button>
  </>;
  return <div className="self-start">
    <div className="relative overflow-hidden rounded-3xl border border-white/5 bg-graphite-800">
      <button type="button" onClick={() => setZoomed(true)} aria-label={`Увеличить изображение ${index + 1} товара ${productName}`} className="block aspect-[4/3] w-full cursor-zoom-in">
        <img src={items[index]} alt={`${productName}, изображение ${index + 1} из ${items.length}`} className="h-full w-full object-contain" /><ZoomIn className="absolute bottom-4 right-4 rounded bg-black/60 p-1 text-white" />
      </button>{controls}
      <div className="pointer-events-none absolute left-4 top-4 flex flex-col gap-2">{badge && <span className="rounded-lg bg-accent-500 px-3 py-1.5 text-xs font-bold text-white">{badge}</span>}{discount > 0 && <span className="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-graphite-900">Скидка {discount}%</span>}</div>
    </div>
    {multiple && <div className="mt-3 flex gap-2 overflow-x-auto pb-1" aria-label="Миниатюры товара">{items.map((src, i) => <button type="button" key={src} onClick={() => setIndex(i)} aria-label={`Показать изображение ${i + 1}`} aria-current={i === index ? 'true' : undefined} className={`h-16 w-20 shrink-0 overflow-hidden rounded-lg border-2 bg-white ${i === index ? 'border-accent-500' : 'border-transparent'}`}><img src={src} alt="" className="h-full w-full object-contain" /></button>)}</div>}
    {zoomed && <div role="dialog" aria-modal="true" aria-label={`Увеличенное изображение товара ${productName}`} className="fixed inset-0 z-[100] flex items-center justify-center bg-black/90 p-4" onClick={() => setZoomed(false)}><button type="button" autoFocus onClick={() => setZoomed(false)} aria-label="Закрыть увеличенное изображение" className="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white"><X /></button><img src={items[index]} alt={`${productName}, увеличенное изображение ${index + 1}`} className="max-h-[90vh] max-w-[95vw] object-contain" onClick={(e) => e.stopPropagation()} />{controls}</div>}
  </div>;
}
