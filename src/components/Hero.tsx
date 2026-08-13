import { Link } from 'react-router-dom';
import { ArrowRight, Zap, Shield, Truck } from 'lucide-react';
import { siteContent } from '@/data/siteContent';

export default function Hero() {
  const { image, badge, title, subtitle } = siteContent.hero;

  const titleParts = title.split(',');

  return (
    <section className="relative min-h-screen flex items-center overflow-hidden bg-graphite-900">
      <div className="absolute inset-0">
        <img
          src={image}
          alt="TELVORA Vision OLED в современном интерьере"
          className="w-full h-full object-cover opacity-50"
        />
        <div className="absolute inset-0 bg-gradient-to-r from-graphite-900 via-graphite-900/80 to-graphite-900/30" />
        <div className="absolute inset-0 bg-gradient-to-t from-graphite-900 via-transparent to-graphite-900/60" />
      </div>

      <div className="relative max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 pt-32 pb-20 w-full">
        <div className="max-w-3xl">
          <div className="inline-flex items-center gap-2 px-4 py-2 mb-6 bg-white/5 backdrop-blur-md border border-white/10 rounded-full animate-fade-up">
            <span className="w-2 h-2 bg-accent-500 rounded-full animate-pulse" />
            <span className="text-sm font-medium text-graphite-100">{badge}</span>
          </div>

          <h1
            className="font-display font-extrabold text-5xl sm:text-6xl lg:text-8xl text-white leading-[1.05] tracking-tight text-balance animate-fade-up"
            style={{ animationDelay: '0.1s', opacity: 0 }}
          >
            {titleParts[0]},
            <br />
            <span className="text-gradient">оживающая</span> в вашей
            <br />
            гостиной
          </h1>

          <p
            className="mt-8 text-lg sm:text-xl text-graphite-300 max-w-xl leading-relaxed animate-fade-up"
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
              className="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white/5 hover:bg-white/10 border border-white/10 text-white font-semibold rounded-xl transition-all backdrop-blur-md"
            >
              Узнать о технологиях
            </a>
          </div>

          <div
            className="mt-16 grid grid-cols-1 sm:grid-cols-3 gap-4 max-w-2xl animate-fade-up"
            style={{ animationDelay: '0.4s', opacity: 0 }}
          >
            {[
              { icon: Truck, title: 'Бесплатная доставка', sub: 'По всей России' },
              { icon: Shield, title: '5 лет гарантии', sub: 'Официальная сервисная программа' },
              { icon: Zap, title: 'Установка за 1 день', sub: 'Профессиональный монтаж' },
            ].map((f) => (
              <div
                key={f.title}
                className="flex items-center gap-3 p-4 bg-white/5 backdrop-blur-md border border-white/10 rounded-2xl"
              >
                <div className="w-10 h-10 rounded-xl bg-accent-500/10 flex items-center justify-center shrink-0">
                  <f.icon className="w-5 h-5 text-accent-500" />
                </div>
                <div>
                  <div className="text-sm font-semibold text-white">{f.title}</div>
                  <div className="text-xs text-graphite-400">{f.sub}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className="absolute bottom-8 left-1/2 -translate-x-1/2 hidden md:flex flex-col items-center gap-2">
        <span className="text-xs text-graphite-500 tracking-widest uppercase">Листайте вниз</span>
        <div className="w-px h-12 bg-gradient-to-b from-graphite-500 to-transparent" />
      </div>
    </section>
  );
}
