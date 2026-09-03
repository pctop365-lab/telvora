import {
  X,
  Plus,
  Minus,
  Trash2,
  ShoppingBag,
  ArrowRight,
} from 'lucide-react';
import { Link, useNavigate } from 'react-router-dom';
import { useCart } from '@/store/cart';
import { useUI } from '@/store/ui';
import { formatPrice } from '@/lib/format';
import { siteContent } from '@/data/siteContent';
import AvailabilityStatus from './AvailabilityStatus';

export default function CartDrawer() {
  const { cartOpen, closeCart } = useUI();
  const {
    items,
    count,
    subtotal,
    delivery,
    total,
    updateQuantity,
    removeFromCart,
  } = useCart();

  const navigate = useNavigate();

  const handleCheckout = () => {
    closeCart();
    navigate('/checkout');
  };

  if (!cartOpen) return null;

  return (
    <div className="fixed inset-0 z-[65]">
      <div
        className="absolute inset-0 bg-black/40 dark:bg-graphite-950/80 backdrop-blur-sm animate-fade-in"
        onClick={closeCart}
      />

      <div className="absolute right-0 top-0 bottom-0 w-full max-w-md bg-white dark:bg-graphite-800 border-l border-graphite-200 dark:border-white/10 flex flex-col animate-slide-in-right shadow-2xl">
        <div className="flex items-center justify-between p-6 border-b border-graphite-200 dark:border-white/10">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-accent-500/10 flex items-center justify-center">
              <ShoppingBag className="w-5 h-5 text-accent-500" />
            </div>

            <div>
              <h3 className="font-display font-bold text-lg text-graphite-900 dark:text-white">
                Корзина
              </h3>

              <p className="text-xs text-graphite-600 dark:text-graphite-400">
                {count > 0 ? `${count} товар(ов)` : 'Пусто'}
              </p>
            </div>
          </div>

          <button
            onClick={closeCart}
            className="w-10 h-10 flex items-center justify-center text-graphite-700 dark:text-white hover:bg-graphite-100 dark:hover:bg-white/10 rounded-xl transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {items.length === 0 ? (
          <div className="flex-1 flex flex-col items-center justify-center px-6 text-center">
            <div className="w-20 h-20 rounded-3xl bg-graphite-100 dark:bg-white/5 flex items-center justify-center mb-4">
              <ShoppingBag className="w-10 h-10 text-graphite-400 dark:text-graphite-600" />
            </div>

            <h4 className="font-display font-semibold text-lg text-graphite-900 dark:text-white mb-2">
              Корзина пуста
            </h4>

            <p className="text-sm text-graphite-600 dark:text-graphite-400 mb-6">
              Добавьте телевизоры из каталога, чтобы оформить заказ.
            </p>

            <Link
              to="/catalog"
              onClick={closeCart}
              className="px-6 py-3 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-colors"
            >
              В каталог
            </Link>
          </div>
        ) : (
          <>
            <div className="flex-1 overflow-y-auto p-6 space-y-4">
              {items.map((item) => (
                <div
                  key={item.id}
                  className="flex gap-4 p-3 bg-graphite-50 dark:bg-graphite-900 rounded-2xl border border-graphite-200 dark:border-white/5"
                >
                  <div className="w-20 h-20 rounded-xl overflow-hidden bg-graphite-100 dark:bg-graphite-800 shrink-0">
                    <img
                      src={item.image}
                      alt={item.name}
                      className="w-full h-full object-cover"
                    />
                  </div>

                  <div className="flex-1 min-w-0">
                    <h4 className="text-sm font-semibold text-graphite-900 dark:text-white truncate">
                      {item.name}
                    </h4>

                    <p className="text-xs text-graphite-600 dark:text-graphite-400 mt-0.5">
                      {item.screenSize} · {item.category}
                    </p>
                    {item.availability && <div className="mt-1"><AvailabilityStatus availability={item.availability} compact /></div>}
                    {item.validationError && <p className="mt-1 text-xs text-red-500">{item.validationError}</p>}

                    <div className="flex items-center justify-between mt-3">
                      <div className="flex items-center gap-2 bg-white dark:bg-graphite-800 rounded-lg p-1 border border-graphite-200 dark:border-transparent">
                        <button
                          onClick={() => updateQuantity(item.id, -1)}
                          className="w-7 h-7 flex items-center justify-center text-graphite-600 dark:text-graphite-300 hover:text-graphite-900 dark:hover:text-white hover:bg-graphite-100 dark:hover:bg-white/10 rounded-md transition-colors"
                        >
                          <Minus className="w-3.5 h-3.5" />
                        </button>

                        <span className="text-sm font-semibold text-graphite-900 dark:text-white w-6 text-center">
                          {item.quantity}
                        </span>

                        <button
                          onClick={() => updateQuantity(item.id, 1)}
                          className="w-7 h-7 flex items-center justify-center text-graphite-600 dark:text-graphite-300 hover:text-graphite-900 dark:hover:text-white hover:bg-graphite-100 dark:hover:bg-white/10 rounded-md transition-colors"
                        >
                          <Plus className="w-3.5 h-3.5" />
                        </button>
                      </div>

                      <div className="text-right">
                        <div className="text-sm font-bold text-graphite-900 dark:text-white">
                          {formatPrice(item.price * item.quantity)}
                        </div>

                        <button
                          onClick={() => removeFromCart(item.id)}
                          className="text-xs text-graphite-500 hover:text-accent-500 transition-colors mt-1 flex items-center gap-1 ml-auto"
                        >
                          <Trash2 className="w-3 h-3" />
                          Убрать
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>

            <div className="border-t border-graphite-200 dark:border-white/10 p-6 space-y-3">
              <div className="flex justify-between text-sm">
                <span className="text-graphite-600 dark:text-graphite-400">
                  Сумма товаров
                </span>

                <span className="text-graphite-900 dark:text-white font-medium">
                  {formatPrice(subtotal)}
                </span>
              </div>

              <div className="flex justify-between text-sm">
                <span className="text-graphite-600 dark:text-graphite-400">
                  Доставка
                </span>

                <span className="text-graphite-900 dark:text-white font-medium">
                  {delivery === 0 ? 'Бесплатно' : formatPrice(delivery)}
                </span>
              </div>

              {subtotal > 0 &&
                subtotal < siteContent.freeDeliveryThreshold && (
                  <p className="text-xs text-accent-500">
                    Добавьте товаров ещё на{' '}
                    {formatPrice(
                      siteContent.freeDeliveryThreshold - subtotal
                    )}{' '}
                    для бесплатной доставки
                  </p>
                )}

              <div className="flex justify-between items-center pt-3 border-t border-graphite-200 dark:border-white/10">
                <span className="text-base font-semibold text-graphite-900 dark:text-white">
                  Итого
                </span>

                <span className="text-2xl font-bold text-graphite-900 dark:text-white">
                  {formatPrice(total)}
                </span>
              </div>

              <button
                onClick={handleCheckout}
                className="w-full flex items-center justify-center gap-2 px-6 py-4 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-all shadow-lg shadow-accent-500/30 mt-2"
              >
                Оформить заказ
                <ArrowRight className="w-5 h-5" />
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
