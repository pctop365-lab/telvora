import { Tv, Instagram, Youtube, Send, ArrowUp } from 'lucide-react';
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
    { label: 'Контакты', to: '/' },
  ],
  Поддержка: [
    { label: 'Доставка и оплата', to: '/#delivery' },
    { label: 'Гарантия', to: '/' },
    { label: 'Возврат', to: '/' },
    { label: 'FAQ', to: '/' },
  ],
};

export default function Footer() {
  return (
    <footer className="bg-graphite-950 border-t border-white/5">
      <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-8 mb-12">
          <div className="col-span-2 lg:col-span-2">
            <Link to="/" className="flex items-center gap-2 mb-4">
              <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-accent-500 to-accent-700 flex items-center justify-center">
                <Tv className="w-5 h-5 text-white" strokeWidth={2.5} />
              </div>
              <span className="font-display font-bold text-xl text-white">TELVORA</span>
            </Link>
            <p className="text-sm text-graphite-400 max-w-xs leading-relaxed">
              Премиальные телевизоры с фокусом на качество изображения, звук
              и дизайн. Создаём эталон домашних экранов с 2014 года.
            </p>
            <div className="flex items-center gap-3 mt-6">
              {[Instagram, Youtube, Send].map((Icon, i) => (
                <a
                  key={i}
                  href="#"
                  className="w-10 h-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-graphite-300 hover:text-white hover:bg-accent-500 hover:border-accent-500 transition-all"
                >
                  <Icon className="w-5 h-5" />
                </a>
              ))}
            </div>
          </div>

          {Object.entries(footerLinks).map(([title, links]) => (
            <div key={title}>
              <h4 className="text-sm font-semibold text-white mb-4">{title}</h4>
              <ul className="space-y-3">
                {links.map((link) => (
                  <li key={link.label}>
                    <Link
                      to={link.to}
                      className="text-sm text-graphite-400 hover:text-accent-500 transition-colors"
                    >
                      {link.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="flex flex-col md:flex-row items-start md:items-center justify-between gap-6 p-6 bg-graphite-800 rounded-3xl border border-white/5 mb-12">
          <div>
            <h4 className="font-display font-semibold text-lg text-white">
              Подпишитесь на новинки и скидки
            </h4>
            <p className="text-sm text-graphite-400 mt-1">
              Первыми узнавайте о новых моделях и эксклюзивных предложениях.
            </p>
          </div>
          <div className="flex gap-3 w-full md:w-auto">
            <input
              type="email"
              placeholder="Ваш email"
              className="flex-1 md:w-64 px-4 py-3 text-sm bg-graphite-900 border border-white/10 rounded-xl text-white placeholder:text-graphite-500 focus:outline-none focus:border-accent-500/50 transition-colors"
            />
            <button className="px-6 py-3 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-colors whitespace-nowrap">
              Подписаться
            </button>
          </div>
        </div>

        <div className="flex flex-col sm:flex-row items-center justify-between gap-4 pt-8 border-t border-white/5">
          <p className="text-sm text-graphite-500">
            © 2026 TELVORA. Все права защищены.
          </p>
          <div className="flex items-center gap-6">
            <a href="#" className="text-sm text-graphite-500 hover:text-white transition-colors">
              Политика конфиденциальности
            </a>
            <a href="#" className="text-sm text-graphite-500 hover:text-white transition-colors">
              Условия
            </a>
          </div>
          <Link
            to="#"
            className="w-10 h-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-graphite-300 hover:text-white hover:bg-white/10 transition-all"
          >
            <ArrowUp className="w-5 h-5" />
          </Link>
        </div>
      </div>
    </footer>
  );
}
