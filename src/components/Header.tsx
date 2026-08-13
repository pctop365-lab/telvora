import { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Search, ShoppingCart, Menu, X, Tv } from 'lucide-react';
import { useCart } from '@/store/cart';
import { useUI } from '@/store/ui';

const navLinks = [
  { label: 'Каталог', to: '/catalog' },
  { label: 'OLED', to: '/catalog/oled' },
  { label: 'QLED', to: '/catalog/qled' },
  { label: '8K', to: '/catalog/8k' },
  { label: 'Технологии', to: '/#tech' },
  { label: 'Доставка', to: '/#delivery' },
];

export default function Header() {
  const { count } = useCart();
  const { openCart, searchQuery, setSearchQuery } = useUI();
  const [scrolled, setScrolled] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [localSearch, setLocalSearch] = useState('');
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

  return (
    <>
      <header
        className={`fixed top-0 left-0 right-0 z-50 transition-all duration-500 ${
          scrolled
            ? 'bg-graphite-900/80 backdrop-blur-2xl border-b border-white/5 py-3'
            : 'bg-transparent py-5'
        }`}
      >
        <div className="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center justify-between gap-4">
            <Link to="/" className="flex items-center gap-2 shrink-0 group">
              <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-accent-500 to-accent-700 flex items-center justify-center shadow-lg shadow-accent-500/20 group-hover:scale-105 transition-transform">
                <Tv className="w-5 h-5 text-white" strokeWidth={2.5} />
              </div>
              <span className="font-display font-bold text-xl tracking-tight text-white">
                TELVORA
              </span>
            </Link>

            <nav className="hidden lg:flex items-center gap-1">
              {navLinks.map((link) => (
                <Link
                  key={link.label}
                  to={link.to}
                  className="px-4 py-2 text-sm font-medium text-graphite-300 hover:text-white transition-colors rounded-lg hover:bg-white/5"
                >
                  {link.label}
                </Link>
              ))}
            </nav>

            <form onSubmit={handleSearch} className="hidden md:flex items-center flex-1 max-w-xs">
              <div className="relative w-full group">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-graphite-400 group-focus-within:text-accent-500 transition-colors" />
                <input
                  type="text"
                  value={localSearch}
                  onChange={(e) => setLocalSearch(e.target.value)}
                  placeholder="Поиск телевизоров..."
                  className="w-full pl-10 pr-4 py-2.5 text-sm bg-white/5 border border-white/10 rounded-xl text-white placeholder:text-graphite-400 focus:outline-none focus:border-accent-500/50 focus:bg-white/10 transition-all"
                />
              </div>
            </form>

            <button
              onClick={openCart}
              className="relative flex items-center gap-2 px-4 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all group"
            >
              <ShoppingCart className="w-5 h-5 text-white" />
              <span className="hidden sm:inline text-sm font-medium text-white">Корзина</span>
              {count > 0 && (
                <span className="absolute -top-2 -right-2 w-5 h-5 bg-accent-500 text-white text-xs font-bold rounded-full flex items-center justify-center shadow-lg shadow-accent-500/40 animate-scale-in">
                  {count}
                </span>
              )}
            </button>

            <button
              onClick={() => setMobileOpen(true)}
              className="lg:hidden p-2 text-white hover:bg-white/10 rounded-lg transition-colors"
            >
              <Menu className="w-6 h-6" />
            </button>
          </div>
        </div>
      </header>

      {mobileOpen && (
        <div className="fixed inset-0 z-[60] lg:hidden">
          <div
            className="absolute inset-0 bg-graphite-950/80 backdrop-blur-sm animate-fade-in"
            onClick={() => setMobileOpen(false)}
          />
          <div className="absolute right-0 top-0 bottom-0 w-80 max-w-[85vw] bg-graphite-800 border-l border-white/10 p-6 animate-slide-in-right overflow-y-auto">
            <div className="flex items-center justify-between mb-8">
              <span className="font-display font-bold text-lg text-white">Меню</span>
              <button
                onClick={() => setMobileOpen(false)}
                className="p-2 text-white hover:bg-white/10 rounded-lg transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSearch} className="relative mb-6">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-graphite-400" />
              <input
                type="text"
                value={localSearch}
                onChange={(e) => setLocalSearch(e.target.value)}
                placeholder="Поиск..."
                className="w-full pl-10 pr-4 py-3 text-sm bg-white/5 border border-white/10 rounded-xl text-white placeholder:text-graphite-400 focus:outline-none focus:border-accent-500/50"
              />
            </form>

            <nav className="flex flex-col gap-1">
              {navLinks.map((link) => (
                <Link
                  key={link.label}
                  to={link.to}
                  onClick={() => setMobileOpen(false)}
                  className="px-4 py-3 text-base font-medium text-graphite-200 hover:text-white hover:bg-white/5 rounded-xl transition-colors"
                >
                  {link.label}
                </Link>
              ))}
            </nav>
          </div>
        </div>
      )}
    </>
  );
}
