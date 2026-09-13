import { Helmet } from 'react-helmet-async';
import { absoluteTelvoraUrl } from '@/lib/seo';

type JsonLd = Record<string, unknown> | Array<Record<string, unknown>>;

type SeoMetadataProps = {
  title: string;
  description: string;
  path: string;
  robots?: string;
  type?: 'website' | 'product';
  image?: string;
  jsonLd?: JsonLd;
};

export default function SeoMetadata({
  title,
  description,
  path,
  robots = 'index, follow',
  type = 'website',
  image,
  jsonLd,
}: SeoMetadataProps) {
  const url = absoluteTelvoraUrl(path);
  const imageUrl = image ? absoluteTelvoraUrl(image) : undefined;
  const structuredData = jsonLd
    ? JSON.stringify(jsonLd).replace(/</g, '\\u003c')
    : undefined;

  return (
    <Helmet>
      <title>{title}</title>
      <meta name="description" content={description} />
      <meta name="robots" content={robots} />
      <link rel="canonical" href={url} />
      <meta property="og:site_name" content="TELVORA" />
      <meta property="og:locale" content="ru_RU" />
      <meta property="og:type" content={type} />
      <meta property="og:title" content={title} />
      <meta property="og:description" content={description} />
      <meta property="og:url" content={url} />
      {imageUrl && <meta property="og:image" content={imageUrl} />}
      <meta name="twitter:card" content={imageUrl ? 'summary_large_image' : 'summary'} />
      <meta name="twitter:title" content={title} />
      <meta name="twitter:description" content={description} />
      {imageUrl && <meta name="twitter:image" content={imageUrl} />}
      {structuredData && <script type="application/ld+json">{structuredData}</script>}
    </Helmet>
  );
}
