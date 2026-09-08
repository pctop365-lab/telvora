import CatalogPage from './CatalogPage';

export default function CategoryPage({ categorySlug }: { categorySlug: string }) {
  const technology = ['oled', 'qled', 'led'].includes(categorySlug) ? categorySlug.toUpperCase() : undefined;
  return <CatalogPage initialTechnology={technology} initialResolutionToken={categorySlug === '8k' ? '8k' : undefined} />;
}
