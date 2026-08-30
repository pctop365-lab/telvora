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
            className="group relative min-h-[360px] p-8 sm:p-10 rounded-4xl bg-white dark:bg-graphite-900 border border-graphite-200 dark:border-white/5 shadow-sm dark:shadow-none hover:border-accent-500/40 transition-all duration-300 overflow-hidden"
          >
            <div className="absolute top-0 right-0 w-64 h-64 bg-accent-500/5 rounded-full blur-3xl group-hover:bg-accent-500/10 transition-all" />

            <div className="relative flex flex-col h-full justify-between">
              <div>
                <div className="w-14 h-14 rounded-2xl bg-accent-500/10 flex items-center justify-center mb-8">
                  <Film className="w-7 h-7 text-accent-500" />
                </div>

                <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
                  Домашний кинотеатр
                </span>

                <h3 className="font-display font-extrabold text-3xl sm:text-4xl text-graphite-900 dark:text-white mt-3 leading-tight">
                  Кинотеатр у вас дома
                </h3>

                <p className="text-graphite-600 dark:text-graphite-300 mt-4 text-lg max-w-lg leading-relaxed">
                  Создайте атмосферу настоящего кинотеатра у себя дома —
                  комфортный просмотр, любимые фильмы и яркие впечатления каждый день.
                </p>
              </div>

              <span className="inline-block mt-8 text-sm font-semibold text-accent-500 group-hover:text-accent-600 dark:group-hover:text-white transition-colors">
                Смотреть телевизоры →
              </span>
            </div>
          </Link>

          <div className="grid grid-cols-1 gap-6">
            <Link
              to="/catalog"
              className="group p-8 rounded-4xl bg-white dark:bg-graphite-900 border border-graphite-200 dark:border-white/5 shadow-sm dark:shadow-none hover:border-accent-500/40 transition-all"
            >
              <div className="flex items-start gap-5">
                <div className="w-12 h-12 rounded-2xl bg-accent-500/10 flex items-center justify-center shrink-0">
                  <Sparkles className="w-6 h-6 text-accent-500" />
                </div>

                <div>
                  <h3 className="font-display font-bold text-2xl text-graphite-900 dark:text-white">
                    Дизайн без границ
                  </h3>

                  <p className="text-graphite-600 dark:text-graphite-300 mt-2 leading-relaxed">
                    Минималистичный внешний вид, который гармонично
                    вписывается в современный интерьер.
                  </p>

                  <span className="inline-block mt-4 text-sm font-semibold text-accent-500 group-hover:text-accent-600 dark:group-hover:text-white transition-colors">
                    Смотреть модели →
                  </span>
                </div>
              </div>
            </Link>

            <Link
              to="/support"
              className="group p-8 rounded-4xl bg-white dark:bg-graphite-900 border border-graphite-200 dark:border-white/5 shadow-sm dark:shadow-none hover:border-accent-500/40 transition-all"
            >
              <div className="flex items-start gap-5">
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