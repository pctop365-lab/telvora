import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { CheckCircle2, ArrowRight, Package, Truck, Loader2 } from 'lucide-react';
import { fetchOrderByNumber } from '@/services/orderService';
import type { Order } from '@/types';
import { formatPrice } from '@/lib/format';

export default function OrderSuccessPage() {
  const { orderNumber } = useParams<{ orderNumber: string }>();
  const [order, setOrder] = useState<Order | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!orderNumber) {
      setLoading(false);
      return;
    }
    let cancelled = false;
    fetchOrderByNumber(orderNumber)
      .then((data) => {
        if (!cancelled) {
          setOrder(data);
          setLoading(false);
        }
      })
      .catch(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [orderNumber]);

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-white dark:bg-graphite-900">
        <Loader2 className="w-8 h-8 text-accent-500 animate-spin" />
      </div>
    );
  }

  if (!order) {
    return (
      <div className="pt-24 pb-20 min-h-screen bg-white dark:bg-graphite-900 flex items-center justify-center">
        <div className="text-center max-w-md px-4">
          <h1 className="font-display font-bold text-2xl text-white mb-3">
            Заказ не найден
          </h1>
          <p className="text-graphite-400 mb-8">
            Возможно, ссылка устарела или содержит ошибку.
          </p>
          <Link
            to="/"
            className="inline-flex items-center gap-2 px-6 py-3 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-colors"
          >
            На главную
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="pt-24 pb-20 bg-white dark:bg-graphite-900 min-h-screen">
      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Success header */}
        <div className="text-center mb-12">
          <div className="inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-green-500/10 mb-6 animate-scale-in">
            <CheckCircle2 className="w-10 h-10 text-green-500" />
          </div>
          <h1 className="font-display font-extrabold text-3xl sm:text-4xl text-white tracking-tight mb-3">
            Заказ оформлен!
          </h1>
          <p className="text-graphite-400 text-lg max-w-md mx-auto">
            Спасибо за покупку. Мы свяжемся с вами в ближайшее время для подтверждения.
          </p>
        </div>

        {/* Order info card */}
        <div className="p-6 sm:p-8 bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-white/5 mb-6">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 pb-6 border-b border-white/10">
            <div>
              <span className="text-xs text-graphite-400 uppercase tracking-wider">
                Номер заказа
              </span>
              <div className="font-display font-bold text-2xl text-white mt-1">
                {order.orderNumber}
              </div>
            </div>
            <div className="text-left sm:text-right">
              <span className="text-xs text-graphite-400 uppercase tracking-wider">
                Дата
              </span>
              <div className="text-white font-medium mt-1">
                {new Date(order.createdAt).toLocaleDateString('ru-RU', {
                  day: 'numeric',
                  month: 'long',
                  year: 'numeric',
                })}
              </div>
            </div>
          </div>

          {/* Items */}
          <div className="space-y-4 mb-6">
            <h3 className="text-sm font-semibold text-white">Состав заказа</h3>
            {order.items.map((item) => (
              <div key={item.productId} className="flex gap-4">
                <div className="w-16 h-16 rounded-xl overflow-hidden bg-white dark:bg-graphite-900 shrink-0">
                  <img
                    src={item.image}
                    alt={item.name}
                    className="w-full h-full object-cover"
                  />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-semibold text-white truncate">
                    {item.name}
                  </div>
                  <div className="text-xs text-graphite-400">
                    {item.screenSize} · {item.quantity} шт
                  </div>
                </div>
                <div className="text-sm font-bold text-white shrink-0">
                  {formatPrice(item.price * item.quantity)}
                </div>
              </div>
            ))}
          </div>

          {/* Totals */}
          <div className="space-y-2 pt-6 border-t border-white/10">
            <div className="flex justify-between text-sm">
              <span className="text-graphite-400">Товары</span>
              <span className="text-white font-medium">{formatPrice(order.subtotal)}</span>
            </div>
            <div className="flex justify-between text-sm">
              <span className="text-graphite-400">Доставка</span>
              <span className="text-white font-medium">
                {order.delivery === 0 ? 'Бесплатно' : formatPrice(order.delivery)}
              </span>
            </div>
            <div className="flex justify-between items-center pt-3 border-t border-white/10">
              <span className="text-base font-semibold text-white">Итого</span>
              <span className="text-2xl font-bold text-white">{formatPrice(order.total)}</span>
            </div>
          </div>
        </div>

        {/* Customer info */}
        <div className="p-6 bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-white/5 mb-6">
          <h3 className="text-sm font-semibold text-white mb-4">Данные получателя</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            <InfoRow label="Имя" value={order.customer.fullName} />
            <InfoRow label="Телефон" value={order.customer.phone} />
            <InfoRow label="Email" value={order.customer.email} />
            <InfoRow
              label="Доставка"
              value={
                order.customer.deliveryMethod === 'courier'
                  ? 'Курьерская доставка'
                  : order.customer.deliveryMethod === 'pickup'
                  ? 'Самовывоз'
                  : 'Почта России'
              }
            />
            {order.customer.address && (
              <InfoRow label="Адрес" value={order.customer.address} />
            )}
            {order.customer.comment && (
              <InfoRow label="Комментарий" value={order.customer.comment} />
            )}
          </div>
        </div>

        {/* Status timeline */}
        <div className="p-6 bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-white/5 mb-8">
          <h3 className="text-sm font-semibold text-white mb-5">Статус заказа</h3>
          <div className="flex items-center gap-3">
            <div className="flex items-center gap-3 flex-1">
              <div className="w-9 h-9 rounded-xl bg-accent-500/20 flex items-center justify-center shrink-0">
                <Package className="w-4 h-4 text-accent-500" />
              </div>
              <div>
                <div className="text-sm font-medium text-white">Принят</div>
                <div className="text-xs text-graphite-400">Заказ зарегистрирован</div>
              </div>
            </div>
            <div className="w-8 h-px bg-graphite-700" />
            <div className="flex items-center gap-3 flex-1 opacity-40">
              <div className="w-9 h-9 rounded-xl bg-white/5 flex items-center justify-center shrink-0">
                <Truck className="w-4 h-4 text-graphite-400" />
              </div>
              <div>
                <div className="text-sm font-medium text-graphite-300">Доставка</div>
                <div className="text-xs text-graphite-500">Ожидает обработки</div>
              </div>
            </div>
          </div>
        </div>

        {/* Actions */}
        <div className="flex flex-col sm:flex-row gap-3">
          <Link
            to="/catalog"
            className="flex-1 inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-colors"
          >
            Продолжить покупки
            <ArrowRight className="w-5 h-5" />
          </Link>
          <Link
            to="/"
            className="flex-1 inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-white/5 hover:bg-white/10 border border-white/10 text-white font-semibold rounded-xl transition-colors"
          >
            На главную
          </Link>
        </div>
      </div>
    </div>
  );
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs text-graphite-500 uppercase tracking-wider">{label}</div>
      <div className="text-white mt-0.5">{value}</div>
    </div>
  );
}
