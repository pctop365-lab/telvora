import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Truck, Store, Package, Loader2, Lock } from 'lucide-react';
import { useCart } from '@/store/cart';
import { formatPrice } from '@/lib/format';
import { siteContent } from '@/data/siteContent';
import { createOrder } from '@/services/orderService';
import type { CheckoutFormData, DeliveryMethod } from '@/types';

const deliveryOptions: { value: DeliveryMethod; label: string; desc: string; icon: typeof Truck }[] = [
  { value: 'courier', label: 'Курьерская доставка', desc: 'Доставка до двери, 1–3 дня', icon: Truck },
  { value: 'pickup', label: 'Самовывоз', desc: 'Из магазина бесплатно', icon: Store },
  { value: 'post', label: 'Почта России', desc: 'Отправка по всей стране, 3–7 дней', icon: Package },
];

export default function CheckoutPage() {
  const { items, subtotal, clearCart } = useCart();
  const navigate = useNavigate();
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [form, setForm] = useState<CheckoutFormData>({
    fullName: '',
    phone: '',
    email: '',
    address: '',
    deliveryMethod: 'courier',
    comment: '',
  });

  const [touched, setTouched] = useState<Record<string, boolean>>({});

  const deliveryCost =
    form.deliveryMethod === 'pickup' || subtotal >= siteContent.freeDeliveryThreshold
      ? 0
      : siteContent.deliveryFee;
  const total = subtotal + deliveryCost;

  const errors: Record<string, string> = {};
  if (!form.fullName.trim()) errors.fullName = 'Укажите имя';
  if (!form.phone.trim()) errors.phone = 'Укажите телефон';
  else if (form.phone.replace(/\D/g, '').length < 10) errors.phone = 'Неверный формат телефона';
  if (!form.email.trim()) errors.email = 'Укажите email';
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) errors.email = 'Неверный формат email';
  if (form.deliveryMethod !== 'pickup' && !form.address.trim())
    errors.address = 'Укажите адрес доставки';

  const isValid = Object.keys(errors).length === 0;

  const updateField = (field: keyof CheckoutFormData, value: string) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const handleBlur = (field: string) => {
    setTouched((prev) => ({ ...prev, [field]: true }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setTouched({ fullName: true, phone: true, email: true, address: true });
    if (!isValid || items.length === 0) return;

    setSubmitting(true);
    setError(null);
    try {
      const order = await createOrder(form, items);
      clearCart();
      navigate(`/order-success/${order.id}`);
    } catch {
      setError('Не удалось оформить заказ. Попробуйте ещё раз.');
    } finally {
      setSubmitting(false);
    }
  };

  if (items.length === 0) {
    return (
      <div className="pt-24 pb-20 min-h-screen bg-graphite-900 flex items-center justify-center">
        <div className="text-center max-w-md px-4">
          <h1 className="font-display font-bold text-2xl text-white mb-3">
            Корзина пуста
          </h1>
          <p className="text-graphite-400 mb-8">
            Добавьте телевизоры в корзину, чтобы оформить заказ.
          </p>
          <Link
            to="/catalog"
            className="inline-flex items-center gap-2 px-6 py-3 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-colors"
          >
            В каталог
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="pt-24 pb-20 bg-graphite-900 min-h-screen">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
        <Link
          to="/catalog"
          className="inline-flex items-center gap-2 text-sm text-graphite-400 hover:text-white transition-colors mb-8"
        >
          <ArrowLeft className="w-4 h-4" />
          Продолжить покупки
        </Link>

        <h1 className="font-display font-extrabold text-4xl sm:text-5xl text-white tracking-tight mb-10">
          Оформление заказа
        </h1>

        <form onSubmit={handleSubmit} className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Form fields */}
          <div className="lg:col-span-2 space-y-6">
            {/* Contact info */}
            <div className="p-6 bg-graphite-800 rounded-3xl border border-white/5">
              <h2 className="font-display font-semibold text-lg text-white mb-5">
                Контактные данные
              </h2>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormField
                  label="Имя и фамилия"
                  value={form.fullName}
                  onChange={(v) => updateField('fullName', v)}
                  onBlur={() => handleBlur('fullName')}
                  error={touched.fullName ? errors.fullName : undefined}
                  placeholder="Иван Иванов"
                />
                <FormField
                  label="Телефон"
                  value={form.phone}
                  onChange={(v) => updateField('phone', v)}
                  onBlur={() => handleBlur('phone')}
                  error={touched.phone ? errors.phone : undefined}
                  placeholder="+7 (900) 123-45-67"
                  type="tel"
                />
                <FormField
                  label="Email"
                  value={form.email}
                  onChange={(v) => updateField('email', v)}
                  onBlur={() => handleBlur('email')}
                  error={touched.email ? errors.email : undefined}
                  placeholder="ivan@example.com"
                  type="email"
                />
                <FormField
                  label="Адрес доставки"
                  value={form.address}
                  onChange={(v) => updateField('address', v)}
                  onBlur={() => handleBlur('address')}
                  error={touched.address ? errors.address : undefined}
                  placeholder="г. Москва, ул. Тверская, д. 1, кв. 10"
                  disabled={form.deliveryMethod === 'pickup'}
                />
              </div>
            </div>

            {/* Delivery method */}
            <div className="p-6 bg-graphite-800 rounded-3xl border border-white/5">
              <h2 className="font-display font-semibold text-lg text-white mb-5">
                Способ доставки
              </h2>
              <div className="space-y-3">
                {deliveryOptions.map((opt) => (
                  <label
                    key={opt.value}
                    className={`flex items-center gap-4 p-4 rounded-2xl border cursor-pointer transition-all ${
                      form.deliveryMethod === opt.value
                        ? 'bg-accent-500/10 border-accent-500/40'
                        : 'bg-graphite-900 border-white/5 hover:border-white/10'
                    }`}
                  >
                    <input
                      type="radio"
                      name="deliveryMethod"
                      value={opt.value}
                      checked={form.deliveryMethod === opt.value}
                      onChange={(e) => updateField('deliveryMethod', e.target.value)}
                      className="sr-only"
                    />
                    <div
                      className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${
                        form.deliveryMethod === opt.value
                          ? 'bg-accent-500/20'
                          : 'bg-white/5'
                      }`}
                    >
                      <opt.icon
                        className={`w-5 h-5 ${
                          form.deliveryMethod === opt.value ? 'text-accent-500' : 'text-graphite-400'
                        }`}
                      />
                    </div>
                    <div className="flex-1">
                      <div className="text-sm font-semibold text-white">{opt.label}</div>
                      <div className="text-xs text-graphite-400">{opt.desc}</div>
                    </div>
                    <div
                      className={`w-5 h-5 rounded-full border-2 shrink-0 ${
                        form.deliveryMethod === opt.value
                          ? 'border-accent-500 bg-accent-500'
                          : 'border-graphite-600'
                      }`}
                    >
                      {form.deliveryMethod === opt.value && (
                        <div className="w-full h-full rounded-full border-[3px] border-graphite-800 bg-accent-500" />
                      )}
                    </div>
                  </label>
                ))}
              </div>
            </div>

            {/* Comment */}
            <div className="p-6 bg-graphite-800 rounded-3xl border border-white/5">
              <h2 className="font-display font-semibold text-lg text-white mb-3">
                Комментарий к заказу
              </h2>
              <textarea
                value={form.comment}
                onChange={(e) => updateField('comment', e.target.value)}
                placeholder="Дополнительные пожелания, удобное время доставки..."
                rows={3}
                className="w-full px-4 py-3 text-sm bg-graphite-900 border border-white/10 rounded-xl text-white placeholder:text-graphite-500 focus:outline-none focus:border-accent-500/50 transition-colors resize-none"
              />
            </div>
          </div>

          {/* Order summary */}
          <div className="lg:col-span-1">
            <div className="sticky top-24 p-6 bg-graphite-800 rounded-3xl border border-white/5">
              <h2 className="font-display font-semibold text-lg text-white mb-5">
                Ваш заказ
              </h2>

              <div className="space-y-3 mb-5 max-h-64 overflow-y-auto hide-scrollbar">
                {items.map((item) => (
                  <div key={item.id} className="flex gap-3">
                    <div className="w-14 h-14 rounded-lg overflow-hidden bg-graphite-900 shrink-0">
                      <img
                        src={item.image}
                        alt={item.name}
                        className="w-full h-full object-cover"
                      />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="text-sm font-medium text-white truncate">
                        {item.name}
                      </div>
                      <div className="text-xs text-graphite-400">
                        {item.screenSize} · {item.quantity} шт
                      </div>
                    </div>
                    <div className="text-sm font-semibold text-white shrink-0">
                      {formatPrice(item.price * item.quantity)}
                    </div>
                  </div>
                ))}
              </div>

              <div className="space-y-2 pt-4 border-t border-white/10">
                <div className="flex justify-between text-sm">
                  <span className="text-graphite-400">Товары ({items.length})</span>
                  <span className="text-white font-medium">{formatPrice(subtotal)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-graphite-400">Доставка</span>
                  <span className="text-white font-medium">
                    {deliveryCost === 0 ? 'Бесплатно' : formatPrice(deliveryCost)}
                  </span>
                </div>
                <div className="flex justify-between items-center pt-3 border-t border-white/10">
                  <span className="text-base font-semibold text-white">Итого</span>
                  <span className="text-2xl font-bold text-white">{formatPrice(total)}</span>
                </div>
              </div>

              {error && (
                <p className="text-sm text-red-400 mt-4">{error}</p>
              )}

              <button
                type="submit"
                disabled={submitting}
                className="w-full flex items-center justify-center gap-2 px-6 py-4 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-all shadow-lg shadow-accent-500/30 mt-5 disabled:opacity-60 disabled:cursor-not-allowed"
              >
                {submitting ? (
                  <>
                    <Loader2 className="w-5 h-5 animate-spin" />
                    Оформляем...
                  </>
                ) : (
                  <>
                    Разместить заказ
                    <ArrowRight className="w-5 h-5" />
                  </>
                )}
              </button>

              <div className="flex items-center justify-center gap-2 mt-4 text-xs text-graphite-500">
                <Lock className="w-3.5 h-3.5" />
                Ваши данные защищены
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
}

type FormFieldProps = {
  label: string;
  value: string;
  onChange: (value: string) => void;
  onBlur: () => void;
  error?: string;
  placeholder?: string;
  type?: string;
  disabled?: boolean;
};

function FormField({ label, value, onChange, onBlur, error, placeholder, type = 'text', disabled }: FormFieldProps) {
  return (
    <div>
      <label className="block text-sm font-medium text-graphite-300 mb-2">{label}</label>
      <input
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onBlur}
        placeholder={placeholder}
        disabled={disabled}
        className={`w-full px-4 py-3 text-sm bg-graphite-900 border rounded-xl text-white placeholder:text-graphite-500 focus:outline-none transition-colors ${
          error
            ? 'border-red-500/50 focus:border-red-500'
            : 'border-white/10 focus:border-accent-500/50'
        } disabled:opacity-40 disabled:cursor-not-allowed`}
      />
      {error && <p className="text-xs text-red-400 mt-1.5">{error}</p>}
    </div>
  );
}
