import CatalogPage from './CatalogPage';
import NotFoundPage from './NotFoundPage';

export default function CategoryPage({ categorySlug }: { categorySlug: string }) {
  if (!['oled', 'qled', 'led', '8k'].includes(categorySlug)) return <NotFoundPage />;
  const technology = ['oled', 'qled', 'led'].includes(categorySlug) ? categorySlug.toUpperCase() : undefined;
  return <CatalogPage initialTechnology={technology} initialResolutionToken={categorySlug === '8k' ? '8k' : undefined} categorySlug={categorySlug} />;
}
