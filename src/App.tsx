import {
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
import ServicesPage from '@/pages/ServicesPage';
import SoundbarsPage from '@/pages/SoundbarsPage';
import AccessoriesPage from '@/pages/AccessoriesPage';
import NotFoundPage from '@/pages/NotFoundPage';
import AdminPage from '@/pages/AdminPage';
import PrivacyPage from '@/pages/PrivacyPage';
import PersonalDataConsentPage from '@/pages/PersonalDataConsentPage';
import CookiesPage from '@/pages/CookiesPage';
import OfferPage from '@/pages/OfferPage';
import ReturnsPage from '@/pages/ReturnsPage';
import ContactsPage from '@/pages/ContactsPage';
import RequisitesPage from '@/pages/RequisitesPage';
import SeoMetadata from '@/components/SeoMetadata';

export default function App() {
  return (
    <ThemeProvider>
        <CartProvider>
          <UIProvider>
            <AppContent />
          </UIProvider>
        </CartProvider>
    </ThemeProvider>
  );
}

function AppContent() {
  const location = useLocation();

  useEffect(() => {
    try {
      localStorage.removeItem('telvora_orders');
    } catch {
      // Browser storage may be unavailable.
    }
  }, []);

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
          <Route path="/televisions" element={<CatalogPage />} />

          <Route
            path="/catalog/:categorySlug"
            element={<CategoryRoute />}
          />

          <Route
            path="/catalog/:categorySlug/:productSlug"
            element={<ProductPage />}
          />

          <Route path="/checkout" element={<><SeoMetadata title="Оформление заказа — TELVORA" description="Оформление заказа TELVORA." path="/checkout" robots="noindex, follow" /><CheckoutPage /></>} />
          <Route path="/admin" element={<><SeoMetadata title="Управление TELVORA" description="Служебная область TELVORA." path="/admin" robots="noindex, nofollow" /><AdminPage /></>} />

          <Route
            path="/order-success/:orderNumber"
            element={<><SeoMetadata title="Заказ оформлен — TELVORA" description="Информация об оформленном заказе TELVORA." path="/order-success" robots="noindex, follow" /><OrderSuccessPage /></>}
          />

          <Route path="/warranty" element={<WarrantyPage />} />
          <Route path="/support" element={<><SeoMetadata title="Поддержка покупателей — TELVORA" description="Помощь по заказам, доставке, гарантии и использованию техники TELVORA." path="/support" /><SupportPage /></>} />
          <Route path="/delivery" element={<DeliveryPage />} />
          <Route path="/services" element={<><SeoMetadata title="Сервисные услуги — TELVORA" description="Профессиональная установка, настройка и связанные сервисные услуги TELVORA." path="/services" /><ServicesPage /></>} />
          <Route path="/soundbars" element={<><SeoMetadata title="Саундбары — TELVORA" description="Раздел саундбаров TELVORA готовится к запуску." path="/soundbars" robots="noindex, follow" /><SoundbarsPage /></>} />
          <Route path="/accessories" element={<><SeoMetadata title="Аксессуары — TELVORA" description="Раздел аксессуаров TELVORA готовится к запуску." path="/accessories" robots="noindex, follow" /><AccessoriesPage /></>} />
          <Route path="/privacy" element={<PrivacyPage />} />
          <Route path="/personal-data-consent" element={<PersonalDataConsentPage />} />
          <Route path="/cookies" element={<CookiesPage />} />
          <Route path="/offer" element={<OfferPage />} />
          <Route path="/returns" element={<ReturnsPage />} />
          <Route path="/contacts" element={<><SeoMetadata title="Контакты TELVORA" description="Контакты TELVORA для заказов, доставки, сервиса и поддержки покупателей." path="/contacts" /><ContactsPage /></>} />
          <Route path="/requisites" element={<RequisitesPage />} />

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
