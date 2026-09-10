import { Link } from 'react-router-dom';
import { ArrowRight, Search, Truck, Wrench } from 'lucide-react';
import { siteContent } from '@/data/siteContent';

export default function Hero() {
  const { image, badge, title, subtitle } = siteContent.hero;
  const lightImage = '/images/telvora-hero-light.png';

  return (
    <section className="dark-content relative min-h-screen flex items-center overflow-hidden bg-graphite-950">
      <div className="absolute inset-0">
        <img
          src={lightImage}
          alt="Телевизор TELVORA в светлой гостиной"
          decoding="async"
          className="h-full w-full object-cover object-[72%_center] sm:object-right dark:hidden"
        />

        <img
          src={image}
          alt="Телевизор TELVORA в тёмной гостиной с домашним кинотеатром"
          decoding="async"
          className="hidden h-full w-full object-cover object-[72%_center] sm:object-right dark:block"
        />

        <div className="absolute inset-0 bg-[linear-gradient(90deg,rgba(15,17,21,0.76)_0%,rgba(15,17,21,0.60)_32%,rgba(15,17,21,0.30)_58%,rgba(15,17,21,0.08)_82%,rgba(15,17,21,0.02)_100%)] dark:hidden" />
        <div className="absolute inset-0 bg-[linear-gradient(0deg,rgba(15,17,21,0.24)_0%,rgba(15,17,21,0.04)_48%,rgba(15,17,21,0.20)_100%)] dark:hidden" />

        <div className="absolute inset-0 hidden bg-[linear-gradient(90deg,rgba(15,17,21,0.90)_0%,rgba(15,17,21,0.78)_28%,rgba(15,17,21,0.52)_52%,rgba(15,17,21,0.25)_73%,rgba(15,17,21,0.10)_100%)] dark:block" />
        <div className="absolute inset-0 hidden bg-[linear-gradient(0deg,rgba(15,17,21,0.48)_0%,rgba(15,17,21,0.04)_52%,rgba(15,17,21,0.34)_100%)] dark:block" />
      </div>

      <div className="relative max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 pt-32 pb-20 w-full">
        <div className="max-w-3xl">

          <div className="inline-flex items-center gap-2 px-4 py-2 mb-6 bg-white/70 dark:bg-white/5 backdrop-blur-md border border-graphite-200 dark:border-white/10 rounded-full animate-fade-up">
            <span className="w-2 h-2 bg-accent-500 rounded-full animate-pulse" />

            <span className="text-sm font-medium text-graphite-100">
              {badge}
            </span>
          </div>

          <h1
            className="font-display font-extrabold text-5xl sm:text-6xl lg:text-7xl text-white leading-[1.05] tracking-tight text-balance animate-fade-up"
            style={{ animationDelay: '0.1s', opacity: 0 }}
          >
            {title}
          </h1>

          <p
            className="mt-8 text-lg sm:text-xl text-graphite-200 max-w-xl leading-relaxed animate-fade-up"
            style={{ animationDelay: '0.2s', opacity: 0 }}
          >
            {subtitle}
          </p>

          <div
            className="mt-10 flex flex-col sm:flex-row gap-4 animate-fade-up"
            style={{ animationDelay: '0.3s', opacity: 0 }}
          >
            <Link
              to="/catalog"
              className="group inline-flex items-center justify-center gap-2 px-8 py-4 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-all shadow-lg shadow-accent-500/30 hover:shadow-xl hover:shadow-accent-500/40 hover:-translate-y-0.5"
            >
              Смотреть каталог

              <ArrowRight className="w-5 h-5 group-hover:translate-x-1 transition-transform" />
            </Link>

            <a
              href="#tech"
              className="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white/70 dark:bg-white/5 hover:bg-white dark:hover:bg-white/10 border border-graphite-200 dark:border-white/10 text-graphite-900 dark:text-white font-semibold rounded-xl transition-all backdrop-blur-md"
            >
              Узнать о технологиях
            </a>
          </div>

          <div
            className="mt-16 grid grid-cols-1 sm:grid-cols-3 gap-4 max-w-3xl animate-fade-up"
            style={{ animationDelay: '0.4s', opacity: 0 }}
          >
            {[
              {
                icon: Search,
                title: 'Подобрать телевизор',
                sub: 'Поможем выбрать модель под ваш бюджет',
                to: '/support',
              },
              {
                icon: Truck,
                title: 'Доставка и оплата',
                sub: 'Тарифы по Москве и отправка в регионы',
                to: '/delivery',
              },
              {
                icon: Wrench,
                title: 'Сервисные услуги',
                sub: 'Монтаж, настройка и пиксельтест',
                to: '/services',
              },
            ].map((f) => (
              <Link
                key={f.title}
                to={f.to}
                className="group flex cursor-pointer items-start gap-3 rounded-2xl border border-graphite-200 bg-white/70 p-4 backdrop-blur-md transition-all duration-200 hover:-translate-y-0.5 hover:border-accent-500/50 hover:bg-white hover:shadow-lg hover:shadow-accent-500/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:border-white/10 dark:bg-white/5 dark:hover:border-accent-500/60 dark:hover:bg-white/10 dark:focus-visible:ring-offset-graphite-900"
              >
                <div className="w-10 h-10 rounded-xl bg-accent-500/10 flex items-center justify-center shrink-0 transition-colors group-hover:bg-accent-500/15">
                  <f.icon className="w-5 h-5 text-accent-500 transition-transform group-hover:scale-105" />
                </div>

                <div>
                  <div className="text-sm font-semibold text-graphite-900 dark:text-white">
                    {f.title}
                  </div>

                  <div className="text-xs text-graphite-600 dark:text-graphite-400">
                    {f.sub}
                  </div>
                </div>
              </Link>
            ))}
          </div>

        </div>
      </div>

      <div className="absolute bottom-0 left-1/2 -translate-x-1/2 hidden md:flex flex-col items-center gap-2">
        <span className="text-xs text-graphite-500 dark:text-graphite-500 tracking-widest uppercase">
          Листайте вниз
        </span>

        <div className="w-px h-12 bg-gradient-to-b from-graphite-500 to-transparent" />
      </div>
    </section>
  );
}
