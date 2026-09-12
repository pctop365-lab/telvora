import { Helmet } from 'react-helmet-async';

export default function LegalMetadata({ title, description, path }: { title: string; description: string; path: string }) {
  const url = `https://telvora.ru${path}`;
  return <Helmet><title>{title}</title><meta name="description" content={description} /><link rel="canonical" href={url} /><meta property="og:title" content={title} /><meta property="og:description" content={description} /><meta property="og:url" content={url} /></Helmet>;
}
