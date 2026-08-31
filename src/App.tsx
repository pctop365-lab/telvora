import {
  BrowserRouter,
  Routes,
  Route,
  useParams,
  useLocation,
} from 'react-router-dom';
import { useEffect } from 'react';
import { CartProvider } from '@/store/cart';
import { UIProvider } from '@/store/ui';
import { ThemeProvider, useTheme } from '@/store/theme';
import Header from '@/components/Header';
import Footer from '@/components/Footer';
import CartDrawer from '@/components/CartDrawer';
import HomePage from '@/pages/HomePage';
import CatalogPage from '@/pages/CatalogPage';
import CategoryPage from '@/pages/CategoryPage';
import ProductPage from '@/pages/ProductPage';
import CheckoutPage from '@/pages/CheckoutPage';
import OrderSuccessPage from '@/pages/OrderSuccessPage';
import WarrantyPage from '@/pages/WarrantyPage';
import SupportPage from '@/pages/SupportPage';
import DeliveryPage from '@/pages/DeliveryPage';
import SoundbarsPage from '@/pages/SoundbarsPage';
import AccessoriesPage from '@/pages/AccessoriesPage';
import NotFoundPage from '@/pages/NotFoundPage';
import AdminPage from '@/pages/AdminPage';

export default function App() {
  return (
    <ThemeProvider>
      <BrowserRouter>
        <CartProvider>
          <UIProvider>
            <AppContent />
          </UIProvider>
        </CartProvider>
      </BrowserRouter>
    </ThemeProvider>
  );
}

function AppContent() {
  const location = useLocation();

  useEffect(() => {
    const sectionId = location.hash.slice(1);

    if (sectionId) {
      const frame = window.requestAnimationFrame(() => {
        document.getElementById(sectionId)?.scrollIntoView({
          behavior: 'smooth',
          block: 'start',
        });
      });

      return () => window.cancelAnimationFrame(frame);
    }

    window.scrollTo({
      top: 0,
      left: 0,
      behavior: 'instant',
    });
  }, [location.pathname, location.search, location.hash]);

  // остальной код AppContent...
  const { theme } = useTheme();
  const isLight = theme === 'light';
const isAdmin = location.pathname.startsWith('/admin');

  return (
    <div
  className={`min-h-screen flex flex-col transition-colors duration-500 ${
    isAdmin
      ? 'bg-transparent'
      : isLight
        ? 'bg-white text-graphite-900'
        : 'bg-graphite-900 text-white'
  }`}
>
      {!isAdmin && <Header />}

      <main className={`flex-1 ${isAdmin ? 'admin-page' : ''}`}>
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/catalog" element={<CatalogPage />} />

          <Route
            path="/catalog/:categorySlug"
            element={<CategoryRoute />}
          />

          <Route
            path="/catalog/:categorySlug/:productSlug"
            element={<ProductPage />}
          />

          <Route path="/checkout" element={<CheckoutPage />} />
          <Route path="/admin" element={<AdminPage />} />

          <Route
            path="/order-success/:orderNumber"
            element={<OrderSuccessPage />}
          />

          <Route path="/warranty" element={<WarrantyPage />} />
          <Route path="/support" element={<SupportPage />} />
          <Route path="/delivery" element={<DeliveryPage />} />
          <Route path="/soundbars" element={<SoundbarsPage />} />
          <Route path="/accessories" element={<AccessoriesPage />} />

          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </main>

      {!isAdmin && <Footer />}
      {!isAdmin && <CartDrawer />}
    </div>
  );
}

function CategoryRoute() {
  const { categorySlug } = useParams<{ categorySlug: string }>();

  return <CategoryPage categorySlug={categorySlug ?? ''} />;
}
