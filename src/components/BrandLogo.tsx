type BrandLogoProps = { className?: string; priority?: boolean };

export default function BrandLogo({ className = '', priority = false }: BrandLogoProps) {
  const loading = priority ? 'eager' : 'lazy';
  return <img src="/telvora-mark.svg" alt="" aria-hidden="true" width="160" height="120" loading={loading} className={`block object-contain ${className}`} />;
}
