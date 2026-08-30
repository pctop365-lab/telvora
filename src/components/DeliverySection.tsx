import {
  Truck,
  ShieldCheck,
  RotateCcw,
  Headset,
  CreditCard,
  Package,
} from 'lucide-react';

const items = [
  {
    icon: Truck,
    title: 'Курьерская доставка',
    desc: 'Доставка курьером за 1–2 дня.',
  },
  {
    icon: ShieldCheck,
    title: '1 год гарантии',
    desc: 'Официальная гарантия производителя. Сервисные центры в 80 городах.',
  },
  {
    icon: Package,
    title: 'Профессиональная установка',
    desc: 'Монтаж, настройка и подключение всех устройств нашими профессионалами.',
  },
  {
    icon: RotateCcw,
    title: '14 дней на возврат',
    desc: 'Не подошёл? Вернём деньги без вопросов.',
  },
  {
    icon: CreditCard,
    title: 'Гибкая оплата',
    desc: 'Оплата удобным для вас способом.',
  },
  {
    icon: Headset,
    title: 'Поддержка 24/7',
    desc: 'Чат, телефон, видео-консультация. Реальные специалисты, не боты.',
  },
];

export default function DeliverySection() {
  return (
    <section
      id="delivery"
      className="py-20 sm:py-28 bg-white dark:bg-graphite-900"
    >
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">

        {/* Заголовок */}
        <div className="text-center max-w-2xl mx-auto mb-14">
          <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
            Сервис
          </span>

          <h2 className="font-display font-extrabold text-4xl sm:text-5xl text-graphite-900 dark:text-white mt-2 tracking-tight text-balance">
            Премиальный сервис
          </h2>

          <p className="text-graphite-600 dark:text-graphite-300 mt-4 text-lg">
            Мы делаем покупку телевизора простой и комфортной — от выбора
            модели до доставки прямо к вашей двери.
          </p>
        </div>

        {/* 6 основных карточек */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
          {items.map((item, i) => (
            <div
              key={item.title}
              className="
                flex gap-5 p-6
                bg-white dark:bg-graphite-800
                rounded-3xl
                border border-graphite-200 dark:border-white/5
                hover:border-accent-500/30
                transition-all
                animate-fade-up
              "
              style={{
                animationDelay: `${i * 0.08}s`,
                opacity: 0,
              }}
            >
              {/* Иконка */}
              <div className="w-12 h-12 rounded-2xl bg-accent-500/10 flex items-center justify-center shrink-0">
                <item.icon className="w-6 h-6 text-accent-500" />
              </div>

              {/* Текст */}
              <div>
                <h3 className="font-display font-semibold text-lg text-graphite-900 dark:text-white mb-1">
                  {item.title}
                </h3>

                <p className="text-sm text-graphite-600 dark:text-graphite-300 leading-relaxed">
                  {item.desc}
                </p>
              </div>
            </div>
          ))}
        </div>

        {/* Важная информация */}
        <div className="flex justify-center mt-5">
          <div
            className="
              w-full sm:max-w-xl
              p-6
              bg-white dark:bg-graphite-800
              rounded-3xl
              border border-graphite-200 dark:border-white/5
              hover:border-accent-500/30
              transition-all
            "
          >
            <div className="flex items-start gap-4">
              <div className="w-11 h-11 rounded-2xl bg-accent-500/10 flex items-center justify-center shrink-0">
                <ShieldCheck className="w-5 h-5 text-accent-500" />
              </div>

              <div>
                <h3 className="font-display font-semibold text-lg text-graphite-900 dark:text-white mb-1">
                  Важная информация
                </h3>

                <p className="text-sm text-graphite-600 dark:text-graphite-300 leading-relaxed">
                  Точные сроки, стоимость и доступные способы доставки будут
                  указаны при оформлении заказа после определения условий
                  продаж.
                </p>
              </div>
            </div>
          </div>
        </div>

      </div>
    </section>
  );
}