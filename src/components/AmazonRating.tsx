import { Star } from 'lucide-react';

export default function AmazonRating({ rating, compact = false }: { rating: number; compact?: boolean }) {
  if (!Number.isFinite(rating) || rating <= 0 || rating > 5) return null;

  const value = rating.toLocaleString('ru-RU', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

  if (compact) {
    return <span aria-label={`Оценка ${value} из 5`} className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-graphite-900 dark:text-white sm:text-sm">
      <Star aria-hidden="true" className="h-3.5 w-3.5 fill-accent-500 text-accent-500 sm:h-4 sm:w-4" />
      {value}
    </span>;
  }

  return <section aria-label="Оценка на Amazon" className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-accent-200 bg-accent-50 px-4 py-3 dark:border-accent-500/20 dark:bg-accent-500/10">
    <div>
      <h2 className="text-sm font-semibold text-graphite-900 dark:text-white">Оценка на Amazon</h2>
      <p className="mt-1 text-xs text-graphite-600 dark:text-graphite-300">Рейтинг покупателей Amazon</p>
    </div>
    <div className="inline-flex items-center gap-2" aria-label={`${value} из 5`}>
      <Star aria-hidden="true" className="h-5 w-5 fill-accent-500 text-accent-500" />
      <span className="text-xl font-bold text-graphite-900 dark:text-white">{value}</span>
      <span className="text-sm text-graphite-500 dark:text-graphite-400">/ 5</span>
    </div>
  </section>;
}
