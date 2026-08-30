import { useState } from 'react';
import { ChevronDown, Search, PackageCheck } from 'lucide-react';

const faqItems = [
  {
    question: 'Как выбрать подходящий телевизор?',
    answer:
      'При выборе телевизора мы рекомендуем учитывать диагональ экрана, разрешение, тип матрицы, расстояние до места просмотра и задачи, для которых он будет использоваться. Если вы сомневаетесь между несколькими моделями, специалисты TELVORA помогут подобрать подходящий вариант.',
  },
  {
    question: 'Какую диагональ телевизора выбрать?',
    answer:
      'Оптимальная диагональ зависит прежде всего от расстояния до экрана и размеров помещения. Для небольшой комнаты чаще подходят компактные модели, а для просторной гостиной можно выбрать телевизор большей диагонали.',
  },
  {
    question: 'В чём разница между OLED, QLED и LED?',
    answer:
      'OLED обеспечивает глубокий чёрный цвет и высокий контраст. QLED использует квантовые точки для получения яркого и насыщенного изображения. LED-телевизоры являются универсальным вариантом и могут быть интересны благодаря сочетанию характеристик и стоимости.',
  },
  {
    question: 'Что означает 4K Ultra HD?',
    answer:
      '4K Ultra HD — разрешение экрана 3840 × 2160 пикселей. По сравнению с Full HD оно обеспечивает более детализированное изображение и особенно заметно раскрывается на больших диагоналях.',
  },
  {
    question: 'Зачем нужна высокая частота обновления экрана?',
    answer:
      'Более высокая частота обновления помогает сделать движение на экране более плавным. Это особенно полезно при просмотре спортивных трансляций и использовании телевизора для игр.',
  },
  {
    question: 'Есть ли у телевизоров TELVORA гарантия?',
    answer:
      'Гарантийные условия зависят от конкретного товара. Подробную информацию о гарантии и условиях сервисного обслуживания можно получить у специалистов TELVORA перед покупкой.',
  },
  {
    question: 'Можно ли заказать профессиональную установку?',
    answer:
      'Да. Для выбранных товаров может быть доступна профессиональная установка. Условия и стоимость услуги зависят от конкретного заказа и адреса клиента.',
  },
  {
    question: 'Как осуществляется доставка?',
    answer:
      'Мы предлагаем несколько вариантов доставки. Доступный способ, стоимость и сроки зависят от выбранного товара, региона и способа получения заказа.',
  },
  {
    question: 'Какие способы оплаты доступны?',
    answer:
      'На этапе оформления заказа покупатель сможет выбрать доступный способ оплаты из представленных на сайте вариантов.',
  },
  {
    question: 'Можно ли вернуть или обменять товар?',
    answer:
      'Условия возврата и обмена зависят от категории товара и причины обращения. Перед оформлением возврата рекомендуем связаться со службой поддержки TELVORA и уточнить порядок действий.',
  },
  {
    question: 'Как узнать статус заказа?',
    answer:
      'Для уточнения статуса заказа подготовьте номер заказа и контактные данные, указанные при оформлении. Служба поддержки TELVORA поможет проверить актуальную информацию.',
  },
  {
    question: 'Можно ли получить консультацию перед покупкой?',
    answer:
      'Да. Специалисты TELVORA помогут сравнить модели, подобрать диагональ и характеристики, а также ответят на вопросы по оформлению заказа, доставке и установке.',
  },
];

