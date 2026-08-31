import { Link, useLocation, useParams } from 'react-router-dom';
import { ArrowRight, CheckCircle2, Package } from 'lucide-react';
import type { OrderItem } from '@/types';
import { formatPrice } from '@/lib/format';

type OrderSummary = {
  items: OrderItem[];
  subtotal: number;
  delivery: number;
  total: number;
  createdAt: string;
};

type SuccessLocationState = {
  orderSummary?: OrderSummary;
};

export default function OrderSuccessPage() {
  const { orderNumber } = useParams<{ orderNumber: string }>();
  const location = useLocation();
  const summary = (location.state as SuccessLocationState | null)?.orderSummary;

  if (!orderNumber) {
    return (
      <div className="pt-24 pb-20 min-h-screen bg-white dark:bg-graphite-900 flex items-center justify-center">
        <div className="text-center max-w-md px-4">
          <h1 className="font-display font-bold text-2xl text-graphite-900 dark:text-white mb-3">Номер заказа не указан</h1>
          <Link to="/" className="inline-flex items-center gap-2 px-6 py-3 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl">На главную</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="pt-24 pb-20 bg-white dark:bg-graphite-900 min-h-screen">
      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-12">
          <div className="inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-green-500/10 mb-6 animate-scale-in">
            <CheckCircle2 className="w-10 h-10 text-green-500" />
          </div>
          <h1 className="font-display font-extrabold text-3xl sm:text-4xl text-graphite-900 dark:text-white tracking-tight mb-3">Заказ оформлен!</h1>
          <p className="text-graphite-600 dark:text-graphite-400 text-lg max-w-md mx-auto">Спасибо за покупку. Мы свяжемся с вами для подтверждения заказа.</p>
        </div>

        <div className="p-6 sm:p-8 bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-graphite-200 dark:border-white/5 mb-6">
          <div className={`flex flex-col sm:flex-row sm:items-center justify-between gap-4 ${summary ? 'mb-6 pb-6 border-b border-graphite-200 dark:border-white/10' : ''}`}>
            <div>
              <span className="text-xs text-graphite-500 dark:text-graphite-400 uppercase tracking-wider">Номер заказа</span>
              <div className="font-display font-bold text-2xl text-graphite-900 dark:text-white mt-1">{orderNumber}</div>
            </div>
            {summary && (
              <div className="text-left sm:text-right">
                <span className="text-xs text-graphite-500 dark:text-graphite-400 uppercase tracking-wider">Дата</span>
                <div className="text-graphite-900 dark:text-white font-medium mt-1">{new Date(summary.createdAt).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })}</div>
              </div>
            )}
          </div>

          {summary ? (
            <>
              <div className="space-y-4 mb-6">
                <h2 className="text-sm font-semibold text-graphite-900 dark:text-white">Состав заказа</h2>
                {summary.items.map((item) => (
                  <div key={item.productId} className="flex gap-4">
                    <div className="w-16 h-16 rounded-xl overflow-hidden bg-white dark:bg-graphite-900 shrink-0"><img src={item.image} alt={item.name} className="w-full h-full object-cover" /></div>
                    <div className="flex-1 min-w-0"><div className="text-sm font-semibold text-graphite-900 dark:text-white truncate">{item.name}</div><div className="text-xs text-graphite-500 dark:text-graphite-400">{item.screenSize} · {item.quantity} шт.</div></div>
                    <div className="text-sm font-bold text-graphite-900 dark:text-white shrink-0">{formatPrice(item.price * item.quantity)}</div>
                  </div>
                ))}
              </div>
              <div className="space-y-2 pt-6 border-t border-graphite-200 dark:border-white/10">
                <div className="flex justify-between text-sm"><span className="text-graphite-500 dark:text-graphite-400">Товары</span><span className="font-medium">{formatPrice(summary.subtotal)}</span></div>
                <div className="flex justify-between text-sm"><span className="text-graphite-500 dark:text-graphite-400">Доставка</span><span className="font-medium">{summary.delivery === 0 ? 'Бесплатно' : formatPrice(summary.delivery)}</span></div>
                <div className="flex justify-between items-center pt-3 border-t border-graphite-200 dark:border-white/10"><span className="font-semibold">Итого</span><span className="text-2xl font-bold">{formatPrice(summary.total)}</span></div>
              </div>
            </>
          ) : (
            <p className="mt-4 text-sm text-graphite-600 dark:text-graphite-400 leading-relaxed">Заказ зарегистрирован. Сохраните его номер — он понадобится для проверки статуса и обращения в поддержку.</p>
          )}
        </div>

        <div className="p-6 bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-graphite-200 dark:border-white/5 mb-8">
          <h2 className="text-sm font-semibold text-graphite-900 dark:text-white mb-5">Подтверждение оформления</h2>
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-xl bg-accent-500/20 flex items-center justify-center shrink-0"><Package className="w-4 h-4 text-accent-500" /></div>
            <div><div className="text-sm font-medium">Заказ успешно принят системой</div><div className="text-xs text-graphite-500 dark:text-graphite-400">Актуальный статус можно проверить на странице поддержки</div></div>
          </div>
        </div>

        <div className="flex flex-col sm:flex-row gap-3">
          <Link to="/catalog" className="flex-1 inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl">Продолжить покупки<ArrowRight className="w-5 h-5" /></Link>
          <Link to="/" className="flex-1 inline-flex items-center justify-center px-6 py-3.5 bg-graphite-100 hover:bg-graphite-200 dark:bg-white/5 dark:hover:bg-white/10 border border-graphite-200 dark:border-white/10 font-semibold rounded-xl">На главную</Link>
        </div>
      </div>
    </div>
  );
}
