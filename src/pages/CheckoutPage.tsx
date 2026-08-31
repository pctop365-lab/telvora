import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  ArrowLeft,
  ArrowRight,
  Truck,
  Store,
  Package,
  Loader2,
  Lock,
  CreditCard,
  Banknote,
} from 'lucide-react';

import { useCart } from '@/store/cart';
import { formatPrice } from '@/lib/format';
import { siteContent } from '@/data/siteContent';
import { createOrder } from '@/services/orderService';
import type { CheckoutFormData, DeliveryMethod } from '@/types';

const deliveryOptions: {
  value: DeliveryMethod;
  label: string;
  desc: string;
  icon: typeof Truck;
}[] = [
  {
    value: 'courier',
    label: 'Курьерская доставка',
    desc: 'Доставка до двери, 1–2 дня',
    icon: Truck,
  },
  {
    value: 'pickup',
    label: 'Самовывоз',
    desc: 'Из магазина бесплатно',
    icon: Store,
  },
  {
    value: 'post',
    label: 'Транспортная компания',
    desc: 'Доставка транспортной компанией по всей России.',
    icon: Package,
  },
];

const deliveryTimeOptions = [
  '10:00–12:00',
  '12:00–14:00',
  '14:00–16:00',
  '16:00–18:00',
  '18:00–20:00',
  '20:00–22:00',
];
const paymentOptions = [
  {
    value: 'sbp',
    label: 'Оплата через СБП',
    desc: 'Перевод по QR-коду через СБП',
    icon: CreditCard,
  },
  {
    value: 'cash',
    label: 'Наличными',
    desc: 'Оплата наличными при получении',
    icon: Banknote,
  },
];

