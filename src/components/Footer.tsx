import { ArrowUp, Tv } from 'lucide-react';
import { Link } from 'react-router-dom';
import { publicContacts } from '@/data/publicContacts';

const footerLinks = {
  Каталог: [
    { label: 'Весь каталог', to: '/catalog' },
    { label: 'Телевизоры', to: '/televisions' },
    { label: 'Саундбары', to: '/soundbars' },
    { label: 'Аксессуары', to: '/accessories' },
  ],
  Компания: [
    { label: 'О TELVORA', to: '/' },
    { label: 'Технологии', to: '/#tech' },
    { label: 'Контакты', to: '/contacts' },
  ],
  Поддержка: [
    { label: 'Центр поддержки', to: '/support' },
    { label: 'Доставка', to: '/delivery' },
    { label: 'Сервисные услуги', to: '/services' },
    { label: 'Гарантия', to: '/warranty' },
    { label: 'Возврат товара', to: '/returns' },
    { label: 'FAQ', to: '/support#faq' },
  ],
  'Правовая информация': [
    { label: 'Публичная оферта', to: '/offer' },
    { label: 'Политика обработки персональных данных', to: '/privacy' },
    { label: 'Согласие на обработку персональных данных', to: '/personal-data-consent' },
    { label: 'Политика Cookies', to: '/cookies' },
    { label: 'Реквизиты', to: '/requisites' },
  ],
};

export default function Footer() {
  const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' });

  return (
    <footer className="bg-graphite-50 dark:bg-graphite-950 border-t border-graphite-200 dark:border-white/5">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-8 mb-12">
          <div className="col-span-2 lg:col-span-2">
            <Link to="/" aria-label="TELVORA — на главную" className="mb-4 inline-block rounded-lg transition-opacity hover:opacity-85 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500">
              <div className="w-12 h-12 rounded-xl bg-gradient-to-br from-accent-500 to-accent-700 flex items-center justify-center">
                <Tv className="w-7 h-7 text-white" strokeWidth={2.5} />
              </div>
            </Link>
            <p className="text-sm text-graphite-600 dark:text-graphite-400 max-w-xs leading-relaxed">
              TELVORA — новый взгляд на технику и онлайн-покупки. Современно, понятно и без лишнего.
            </p>
            <div className="mt-5 space-y-2 text-sm">
              <a href={publicContacts.phoneLink} className="block text-graphite-700 transition-colors hover:text-accent-600 dark:text-graphite-300 dark:hover:text-accent-500">{publicContacts.phoneDisplay}</a>
              <a href={publicContacts.ordersMailto} className="block break-all text-graphite-600 transition-colors hover:text-accent-600 dark:text-graphite-400 dark:hover:text-accent-500">Заказы: {publicContacts.ordersEmail}</a>
              <a href={publicContacts.supportMailto} className="block break-all text-graphite-600 transition-colors hover:text-accent-600 dark:text-graphite-400 dark:hover:text-accent-500">Поддержка: {publicContacts.supportEmail}</a>
            </div>
            <div className="mt-5 border-t border-graphite-200 pt-4 text-xs leading-relaxed text-graphite-600 dark:border-white/10 dark:text-graphite-400">
              <p>Продавец: {publicContacts.sellerShortName}</p>
              <p>ИНН {publicContacts.inn} · ОГРНИП {publicContacts.ogrnip}</p>
              <Link to="/requisites" className="mt-2 inline-block font-semibold text-accent-600 hover:text-accent-700 dark:text-accent-500">Реквизиты</Link>
            </div>
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
