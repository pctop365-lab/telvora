import { Film, Sparkles, ShieldCheck, Headphones } from 'lucide-react';
import { Link } from 'react-router-dom';

export default function TechSection() {
  return (
    <section
      id="tech"
      className="py-20 sm:py-28 bg-graphite-50 dark:bg-graphite-950 relative overflow-hidden"
    >
      <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-accent-500/5 rounded-full blur-3xl pointer-events-none" />

      <div className="relative max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center max-w-3xl mx-auto mb-16">
          <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
            Технологии
          </span>

          <h2 className="font-display font-extrabold text-4xl sm:text-5xl text-graphite-900 dark:text-white mt-2 tracking-tight text-balance">
            Современный взгляд на телевидение
          </h2>

          <p className="text-graphite-600 dark:text-graphite-300 mt-4 text-lg">
            Продуманный дизайн, современные решения и внимание к деталям —
            всё для комфортного просмотра и гармоничного интерьера.
          </p>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Link
            to="/catalog"
            className="dark-content group relative min-h-[360px] p-8 sm:p-10 rounded-4xl bg-graphite-950 border border-white/10 shadow-sm dark:shadow-none hover:border-accent-500/40 transition-all duration-300 overflow-hidden"
          >
            <img
              src="/images/tech-home-cinema.png"
              alt="Домашний кинотеатр TELVORA в премиальной гостиной"
              loading="lazy"
              decoding="async"
              className="absolute inset-0 h-full w-full object-cover object-[62%_center] transition-transform duration-700 group-hover:scale-[1.02] sm:object-center"
            />
            <div className="absolute inset-0 bg-[linear-gradient(90deg,rgba(15,17,21,0.96)_0%,rgba(15,17,21,0.88)_38%,rgba(15,17,21,0.58)_68%,rgba(15,17,21,0.28)_100%)]" />
            <div className="absolute inset-0 bg-gradient-to-t from-graphite-950/55 via-transparent to-graphite-950/20" />

            <div className="relative flex flex-col h-full justify-between">
              <div>
                <div className="w-14 h-14 rounded-2xl bg-black/30 border border-white/10 backdrop-blur-sm flex items-center justify-center mb-8">
                  <Film className="w-7 h-7 text-accent-500" />
                </div>

                <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
                  Домашний кинотеатр
                </span>

                <h3 className="font-display font-extrabold text-3xl sm:text-4xl text-white mt-3 leading-tight">
                  Кинотеатр у вас дома
                </h3>

                <p className="text-graphite-200 mt-4 text-lg max-w-lg leading-relaxed">
                  Создайте атмосферу настоящего кинотеатра у себя дома —
                  комфортный просмотр, любимые фильмы и яркие впечатления каждый день.
                </p>
              </div>

              <span className="inline-block mt-8 text-sm font-semibold text-accent-500 group-hover:text-white transition-colors">
                Смотреть телевизоры →
              </span>
            </div>
          </Link>

          <div className="grid grid-cols-1 gap-6">
            <Link
              to="/catalog"
              className="dark-content group relative overflow-hidden p-8 rounded-4xl bg-graphite-950 border border-white/10 shadow-sm dark:shadow-none hover:border-accent-500/40 transition-all"
            >
              <img
                src="/images/tech-design-lounge.png"
                alt="Телевизор TELVORA в современном интерьере"
                loading="lazy"
                decoding="async"
                className="absolute inset-0 h-full w-full object-cover object-[68%_center] transition-transform duration-700 group-hover:scale-[1.02] sm:object-center"
              />
              <div className="absolute inset-0 bg-[linear-gradient(90deg,rgba(15,17,21,0.96)_0%,rgba(15,17,21,0.88)_42%,rgba(15,17,21,0.52)_72%,rgba(15,17,21,0.28)_100%)]" />

              <div className="relative flex items-start gap-5">
                <div className="w-12 h-12 rounded-2xl bg-black/30 border border-white/10 backdrop-blur-sm flex items-center justify-center shrink-0">
                  <Sparkles className="w-6 h-6 text-accent-500" />
                </div>

                <div>
                  <h3 className="font-display font-bold text-2xl text-white">
                    Дизайн без границ
                  </h3>

                  <p className="text-graphite-200 mt-2 leading-relaxed">
                    Минималистичный внешний вид, который гармонично
                    вписывается в современный интерьер.
                  </p>

                  <span className="inline-block mt-4 text-sm font-semibold text-accent-500 group-hover:text-white transition-colors">
                    Смотреть модели →
                  </span>
                </div>
              </div>
            </Link>

            <Link
              to="/support"
              className="group relative overflow-hidden p-8 rounded-4xl bg-white dark:bg-graphite-900 border border-graphite-200 dark:border-white/5 shadow-sm dark:shadow-none hover:border-accent-500/40 transition-all"
            >
              <div className="absolute -right-16 -top-20 h-48 w-48 rounded-full bg-accent-500/10 blur-3xl transition-colors group-hover:bg-accent-500/15" />
              <div className="absolute inset-0 bg-gradient-to-br from-transparent via-transparent to-accent-500/[0.04]" />

              <div className="relative flex items-start gap-5">
                <div className="w-12 h-12 rounded-2xl bg-accent-500/10 flex items-center justify-center shrink-0">
                  <Headphones className="w-6 h-6 text-accent-500" />
                </div>

                <div>
                  <h3 className="font-display font-bold text-2xl text-graphite-900 dark:text-white">
                    Поддержка
                  </h3>

                  <p className="text-graphite-600 dark:text-graphite-300 mt-2 leading-relaxed">
                    Помощь по вопросам выбора, покупки и использования
                    продукции TELVORA.
                  </p>

                  <span className="inline-block mt-4 text-sm font-semibold text-accent-500 group-hover:text-accent-600 dark:group-hover:text-white transition-colors">
                    Получить помощь →
                  </span>
                </div>
              </div>
            </Link>
          </div>
        </div>
      </div>
    </section>
  );
}
