import { ArrowUp, Tv } from 'lucide-react';
import { Link } from 'react-router-dom';

const footerLinks = {
  Каталог: [
    { label: 'OLED телевизоры', to: '/catalog/oled' },
    { label: 'QLED телевизоры', to: '/catalog/qled' },
    { label: '8K телевизоры', to: '/catalog/8k' },
    { label: 'LED телевизоры', to: '/catalog/led' },
  ],
  Компания: [
    { label: 'О TELVORA', to: '/' },
    { label: 'Технологии', to: '/#tech' },
  ],
  Поддержка: [
    { label: 'Доставка и оплата', to: '/delivery' },
    { label: 'Гарантия', to: '/warranty' },
    { label: 'Возврат', to: '/support#returns' },
    { label: 'FAQ', to: '/support#faq' },
  ],
};

export default function Footer() {
  const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' });

  return (
    <footer className="bg-graphite-50 dark:bg-graphite-950 border-t border-graphite-200 dark:border-white/5">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-8 mb-12">
          <div className="col-span-2 lg:col-span-2">
            <Link to="/" className="flex items-center gap-2 mb-4">
              <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-accent-500 to-accent-700 flex items-center justify-center">
                <Tv className="w-5 h-5 text-white" strokeWidth={2.5} />
              </div>
              <span className="font-display font-bold text-xl text-graphite-900 dark:text-white">TELVORA</span>
            </Link>
            <p className="text-sm text-graphite-600 dark:text-graphite-400 max-w-xs leading-relaxed">
              TELVORA — новый взгляд на технику и онлайн-покупки. Современно, понятно и без лишнего.
            </p>
          </div>

          {Object.entries(footerLinks).map(([title, links]) => (
            <nav key={title} aria-label={title}>
              <h4 className="text-sm font-semibold text-graphite-900 dark:text-white mb-4">{title}</h4>
              <ul className="space-y-3">
                {links.map((link) => (
                  <li key={link.label}>
                    <Link to={link.to} className="text-sm text-graphite-600 dark:text-graphite-400 hover:text-accent-600 dark:hover:text-accent-500 transition-colors">
                      {link.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </nav>
          ))}
        </div>

        <div className="flex items-center justify-between gap-4 pt-8 border-t border-graphite-200 dark:border-white/5">
          <p className="text-sm text-graphite-500">© 2026 TELVORA. Все права защищены.</p>
          <button type="button" onClick={scrollToTop} aria-label="Наверх" className="w-10 h-10 rounded-xl bg-white dark:bg-white/5 border border-graphite-200 dark:border-white/10 flex items-center justify-center text-graphite-600 dark:text-graphite-300 hover:text-accent-600 hover:border-accent-500/40 transition-all">
            <ArrowUp className="w-5 h-5" />
          </button>
        </div>
      </div>
    </footer>
  );
}
