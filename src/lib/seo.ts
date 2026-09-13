const SITE_URL = 'https://telvora.ru';

export function absoluteTelvoraUrl(path: string): string {
  if (/^https?:\/\//i.test(path)) return path;
  const normalized = path === '/' ? '/' : `/${path.replace(/^\/+|\/+$/g, '')}`;
  return `${SITE_URL}${normalized}`;
}
