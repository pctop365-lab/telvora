import { Truck, ShieldCheck, RotateCcw, Headset, CreditCard, Package } from 'lucide-react';

const items = [
  {
    icon: Truck,
    title: 'Бесплатная доставка',
    desc: 'По всей России за 1–3 дня. Бережная транспортировка в спецупаковке.',
  },
  {
    icon: ShieldCheck,
    title: '5 лет гарантии',
    desc: 'Официальная гарантия производителя. Сервисные центры в 80 городах.',
  },
  {
    icon: Package,
    title: 'Профессиональная установка',
    desc: 'Монтаж, настройка и подключение всех устройств. Включено в стоимость.',
  },
  {
    icon: RotateCcw,
    title: '30 дней на возврат',
    desc: 'Не подошёл? Вернём деньги без вопросов. Бесплатный обратный вывоз.',
  },
  {
    icon: CreditCard,
    title: 'Гибкая оплата',
    desc: 'Картой, по счёту, в рассрочку 0-0-12. Без скрытых комиссий.',
  },
  {
    icon: Headset,
    title: 'Поддержка 24/7',
    desc: 'Чат, телефон, видео-консультация. Реальные специалисты, не боты.',
  },
];

export default function DeliverySection() {
  return (
    <section id="delivery" className="py-20 sm:py-28 bg-graphite-900">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center max-w-2xl mx-auto mb-14">
          <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
            Сервис
          </span>
          <h2 className="font-display font-extrabold text-4xl sm:text-5xl text-white mt-2 tracking-tight text-balance">
            Премиальный сервис
          </h2>
          <p className="text-graphite-400 mt-4 text-lg">
            Мы не просто продаём телевизоры. Мы доставляем впечатления —
            от заказа до первой ночи кино.
          </p>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
          {items.map((item, i) => (
            <div
              key={item.title}
              className="flex gap-5 p-6 bg-graphite-800 rounded-3xl border border-white/5 hover:border-white/10 transition-all animate-fade-up"
              style={{ animationDelay: `${i * 0.08}s`, opacity: 0 }}
            >
              <div className="w-12 h-12 rounded-2xl bg-accent-500/10 flex items-center justify-center shrink-0">
                <item.icon className="w-6 h-6 text-accent-500" />
              </div>
              <div>
                <h3 className="font-display font-semibold text-lg text-white mb-1">
                  {item.title}
                </h3>
                <p className="text-sm text-graphite-400 leading-relaxed">{item.desc}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
