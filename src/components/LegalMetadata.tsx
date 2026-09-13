import SeoMetadata from './SeoMetadata';

export default function LegalMetadata({ title, description, path }: { title: string; description: string; path: string }) {
  return <SeoMetadata title={title} description={description} path={path} />;
}
