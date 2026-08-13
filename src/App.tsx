import { BrowserRouter, Routes, Route, useParams } from 'react-router-dom';
import { CartProvider } from '@/store/cart';
import { UIProvider } from '@/store/ui';
import Header from '@/components/Header';
import Footer from '@/components/Footer';
import CartDrawer from '@/components/CartDrawer';
import HomePage from '@/pages/HomePage';
import CatalogPage from '@/pages/CatalogPage';
import CategoryPage from '@/pages/CategoryPage';
import ProductPage from '@/pages/ProductPage';
import CheckoutPage from '@/pages/CheckoutPage';
import OrderSuccessPage from '@/pages/OrderSuccessPage';
import NotFoundPage from '@/pages/NotFoundPage';

export default function App() {
  return (
    <BrowserRouter>
      <CartProvider>
        <UIProvider>
          <div className="min-h-screen bg-graphite-900 text-white flex flex-col">
            <Header />
            <main className="flex-1">
              <Routes>
                <Route path="/" element={<HomePage />} />
                <Route path="/catalog" element={<CatalogPage />} />
                <Route path="/catalog/:categorySlug" element={<CategoryRoute />} />
                <Route path="/catalog/:categorySlug/:productSlug" element={<ProductPage />} />
                <Route path="/checkout" element={<CheckoutPage />} />
                <Route path="/order-success/:orderId" element={<OrderSuccessPage />} />
                <Route path="*" element={<NotFoundPage />} />
              </Routes>
            </main>
            <Footer />
            <CartDrawer />
          </div>
        </UIProvider>
      </CartProvider>
    </BrowserRouter>
  );
}

function CategoryRoute() {
  const { categorySlug } = useParams<{ categorySlug: string }>();
  return <CategoryPage categorySlug={categorySlug ?? ''} />;
}
