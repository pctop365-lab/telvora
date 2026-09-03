import type { PublicVariantAvailability } from '@/types';

const labels = { in_stock: 'В наличии', out_of_stock: 'Нет в наличии', expected: 'Ожидается поступление', unknown: 'Наличие уточняется' } as const;

function formatArrival(value: string): string | null {
  const date = new Date(value.replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? null : new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' }).format(date);
}

export default function AvailabilityStatus({ availability, compact = false }: { availability: PublicVariantAvailability; compact?: boolean }) {
  const date = availability.expectedArrivalAt ? formatArrival(availability.expectedArrivalAt) : null;
  const detail = availability.status === 'expected'
    ? date ? `Ожидаем поступление у поставщика ${date}. Срок доставки подтвердим после поступления.` : 'Ожидается поступление у поставщика. Срок доставки подтвердим после поступления.'
    : availability.status === 'unknown' ? 'Наличие и срок поставки уточняются.' : null;
  return <div className="text-sm">
    <span className={availability.status === 'in_stock' ? 'text-green-600 dark:text-green-400' : availability.status === 'out_of_stock' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'}>{labels[availability.status]}</span>
    {!compact && detail && <p className="mt-1 text-xs text-graphite-600 dark:text-graphite-400">{detail}</p>}
  </div>;
}