export default function SupportPage() {
  const [openFaq, setOpenFaq] = useState<number | null>(null);
  const [orderNumber, setOrderNumber] = useState('');
  const [phone, setPhone] = useState('');
  const [trackingLoading, setTrackingLoading] = useState(false);
  const [trackingError, setTrackingError] = useState('');
  const [trackingResult, setTrackingResult] = useState<{
    id: number;
    status: string;
  } | null>(null);

  const trackOrder = async () => {
    const value = orderNumber.trim();
    const phoneValue = phone.trim();

    if (!value || !phoneValue) {
      setTrackingError('Введите номер заказа и телефон');
      setTrackingResult(null);
      return;
    }

    setTrackingLoading(true);
    setTrackingError('');
    setTrackingResult(null);

    try {
      const response = await fetch(
        '/manager.php?action=track_order',
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            order_number: value,
            phone: phoneValue,
          }),
        }
      );

      const data = await response.json();

      if (!response.ok || !data.success) {
        setTrackingError(data.message || 'Заказ не найден');
        return;
      }

      setTrackingResult(data.order);
    } catch {
      setTrackingError('Не удалось получить статус заказа');
    } finally {
      setTrackingLoading(false);
    }
  };

  const toggleFaq = (index: number) => {
    setOpenFaq((current) => (current === index ? null : index));
  };

  return (
    <main className="min-h-screen bg-graphite-50 dark:bg-graphite-950 text-graphite-900 dark:text-white py-20 sm:py-28">
      <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-12">
          <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
            TELVORA
          </span>

          <h1 className="font-display font-extrabold text-4xl sm:text-5xl mt-3 tracking-tight">
            Поддержка клиентов
          </h1>

          <p className="text-graphite-600 dark:text-graphite-400 text-lg mt-5 max-w-2xl mx-auto">
            Мы поможем с выбором товара, оформлением заказа и вопросами
            по продукции TELVORA.
          </p>
        </div>

        <section className="mb-10">
          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm dark:shadow-none">
            <div className="flex items-center gap-3 mb-3">
              <div className="w-11 h-11 rounded-xl bg-accent-500/10 flex items-center justify-center">
                <PackageCheck className="w-5 h-5 text-accent-500" />
              </div>

              <div>
                <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
                  TELVORA
                </span>

                <h2 className="font-display font-bold text-2xl">
                  Отследить заказ
                </h2>
              </div>
            </div>

            <p className="text-graphite-600 dark:text-graphite-400 mb-5">
              Введите номер заказа, чтобы узнать его текущий статус.
            </p>

            <div className="flex flex-col sm:flex-row gap-3">
              <input
                type="text"
                required
                value={orderNumber}
                onChange={(e) => {
                  setOrderNumber(e.target.value);
                  setTrackingError('');
                }}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    trackOrder();
                  }
                }}
                placeholder="Например, 21"
                className="flex-1 px-4 py-3 rounded-xl bg-white dark:bg-graphite-950 border border-graphite-200 dark:border-white/10 text-graphite-900 dark:text-white outline-none focus:border-accent-500"
              />

              <input
                type="tel"
                required
                autoComplete="tel"
                value={phone}
                onChange={(e) => {
                  setPhone(e.target.value);
                  setTrackingError('');
                }}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    trackOrder();
                  }
                }}
                placeholder="Телефон, указанный при оформлении"
                aria-label="Телефон, указанный при оформлении"
                className="flex-1 px-4 py-3 rounded-xl bg-white dark:bg-graphite-950 border border-graphite-200 dark:border-white/10 text-graphite-900 dark:text-white outline-none focus:border-accent-500"
              />

              <button
                type="button"
                onClick={trackOrder}
                disabled={trackingLoading}
                className="inline-flex items-center justify-center gap-2 px-6 py-3 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-colors disabled:opacity-60"
              >
                <Search className="w-4 h-4" />
                {trackingLoading ? 'Проверяем...' : 'Найти заказ'}
              </button>
            </div>

            {trackingError && (
              <p className="mt-4 text-sm text-red-500">
                {trackingError}
              </p>
            )}

            {trackingResult && (
              <div className="mt-5 p-5 rounded-2xl bg-graphite-50 dark:bg-graphite-950 border border-graphite-200 dark:border-white/5">
                <div className="text-sm text-graphite-500 dark:text-graphite-400">
                  Заказ №{trackingResult.id}
                </div>

                <div className="mt-2 text-xl font-bold text-graphite-900 dark:text-white">
                  {trackingResult.status}
                </div>
              </div>
            )}
          </div>
        </section>
        <div className="grid gap-6">
          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm dark:shadow-none">
            <h2 className="font-display font-bold text-2xl mb-3">
              Помощь с выбором
            </h2>

            <p className="text-graphite-600 dark:text-graphite-400 leading-relaxed">
              Если вы не знаете, какая модель подойдёт именно вам,
              мы поможем подобрать телевизор с учётом ваших задач,
              размера помещения и бюджета.
            </p>
          </div>

          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm dark:shadow-none">
            <h2 className="font-display font-bold text-2xl mb-3">
              Вопросы по заказу
            </h2>

            <p className="text-graphite-600 dark:text-graphite-400 leading-relaxed">
              Поможем разобраться с оформлением заказа, доставкой,
              оплатой и другими вопросами, связанными с покупкой.
            </p>
          </div>

          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm dark:shadow-none">
            <h2 className="font-display font-bold text-2xl mb-3">
              После покупки
            </h2>

            <p className="text-graphite-600 dark:text-graphite-400 leading-relaxed">
              Если у вас возникли вопросы после покупки, обратитесь
              в службу поддержки TELVORA. Мы поможем разобраться
              с дальнейшими действиями.
            </p>
          </div>

          <section className="pt-6">
            <div className="mb-6">
              <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
                FAQ
              </span>

              <h2 className="font-display font-extrabold text-3xl sm:text-4xl mt-2">
                Частые вопросы
              </h2>

              <p className="text-graphite-600 dark:text-graphite-400 mt-3">
                Ответы на самые популярные вопросы наших покупателей.
              </p>
            </div>

            <div className="space-y-3">
              {faqItems.map((item, index) => {
                const isOpen = openFaq === index;

                return (
                  <div
                    key={item.question}
                    className="bg-white dark:bg-graphite-900 rounded-2xl border border-graphite-200 dark:border-white/5 overflow-hidden shadow-sm dark:shadow-none"
                  >
                    <button
                      type="button"
                      onClick={() => toggleFaq(index)}
                      className="w-full flex items-center justify-between gap-4 px-5 sm:px-6 py-5 text-left"
                      aria-expanded={isOpen}
                    >
                      <span className="font-semibold text-base sm:text-lg">
                        {item.question}
                      </span>

                      <ChevronDown
                        className={`w-5 h-5 shrink-0 text-accent-500 transition-transform duration-300 ${
                          isOpen ? 'rotate-180' : ''
                        }`}
                      />
                    </button>

                    <div
                      className={`grid transition-all duration-300 ${
                        isOpen
                          ? 'grid-rows-[1fr] opacity-100'
                          : 'grid-rows-[0fr] opacity-0'
                      }`}
                    >
                      <div className="overflow-hidden">
                        <p className="px-5 sm:px-6 pb-5 text-graphite-600 dark:text-graphite-400 leading-relaxed">
                          {item.answer}
                        </p>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </section>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-6 pt-6">
            <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm dark:shadow-none">
              <h2 className="font-display font-bold text-2xl mb-3">
                Гарантия
              </h2>

              <p className="text-graphite-600 dark:text-graphite-400 leading-relaxed">
                Подробную информацию о гарантии и сервисном обслуживании
                можно получить у специалистов TELVORA.
              </p>
            </div>

            <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm dark:shadow-none">
              <h2 className="font-display font-bold text-2xl mb-3">
                Доставка и оплата
              </h2>

              <p className="text-graphite-600 dark:text-graphite-400 leading-relaxed">
                Поможем подобрать удобный способ доставки и ответим
                на вопросы по оплате заказа.
              </p>
            </div>
          </div>

          <div className="p-8 bg-accent-500 rounded-3xl text-white mt-2">
            <span className="text-sm font-semibold uppercase tracking-widest text-white/80">
              TELVORA
            </span>

            <h2 className="font-display font-extrabold text-3xl mt-2">
              Остались вопросы?
            </h2>

            <p className="text-white/80 mt-3 leading-relaxed max-w-2xl">
              Свяжитесь с нашей службой поддержки — поможем с выбором
              товара, оформлением заказа, доставкой и вопросами после покупки.
            </p>
          </div>
        </div>
      </div>
    </main>
  );
}
