type BrandLogoProps = { className?: string; priority?: boolean };

export default function BrandLogo({ className = '', priority = false }: BrandLogoProps) {
  const loading = priority ? 'eager' : 'lazy';
  return <span className={`relative block rounded-lg bg-transparent dark:bg-white/[0.96] dark:px-[7px] dark:py-[5px] ${className}`}>
    <img src="/telvora-logo.svg" alt="TELVORA" width="520" height="130" loading={loading} className="block h-full w-full object-contain" />
  </span>;
}
