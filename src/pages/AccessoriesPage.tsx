import { Link } from 'react-router-dom';
import { ArrowLeft, Sparkles } from 'lucide-react';

export default function AccessoriesPage() {
  return (
    <section className="min-h-screen pt-32 pb-20 bg-graphite-50 dark:bg-graphite-950">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <Link
          to="/"
          className="inline-flex items-center gap-2 text-sm text-graphite-400 hover:text-white transition-colors mb-10"
        >
          <ArrowLeft className="w-4 h-4" />
          На главную
        </Link>

        <div className="max-w-3xl">
          <div className="w-14 h-14 rounded-2xl bg-accent-500/10 flex items-center justify-center mb-6">
            <Sparkles className="w-7 h-7 text-accent-500" />
          </div>

          <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
            TELVORA
          </span>

          <h1 className="font-display font-extrabold text-4xl sm:text-6xl text-white mt-2 tracking-tight">
            Аксессуары
          </h1>

          <p className="text-graphite-400 text-lg sm:text-xl mt-5 leading-relaxed">
            Всё необходимое для комфортного использования техники TELVORA.
          </p>
        </div>

        <div className="mt-16 p-8 sm:p-12 rounded-4xl bg-graphite-100 dark:bg-graphite-800/50 border border-white/5">
          <h2 className="font-display font-bold text-2xl sm:text-3xl text-white">
            Раздел готовится к запуску
          </h2>

          <p className="text-graphite-400 mt-3 max-w-2xl">
            Здесь появятся аксессуары для телевизоров,
            крепления и другие полезные товары.
          </p>

          <Link
            to="/catalog"
            className="inline-flex items-center gap-2 mt-7 px-6 py-3 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-colors"
          >
            Перейти к телевизорам
          </Link>
        </div>
      </div>
    </section>
  );
}