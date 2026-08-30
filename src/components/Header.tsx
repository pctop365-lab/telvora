import { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  Search,
  ShoppingCart,
  Menu,
  X,
  Tv,
  Sun,
  Moon,
} from 'lucide-react';
import { useCart } from '@/store/cart';
import { useUI } from '@/store/ui';
import { useTheme } from '@/store/theme';

const navLinks = [
  { label: 'Каталог', to: '/catalog' },
  { label: 'Телевизоры', to: '/catalog' },
  { label: 'Саундбары', to: '/soundbars' },
  { label: 'Аксессуары', to: '/accessories' },
  { label: 'Технологии', to: '/#tech' },
 { label: 'Поддержка', to: '/support' },
];

export default function Header() {
  const { count } = useCart();
  const { openCart, searchQuery, setSearchQuery } = useUI();
  const { theme, toggleTheme } = useTheme();

  const [scrolled, setScrolled] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [localSearch, setLocalSearch] = useState(searchQuery || '');

  const navigate = useNavigate();

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 20);

    window.addEventListener('scroll', onScroll, { passive: true });

    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();

    setSearchQuery(localSearch);
    navigate('/catalog');
    setMobileOpen(false);
  };

  const handleNavClick = (to: string) => {
    setMobileOpen(false);

    if (to === '/#tech') {
      navigate('/');

      setTimeout(() => {
        document.getElementById('tech')?.scrollIntoView({
          behavior: 'smooth',
        });
      }, 100);

      return;
    }

    navigate(to);
  };

  const isLight = theme === 'light';

  return (
    <>
      <header
        className={`fixed top-0 left-0 right-0 z-50 transition-all duration-500 ${
          isLight
            ? scrolled
              ? 'bg-white/90 backdrop-blur-2xl border-b border-graphite-200 py-3 shadow-sm'
              : 'bg-white/95 backdrop-blur-xl py-5'
            : scrolled
              ? 'bg-graphite-900/90 backdrop-blur-2xl border-b border-graphite-700 py-3 shadow-lg'
              : 'bg-graphite-900/95 backdrop-blur-xl py-5'
        }`}
      >
        <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center justify-between gap-4">
            {/* LOGO */}
            <Link to="/" className="flex items-center gap-2 shrink-0 group">
              <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-accent-500 to-accent-700 flex items-center justify-center shadow-lg shadow-accent-500/20 group-hover:scale-105 transition-transform">
                <Tv
                  className={`w-5 h-5 ${
                    isLight ? 'text-white' : 'text-white'
                  }`}
                  strokeWidth={2.5}
                />
              </div>

              <span
                className={`font-display font-bold text-xl tracking-tight transition-colors ${
                  isLight ? 'text-graphite-900' : 'text-white'
                }`}
              >
                TELVORA
              </span>
            </Link>

            {/* DESKTOP NAVIGATION */}
            <nav className="hidden lg:flex items-center gap-1">
              {navLinks.map((link) => (
                <button
                  key={link.label}
                  onClick={() => handleNavClick(link.to)}
                  className={`px-4 py-2 text-sm font-medium rounded-lg transition-colors ${
                    isLight
                      ? 'text-graphite-600 hover:text-graphite-900 hover:bg-graphite-100'
                      : 'text-graphite-300 hover:text-white hover:bg-white/10'
                  }`}
                >
                  {link.label}
                </button>
              ))}
            </nav>

            {/* SEARCH */}
            <form
              onSubmit={handleSearch}
              className="hidden md:flex items-center flex-1 max-w-xs"
            >
              <div className="relative w-full group">
                <Search
                  className={`absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 transition-colors ${
                    isLight
                      ? 'text-graphite-400 group-focus-within:text-accent-500'
                      : 'text-graphite-500 group-focus-within:text-accent-500'
                  }`}
                />

                <input
                  type="text"
                  value={localSearch}
                  onChange={(e) => setLocalSearch(e.target.value)}
                  placeholder="Поиск телевизоров..."
                  className={`w-full pl-10 pr-4 py-2.5 text-sm rounded-xl focus:outline-none focus:border-accent-500/50 transition-all ${
                    isLight
                      ? 'bg-graphite-50 border border-graphite-200 text-graphite-900 placeholder:text-graphite-400 focus:bg-white'
                      : 'bg-white/5 border border-graphite-700 text-white placeholder:text-graphite-500 focus:bg-white/10'
                  }`}
                />
              </div>
            </form>

            {/* THEME SWITCH */}
<button
  type="button"
  onClick={toggleTheme}
  aria-label={
    isLight
      ? 'Включить тёмную тему'
      : 'Включить светлую тему'
  }
  title={
    isLight
      ? 'Включить тёмную тему'
      : 'Включить светлую тему'
  }
  className={`shrink-0 flex items-center justify-center w-11 h-11 rounded-xl border transition-all duration-200 ${
    isLight
      ? 'bg-white hover:bg-graphite-100 border-graphite-200'
      : 'bg-white/5 hover:bg-white/10 border-graphite-700'
  }`}
>
  {isLight ? (
    <Moon className="w-5 h-5 text-graphite-900" />
  ) : (
    <Sun className="w-5 h-5 text-accent-500" />
  )}
</button>

            {/* CART */}
            <button
              onClick={openCart}
              className={`relative flex items-center gap-2 px-4 py-2.5 rounded-xl border transition-all group shadow-sm ${
                isLight
                  ? 'bg-white hover:bg-graphite-50 border-graphite-200'
                  : 'bg-white/5 hover:bg-white/10 border-graphite-700'
              }`}
            >
              <ShoppingCart
                className={`w-5 h-5 ${
                  isLight ? 'text-graphite-900' : 'text-white'
                }`}
              />

              <span
                className={`hidden sm:inline text-sm font-medium ${
                  isLight ? 'text-graphite-900' : 'text-white'
                }`}
              >
                Корзина
              </span>

              {count > 0 && (
                <span className="absolute -top-2 -right-2 w-5 h-5 bg-accent-500 text-white text-xs font-bold rounded-full flex items-center justify-center shadow-lg shadow-accent-500/40 animate-scale-in">
                  {count}
                </span>
              )}
            </button>

            {/* MOBILE MENU BUTTON */}
            <button
              onClick={() => setMobileOpen(true)}
              className={`lg:hidden p-2 rounded-lg transition-colors ${
                isLight
                  ? 'text-graphite-900 hover:bg-graphite-200'
                  : 'text-white hover:bg-white/10'
              }`}
              aria-label="Открыть меню"
            >
              <Menu className="w-6 h-6" />
            </button>
          </div>
        </div>
      </header>

      {/* MOBILE MENU */}
      {mobileOpen && (
        <div className="fixed inset-0 z-[60] lg:hidden">
          {/* BACKDROP */}
          <div
            className={`absolute inset-0 backdrop-blur-sm animate-fade-in ${
              isLight
                ? 'bg-graphite-900/40'
                : 'bg-black/70'
            }`}
            onClick={() => setMobileOpen(false)}
          />

          {/* MENU PANEL */}
          <div
            className={`absolute right-0 top-0 bottom-0 w-80 max-w-[85vw] border-l p-6 animate-slide-in-right overflow-y-auto ${
              isLight
                ? 'bg-white border-graphite-200'
                : 'bg-graphite-900 border-graphite-700'
            }`}
          >
            <div className="flex items-center justify-between mb-8">
              <span
                className={`font-display font-bold text-lg ${
                  isLight ? 'text-graphite-900' : 'text-white'
                }`}
              >
                Меню
              </span>

              <button
                onClick={() => setMobileOpen(false)}
                className={`p-2 rounded-lg transition-colors ${
                  isLight
                    ? 'text-graphite-900 hover:bg-graphite-200'
                    : 'text-white hover:bg-white/10'
                }`}
                aria-label="Закрыть меню"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* MOBILE SEARCH */}
            <form onSubmit={handleSearch} className="relative mb-6">
              <Search
                className={`absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 ${
                  isLight
                    ? 'text-graphite-400'
                    : 'text-graphite-500'
                }`}
              />

              <input
                type="text"
                value={localSearch}
                onChange={(e) => setLocalSearch(e.target.value)}
                placeholder="Поиск..."
                className={`w-full pl-10 pr-4 py-3 text-sm rounded-xl focus:outline-none focus:border-accent-500/50 ${
                  isLight
                    ? 'bg-graphite-50 border border-graphite-200 text-graphite-900 placeholder:text-graphite-400'
                    : 'bg-white/5 border border-graphite-700 text-white placeholder:text-graphite-500'
                }`}
              />
            </form>

            {/* MOBILE NAV */}
            <nav className="flex flex-col gap-1">
              {navLinks.map((link) => (
                <button
                  key={link.label}
                  onClick={() => handleNavClick(link.to)}
                  className={`w-full text-left px-4 py-3 text-base font-medium rounded-xl transition-colors ${
                    isLight
                      ? 'text-graphite-700 hover:text-graphite-900 hover:bg-graphite-100'
                      : 'text-graphite-200 hover:text-white hover:bg-white/10'
                  }`}
                >
                  {link.label}
                </button>
              ))}
            </nav>

            {/* MOBILE THEME SWITCH */}
            <div
              className={`mt-6 pt-6 border-t ${
                isLight
                  ? 'border-graphite-200'
                  : 'border-graphite-700'
              }`}
            >
              <button
                onClick={toggleTheme}
                className={`w-full flex items-center justify-between px-4 py-3 rounded-xl transition-colors ${
                  isLight
                    ? 'hover:bg-graphite-100'
                    : 'hover:bg-white/10'
                }`}
              >
                <span
                  className={`font-medium ${
                    isLight ? 'text-graphite-900' : 'text-white'
                  }`}
                >
                  {isLight ? 'Тёмная тема' : 'Светлая тема'}
                </span>

                {isLight ? (
                  <Moon className="w-5 h-5 text-graphite-700" />
                ) : (
                  <Sun className="w-5 h-5 text-accent-500" />
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}