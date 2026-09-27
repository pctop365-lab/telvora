import type { OrderHistory } from '@/services/customerService';
import { formatPrice } from '@/lib/format';
export default function CustomerOrders({ history, onPage }: { history: OrderHistory; onPage: (page: number) => void }) {
  return <div className="space-y-4">
    {history.orders.length === 0 && <p>Заказов пока нет. Здесь появятся заказы, оформленные после входа в аккаунт.</p>}
    {history.orders.map(order => <article key={order.id} className="rounded-2xl border border-graphite-200 dark:border-white/10 p-5 space-y-2 break-words">
      <div className="flex flex-wrap justify-between gap-2"><strong>{order.order_number}</strong><span>{order.status}</span></div>
      <p className="text-sm opacity-70">{order.created_at}</p>
      <ul className="space-y-1">{order.items.map((item, i) => <li key={i}>{item.product_name} × {item.quantity} — {formatPrice(Number(item.price) * item.quantity)}</li>)}{order.services.map((service, i) => <li key={`s${i}`}>{service.service_name} × {service.quantity} — {formatPrice(Number(service.total))}</li>)}</ul>
      <p className="font-semibold">{order.total === null ? 'Итог после согласования доставки' : `Итого: ${formatPrice(Number(order.total))}`}</p>
      <p className="text-sm opacity-80">Получатель: {order.customer_name}; {order.phone}{order.email && `; ${order.email}`}</p>
      {order.address && <p className="text-sm opacity-80">{order.address}</p>}
    </article>)}
    {history.total > 20 && <div className="flex items-center gap-4"><button type="button" disabled={history.page <= 1} onClick={() => onPage(history.page - 1)} className="disabled:opacity-40">Назад</button><span>{history.page} / {Math.ceil(history.total / 20)}</span><button type="button" disabled={history.page * 20 >= history.total} onClick={() => onPage(history.page + 1)} className="disabled:opacity-40">Далее</button></div>}
  </div>;
}
