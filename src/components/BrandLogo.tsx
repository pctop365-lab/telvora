type BrandLogoProps = { className?: string; priority?: boolean; variant?: 'header' | 'footer' };

export default function BrandLogo({ className = '', priority = false, variant = 'footer' }: BrandLogoProps) {
  const loading = priority ? 'eager' : 'lazy';
  if (variant === 'header') {
    return <span className={`relative inline-flex items-center gap-2 rounded-lg bg-transparent dark:bg-white/[0.96] dark:px-[7px] dark:py-[5px] ${className}`}>
      <img src="/telvora-mark.svg" alt="" aria-hidden="true" width="34" height="26" loading={loading} className="block h-[26px] w-[34px] object-contain" />
      <span className="font-display text-[19px] font-extrabold tracking-tight text-graphite-900">TELVORA</span>
    </span>;
  }
  return <span className={`relative block rounded-lg bg-transparent dark:bg-white/[0.96] dark:px-[7px] dark:py-[5px] ${className}`}>
    <img src="/telvora-logo.svg" alt="TELVORA" width="520" height="130" loading={loading} className="block h-full w-full object-contain" />
  </span>;
}
