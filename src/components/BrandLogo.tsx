type BrandLogoProps = { className?: string; priority?: boolean };

export default function BrandLogo({ className = '', priority = false }: BrandLogoProps) {
  const loading = priority ? 'eager' : 'lazy';
  return <span className={`relative block ${className}`}>
    <img src="/telvora-logo-dark.svg" alt="TELVORA" width="520" height="130" loading={loading} className="block h-full w-full object-contain dark:hidden" />
    <img src="/telvora-logo-white.svg" alt="" aria-hidden="true" width="520" height="130" loading={loading} className="hidden h-full w-full object-contain dark:block" />
  </span>;
}