export default function CheckoutPage() {
  const { items, subtotal, clearCart } = useCart();
  const navigate = useNavigate();

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [personalDataConsent, setPersonalDataConsent] = useState(false);
  const [consentError, setConsentError] = useState(false);

  const [form, setForm] = useState<
    CheckoutFormData & { paymentMethod: string }
  >({
    fullName: '',
    phone: '',
    email: '',
    address: '',
    deliveryMethod: 'courier',
deliveryTime: '',
paymentMethod: 'cash',
comment: '',
  });

  const [touched, setTouched] = useState<Record<string, boolean>>({});

  const deliveryCost =
    form.deliveryMethod === 'pickup' ||
    subtotal >= siteContent.freeDeliveryThreshold
      ? 0
      : siteContent.deliveryFee;

  const total = subtotal + deliveryCost;

  const errors: Record<string, string> = {};

  if (!form.fullName.trim()) {
    errors.fullName = 'Укажите имя';
  }

  if (!form.phone.trim()) {
    errors.phone = 'Укажите телефон';
  } else if (form.phone.replace(/\D/g, '').length < 10) {
    errors.phone = 'Неверный формат телефона';
  }

  if (!form.email.trim()) {
    errors.email = 'Укажите email';
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    errors.email = 'Неверный формат email';
  }

  if (form.deliveryMethod !== 'pickup' && !form.address.trim()) {
    errors.address = 'Укажите адрес доставки';
  }

  const isValid = Object.keys(errors).length === 0;

  const updateField = (
    field: keyof (CheckoutFormData & { paymentMethod: string }),
    value: string
  ) => {
    setForm((prev) => ({
      ...prev,
      [field]: value,
    }));
  };

  const handleBlur = (field: string) => {
    setTouched((prev) => ({
      ...prev,
      [field]: true,
    }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    setTouched({
      fullName: true,
      phone: true,
      email: true,
      address: true,
    });

    if (!personalDataConsent) {
      setConsentError(true);
    }

    if (!isValid || items.length === 0 || !personalDataConsent) {
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      const order = await createOrder(
        form as CheckoutFormData,
        items
      );

      clearCart();
      navigate(`/order-success/${encodeURIComponent(order.orderNumber)}`, {
        state: {
          orderSummary: {
            items: order.items,
            subtotal: order.subtotal,
            delivery: order.delivery,
            total: order.total,
            createdAt: order.createdAt,
          },
        },
      });
    } catch (err) {
      console.error('Ошибка оформления заказа:', err);

      setError(
        err instanceof Error
          ? err.message
          : 'Не удалось оформить заказ. Попробуйте ещё раз.'
      );
    } finally {
      setSubmitting(false);
    }
  };

  if (items.length === 0) {
    return (
      <div className="pt-24 pb-20 min-h-screen bg-white dark:bg-graphite-900 flex items-center justify-center">
        <div className="text-center max-w-md px-4">
          <h1 className="font-display font-bold text-2xl text-graphite-900 dark:text-white mb-3">
            Корзина пуста
          </h1>

          <p className="text-graphite-600 dark:text-graphite-400 mb-8">
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
    <div className="pt-24 pb-20 bg-white dark:bg-graphite-900 min-h-screen">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">

        <Link
          to="/catalog"
          className="inline-flex items-center gap-2 text-sm text-graphite-600 dark:text-graphite-400 hover:text-graphite-900 dark:hover:text-white transition-colors mb-8"
        >
          <ArrowLeft className="w-4 h-4" />
          Продолжить покупки
        </Link>

        <h1 className="font-display font-extrabold text-4xl sm:text-5xl text-graphite-900 dark:text-white tracking-tight mb-10">
          Оформление заказа
        </h1>

        <form
          onSubmit={handleSubmit}
          className="grid grid-cols-1 lg:grid-cols-3 gap-8"
        >

          {/* Левая часть */}
          <div className="lg:col-span-2 space-y-6">

            {/* Контактные данные */}
            <div className="p-6 bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-graphite-200 dark:border-white/5">
              <h2 className="font-display font-semibold text-lg text-graphite-900 dark:text-white mb-5">
                Контактные данные
              </h2>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <FormField
                  label="Имя и фамилия"
                  value={form.fullName}
                  onChange={(v) => updateField('fullName', v)}
                  onBlur={() => handleBlur('fullName')}
                  error={
                    touched.fullName
                      ? errors.fullName
                      : undefined
                  }
                  placeholder="Иван Иванов"
                />

                <FormField
                  label="Телефон"
                  value={form.phone}
                  onChange={(v) => updateField('phone', v)}
                  onBlur={() => handleBlur('phone')}
                  error={
                    touched.phone
                      ? errors.phone
                      : undefined
                  }
                  placeholder="+7 (900) 123-45-67"
                  type="tel"
                />

                <FormField
                  label="Email"
                  value={form.email}
                  onChange={(v) => updateField('email', v)}
                  onBlur={() => handleBlur('email')}
                  error={
                    touched.email
                      ? errors.email
                      : undefined
                  }
                  placeholder="ivan@example.com"
                  type="email"
                />

                <FormField
                  label="Адрес доставки"
                  value={form.address}
                  onChange={(v) => updateField('address', v)}
                  onBlur={() => handleBlur('address')}
                  error={
                    touched.address
                      ? errors.address
                      : undefined
                  }
                  placeholder="г. Москва, ул. Тверская, д. 1, кв. 10"
                  disabled={form.deliveryMethod === 'pickup'}
                />
<div className="sm:col-span-2">
  <label className="block text-sm font-medium text-graphite-700 dark:text-graphite-300 mb-2">
    Удобное время доставки
  </label>

  <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
    {deliveryTimeOptions.map((time) => {
      const selected = form.deliveryTime === time;

      return (
        <button
          key={time}
          type="button"
          disabled={form.deliveryMethod === 'pickup'}
          onClick={() => updateField('deliveryTime', time)}
          className={`px-3 py-3 text-sm font-medium rounded-xl border transition-all ${
            selected
              ? 'bg-accent-500 text-white border-accent-500 shadow-md shadow-accent-500/20'
              : 'bg-white dark:bg-graphite-900 text-graphite-700 dark:text-graphite-300 border-graphite-200 dark:border-white/10 hover:border-accent-500/50 hover:text-accent-500'
          } ${
            form.deliveryMethod === 'pickup'
              ? 'opacity-40 cursor-not-allowed'
              : 'cursor-pointer'
          }`}
        >
          {time}
        </button>
      );
    })}
  </div>

  {form.deliveryMethod === 'pickup' && (
    <p className="text-xs text-graphite-500 mt-2">
      Для самовывоза время доставки не требуется.
    </p>
  )}
</div>

              </div>
            </div>

            {/* Способ доставки */}
            <div className="p-6 bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-graphite-200 dark:border-white/5">
              <h2 className="font-display font-semibold text-lg text-graphite-900 dark:text-white mb-5">
                Способ доставки
              </h2>

              <div className="space-y-3">

                {deliveryOptions.map((opt) => (
                  <label
                    key={opt.value}
                    className={`flex items-center gap-4 p-4 rounded-2xl border cursor-pointer transition-all ${
                      form.deliveryMethod === opt.value
                        ? 'bg-accent-500/10 border-accent-500/40'
                        : 'bg-white dark:bg-graphite-900 border-graphite-200 dark:border-white/5 hover:border-graphite-300 dark:hover:border-white/10'
                    }`}
                  >

                    <input
                      type="radio"
                      name="deliveryMethod"
                      value={opt.value}
                      checked={form.deliveryMethod === opt.value}
                      onChange={(e) =>
                        updateField(
                          'deliveryMethod',
                          e.target.value
                        )
                      }
                      className="sr-only"
                    />

                    <div
                      className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${
                        form.deliveryMethod === opt.value
                          ? 'bg-accent-500/20'
                          : 'bg-graphite-100 dark:bg-white/5'
                      }`}
                    >
                      <opt.icon
                        className={`w-5 h-5 ${
                          form.deliveryMethod === opt.value
                            ? 'text-accent-500'
                            : 'text-graphite-500 dark:text-graphite-400'
                        }`}
                      />
                    </div>

                    <div className="flex-1">
                      <div className="text-sm font-semibold text-graphite-900 dark:text-white">
                        {opt.label}
                      </div>

                      <div className="text-xs text-graphite-600 dark:text-graphite-400">
                        {opt.desc}
                      </div>
                    </div>

                    {/* Кружок выбора */}
                    <div
                      className={`w-5 h-5 rounded-full border-2 shrink-0 flex items-center justify-center ${
                        form.deliveryMethod === opt.value
                          ? 'border-accent-500 bg-accent-500'
                          : 'border-graphite-300 dark:border-graphite-600 bg-white dark:bg-graphite-900'
                      }`}
                    >
                      {form.deliveryMethod === opt.value && (
                        <div className="w-2 h-2 rounded-full bg-white dark:bg-graphite-900" />
                      )}
                    </div>

                  </label>
                ))}

              </div>
            </div>

            {/* Способ оплаты */}
            <div className="p-6 bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-graphite-200 dark:border-white/5">
              <h2 className="font-display font-semibold text-lg text-graphite-900 dark:text-white mb-5">
                Способ оплаты
              </h2>

              <div className="space-y-3">

                {paymentOptions.map((opt) => (
                  <label
                    key={opt.value}
                    className={`flex items-center gap-4 p-4 rounded-2xl border cursor-pointer transition-all ${
                      form.paymentMethod === opt.value
                        ? 'bg-accent-500/10 border-accent-500/40'
                        : 'bg-white dark:bg-graphite-900 border-graphite-200 dark:border-white/5 hover:border-graphite-300 dark:hover:border-white/10'
                    }`}
                  >

                    <input
                      type="radio"
                      name="paymentMethod"
                      value={opt.value}
                      checked={form.paymentMethod === opt.value}
                      onChange={(e) =>
                        updateField(
                          'paymentMethod',
                          e.target.value
                        )
                      }
                      className="sr-only"
                    />

                    <div
                      className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${
                        form.paymentMethod === opt.value
                          ? 'bg-accent-500/20'
                          : 'bg-graphite-100 dark:bg-white/5'
                      }`}
                    >
                      <opt.icon
                        className={`w-5 h-5 ${
                          form.paymentMethod === opt.value
                            ? 'text-accent-500'
                            : 'text-graphite-500 dark:text-graphite-400'
                        }`}
                      />
                    </div>

                    <div className="flex-1">
                      <div className="text-sm font-semibold text-graphite-900 dark:text-white">
                        {opt.label}
                      </div>

                      <div className="text-xs text-graphite-600 dark:text-graphite-400">
                        {opt.desc}
                      </div>
                    </div>

                    {/* Кружок выбора */}
                    <div
                      className={`w-5 h-5 rounded-full border-2 shrink-0 flex items-center justify-center ${
                        form.paymentMethod === opt.value
                          ? 'border-accent-500 bg-accent-500'
                          : 'border-graphite-300 dark:border-graphite-600 bg-white dark:bg-graphite-900'
                      }`}
                    >
                      {form.paymentMethod === opt.value && (
                        <div className="w-2 h-2 rounded-full bg-white dark:bg-graphite-900" />
                      )}
                    </div>

                  </label>
                ))}

              </div>
            </div>

            {/* Комментарий */}
            <div className="p-6 bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-graphite-200 dark:border-white/5">
              <h2 className="font-display font-semibold text-lg text-graphite-900 dark:text-white mb-3">
                Комментарий к заказу
              </h2>

              <textarea
                value={form.comment}
                onChange={(e) =>
                  updateField('comment', e.target.value)
                }
                placeholder="Дополнительные пожелания, удобное время доставки..."
                rows={3}
                className="w-full px-4 py-3 text-sm bg-white dark:bg-graphite-900 border border-graphite-200 dark:border-white/10 rounded-xl text-graphite-900 dark:text-white placeholder:text-graphite-400 dark:placeholder:text-graphite-500 focus:outline-none focus:border-accent-500/50 transition-colors resize-none"
              />
            </div>

          </div>

          {/* Правая часть */}
          <div className="lg:col-span-1">

            <div className="sticky top-24 p-6 bg-graphite-100 dark:bg-graphite-800 rounded-3xl border border-graphite-200 dark:border-white/5">

              <h2 className="font-display font-semibold text-lg text-graphite-900 dark:text-white mb-5">
                Ваш заказ
              </h2>

              <div className="space-y-3 mb-5 max-h-64 overflow-y-auto hide-scrollbar">

                {items.map((item) => (
                  <div
                    key={item.id}
                    className="flex gap-3"
                  >

                    <div className="w-14 h-14 rounded-lg overflow-hidden bg-white dark:bg-graphite-900 shrink-0">
                      <img
                        src={item.image}
                        alt={item.name}
                        className="w-full h-full object-cover"
                      />
                    </div>

                    <div className="flex-1 min-w-0">

                      <div className="text-sm font-medium text-graphite-900 dark:text-white truncate">
                        {item.name}
                      </div>

                      <div className="text-xs text-graphite-600 dark:text-graphite-400">
                        {item.screenSize} · {item.quantity} шт
                      </div>

                    </div>

                    <div className="text-sm font-semibold text-graphite-900 dark:text-white shrink-0">
                      {formatPrice(
                        item.price * item.quantity
                      )}
                    </div>

                  </div>
                ))}

              </div>

              <div className="space-y-2 pt-4 border-t border-graphite-200 dark:border-white/10">

                <div className="flex justify-between text-sm">
                  <span className="text-graphite-600 dark:text-graphite-400">
                    Товары ({items.length})
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
                    {deliveryCost === 0
                      ? 'Бесплатно'
                      : formatPrice(deliveryCost)}
                  </span>

                </div>

                <div className="flex justify-between items-center pt-3 border-t border-graphite-200 dark:border-white/10">

                  <span className="text-base font-semibold text-graphite-900 dark:text-white">
                    Итого
                  </span>

                  <span className="text-2xl font-bold text-graphite-900 dark:text-white">
                    {formatPrice(total)}
                  </span>

                </div>

              </div>

              {error && (
                <p className="text-sm text-red-400 mt-4">
                  {error}
                </p>
              )}

              <div
                className={`mt-5 p-4 rounded-2xl border transition-colors ${
                  consentError
                    ? 'bg-red-50 dark:bg-red-500/5 border-red-300 dark:border-red-500/30'
                    : 'bg-white dark:bg-graphite-900 border-graphite-200 dark:border-white/10'
                }`}
              >
                <div className="flex items-start gap-3">
                  <input
                    id="personal-data-consent"
                    type="checkbox"
                    checked={personalDataConsent}
                    onChange={(event) => {
                      setPersonalDataConsent(event.target.checked);
                      if (event.target.checked) setConsentError(false);
                    }}
                    aria-invalid={consentError}
                    aria-describedby={consentError ? 'personal-data-consent-error' : undefined}
                    className="mt-0.5 h-5 w-5 shrink-0 rounded border-graphite-300 text-accent-500 accent-accent-500 focus:ring-2 focus:ring-accent-500 focus:ring-offset-2 dark:focus:ring-offset-graphite-900 cursor-pointer"
                  />
                  <p className="text-sm text-graphite-700 dark:text-graphite-300 leading-relaxed">
                    <label htmlFor="personal-data-consent" className="cursor-pointer">
                      Я даю{' '}
                    </label>
                    <Link
                      to="/personal-data-consent"
                      target="_blank"
                      rel="noopener noreferrer"
                      className="font-medium text-accent-600 dark:text-accent-500 hover:underline focus:outline-none focus:ring-2 focus:ring-accent-500/40 rounded"
                    >
                      согласие на обработку персональных данных
                    </Link>{' '}
                    и ознакомился с{' '}
                    <Link
                      to="/privacy"
                      target="_blank"
                      rel="noopener noreferrer"
                      className="font-medium text-accent-600 dark:text-accent-500 hover:underline focus:outline-none focus:ring-2 focus:ring-accent-500/40 rounded"
                    >
                      Политикой обработки персональных данных
                    </Link>.
                  </p>
                </div>

                {consentError && (
                  <p id="personal-data-consent-error" role="alert" className="mt-3 text-sm text-red-600 dark:text-red-400">
                    Для оформления заказа необходимо подтвердить согласие на обработку персональных данных.
                  </p>
                )}

                <p className="mt-3 pl-8 text-xs text-graphite-500 dark:text-graphite-400 leading-relaxed">
                  Перед оформлением заказа вы также можете ознакомиться с условиями{' '}
                  <Link to="/delivery" target="_blank" rel="noopener noreferrer" className="text-accent-600 dark:text-accent-500 hover:underline">доставки</Link>,{' '}
                  <Link to="/returns" target="_blank" rel="noopener noreferrer" className="text-accent-600 dark:text-accent-500 hover:underline">возврата</Link>{' '}
                  и{' '}
                  <Link to="/warranty" target="_blank" rel="noopener noreferrer" className="text-accent-600 dark:text-accent-500 hover:underline">гарантии</Link>. Также доступен{' '}
                  <Link to="/offer" target="_blank" rel="noopener noreferrer" className="text-accent-600 dark:text-accent-500 hover:underline">проект публичной оферты</Link>.
                </p>
              </div>

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

function FormField({
  label,
  value,
  onChange,
  onBlur,
  error,
  placeholder,
  type = 'text',
  disabled,
}: FormFieldProps) {
  return (
    <div>

      <label className="block text-sm font-medium text-graphite-700 dark:text-graphite-300 mb-2">
        {label}
      </label>

      <input
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onBlur}
        placeholder={placeholder}
        disabled={disabled}
        className={`w-full px-4 py-3 text-sm bg-white dark:bg-graphite-900 border rounded-xl text-graphite-900 dark:text-white placeholder:text-graphite-400 dark:placeholder:text-graphite-500 focus:outline-none transition-colors ${
          error
            ? 'border-red-500/50 focus:border-red-500'
            : 'border-graphite-200 dark:border-white/10 focus:border-accent-500/50'
        } disabled:opacity-40 disabled:cursor-not-allowed`}
      />

      {error && (
        <p className="text-xs text-red-400 mt-1.5">
          {error}
        </p>
      )}

    </div>
  );
}
