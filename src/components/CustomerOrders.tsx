import { CalendarDays, ChevronLeft, ChevronRight, MapPin, PackageCheck } from 'lucide-react';
import type { CustomerOrder, OrderHistory } from '@/services/customerService';
import { formatPrice } from '@/lib/format';

function formatDate(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

function statusClass(status: string) {
  const value = status.toLowerCase();
  if (value.includes('отмен') || value.includes('ошиб')) return 'bg-red-500/10 text-red-700 dark:text-red-300';
  if (value.includes('заверш') || value.includes('достав')) return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
  return 'bg-accent-500/10 text-accent-700 dark:text-accent-300';
}

function OrderCard({ order }: { order: CustomerOrder }) {
  return (
    <article className="overflow-hidden rounded-3xl border border-graphite-200 bg-white shadow-sm dark:border-white/10 dark:bg-graphite-800">
      <div className="flex flex-wrap items-start justify-between gap-4 border-b border-graphite-100 px-5 py-5 dark:border-white/10 sm:px-6">
        <div><p className="mb-1 text-xs font-semibold uppercase tracking-[0.14em] text-graphite-500 dark:text-graphite-300">Заказ</p><h3 className="font-display text-lg font-semibold text-graphite-950 dark:text-white">{order.order_number}</h3></div>
        <span className={`rounded-full px-3 py-1.5 text-xs font-semibold ${statusClass(order.status)}`}>{order.status}</span>
      </div>
      <div className="space-y-5 px-5 py-5 sm:px-6">
        <div className="flex items-center gap-2 text-sm text-graphite-600 dark:text-graphite-200"><CalendarDays className="h-4 w-4 text-accent-500" aria-hidden="true" /><span>{formatDate(order.created_at)}</span></div>
        <div>
          <p className="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-graphite-500 dark:text-graphite-300">Состав заказа</p>
          <ul className="divide-y divide-graphite-100 rounded-2xl border border-graphite-100 dark:divide-white/10 dark:border-white/10">
            {order.items.map((item, index) => <li key={`${item.product_name}-${index}`} className="flex items-start justify-between gap-4 px-4 py-3 text-sm"><span className="min-w-0 break-words text-graphite-800 dark:text-graphite-100">{item.product_name} <span className="text-graphite-500 dark:text-graphite-300">× {item.quantity}</span></span><span className="shrink-0 font-medium text-graphite-900 dark:text-white">{formatPrice(Number(item.price) * item.quantity)}</span></li>)}
            {order.services.map((service, index) => <li key={`service-${service.service_name}-${index}`} className="flex items-start justify-between gap-4 px-4 py-3 text-sm"><span className="min-w-0 break-words text-graphite-800 dark:text-graphite-100">{service.service_name} <span className="text-graphite-500 dark:text-graphite-300">× {service.quantity}</span></span><span className="shrink-0 font-medium text-graphite-900 dark:text-white">{formatPrice(Number(service.total))}</span></li>)}
            {!order.items.length && !order.services.length && <li className="px-4 py-3 text-sm text-graphite-500 dark:text-graphite-300">Состав заказа не указан.</li>}
          </ul>
        </div>
        <div className="flex flex-wrap items-baseline justify-between gap-2 border-t border-graphite-100 pt-4 dark:border-white/10"><span className="text-sm text-graphite-600 dark:text-graphite-200">Итого</span><strong className="text-lg text-graphite-950 dark:text-white">{order.total === null ? 'После согласования доставки' : formatPrice(Number(order.total))}</strong></div>
        <div className="grid gap-4 border-t border-graphite-100 pt-4 text-sm dark:border-white/10 sm:grid-cols-2">
          <div><p className="mb-1 text-xs font-semibold uppercase tracking-[0.12em] text-graphite-500 dark:text-graphite-300">Получатель</p><p className="break-words text-graphite-800 dark:text-graphite-100">{order.customer_name}</p><p className="break-words text-graphite-600 dark:text-graphite-200">{order.phone}{order.email && ` · ${order.email}`}</p></div>
          {order.address && <div><p className="mb-1 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.12em] text-graphite-500 dark:text-graphite-300"><MapPin className="h-3.5 w-3.5" aria-hidden="true" />Адрес доставки</p><p className="break-words text-graphite-800 dark:text-graphite-100">{order.address}</p></div>}
        </div>
      </div>
    </article>
  );
}

export default function CustomerOrders({ history, onPage }: { history: OrderHistory; onPage: (page: number) => void }) {
  if (history.orders.length === 0) return <div className="rounded-3xl border border-dashed border-graphite-300 bg-graphite-50 px-6 py-12 text-center dark:border-white/15 dark:bg-graphite-800/60"><PackageCheck className="mx-auto mb-4 h-10 w-10 text-accent-500" aria-hidden="true" /><h3 className="font-display text-lg font-semibold text-graphite-950 dark:text-white">Заказов пока нет</h3><p className="mx-auto mt-2 max-w-sm text-sm leading-6 text-graphite-600 dark:text-graphite-200">Оформленные после входа в аккаунт заказы появятся здесь.</p></div>;
  return <div className="space-y-4">
    {history.orders.map(order => <OrderCard key={order.id} order={order} />)}
    {history.total > 20 && <nav aria-label="Страницы заказов" className="flex items-center justify-between rounded-2xl border border-graphite-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-graphite-800"><button type="button" disabled={history.page <= 1} onClick={() => onPage(history.page - 1)} className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1.5 font-medium text-accent-600 transition hover:bg-accent-500/10 disabled:cursor-not-allowed disabled:opacity-40 dark:text-accent-300"><ChevronLeft className="h-4 w-4" aria-hidden="true" />Назад</button><span className="text-graphite-600 dark:text-graphite-200">{history.page} из {Math.ceil(history.total / 20)}</span><button type="button" disabled={history.page * 20 >= history.total} onClick={() => onPage(history.page + 1)} className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1.5 font-medium text-accent-600 transition hover:bg-accent-500/10 disabled:cursor-not-allowed disabled:opacity-40 dark:text-accent-300">Далее<ChevronRight className="h-4 w-4" aria-hidden="true" /></button></nav>}
  </div>;
}
