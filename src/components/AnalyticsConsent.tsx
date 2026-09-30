import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';

const ID = 113224284;
const KEY = 'telvora_analytics_consent';
type Consent = 'yes' | 'no' | null;
type Ym = ((...args: unknown[]) => void) & { a?: unknown[][]; l?: number };
function getBrowser() { return window as Window & { ym?: Ym }; }
let loading: Promise<void> | undefined;
let active = false;
let lastPath = '';

function readConsent(): Consent {
  try {
    const value = localStorage.getItem(KEY);
    return value === 'yes' || value === 'no' ? value : null;
  } catch {
    return null;
  }
}

function loadTag() {
  if (!loading) {
    loading = new Promise<void>((resolve, reject) => {
      if (!getBrowser().ym) {
        const queue: Ym = (...args) => {
          queue.a = queue.a || [];
          queue.a.push(args);
        };
        queue.l = Date.now();
        getBrowser().ym = queue;
      }
      const script = document.createElement('script');
      script.async = true;
      script.src = `https://mc.yandex.ru/metrika/tag.js?id=${ID}`;
      script.onload = () => resolve();
      script.onerror = () => {
        script.remove();
        loading = undefined;
        reject(new Error('Metrika unavailable'));
      };
      document.head.appendChild(script);
    });
  }
  return loading;
}

export default function AnalyticsConsent() {
  const { pathname } = useLocation();
  const [consent, setConsent] = useState<Consent>(readConsent);
  const [settings, setSettings] = useState(false);
  const privatePage = /^\/(admin|account|checkout|order-success)(\/|$)/.test(pathname);

  useEffect(() => {
    let cancelled = false;
    if (consent !== 'yes' || privatePage) {
      if (active) getBrowser().ym?.(ID, 'destruct');
      active = false;
      lastPath = '';
      return;
    }
    if (!['telvora.ru', 'www.telvora.ru'].includes(location.hostname)) return;

    void loadTag().then(() => {
      if (cancelled) return;
      if (!active) {
        getBrowser().ym?.(ID, 'init', {
          defer: true,
          webvisor: false,
          clickmap: false,
          trackLinks: false,
          accurateTrackBounce: true,
        });
        active = true;
      }
      if (lastPath !== pathname) {
        const previous = lastPath;
        lastPath = pathname;
        getBrowser().ym?.(ID, 'hit', `${location.origin}${pathname}`, {
          referer: previous ? `${location.origin}${previous}` : '',
        });
      }
    }).catch(() => {});
    return () => { cancelled = true; };
  }, [consent, pathname, privatePage]);

  function choose(value: 'yes' | 'no') {
    try { localStorage.setItem(KEY, value); } catch {}
    setConsent(value);
    setSettings(false);
    if (value === 'no' && consent === 'yes') {
      getBrowser().ym?.(ID, 'destruct');
      active = false;
      location.reload();
    }
  }

  if (privatePage) return null;

  return <>
    {(consent === null || settings) && (
      <section aria-label="Настройки аналитики"
        className="fixed bottom-4 left-4 right-4 z-[100] mx-auto max-w-3xl rounded-2xl border border-orange-200 bg-white p-5 text-gray-900 shadow-xl">
        <p className="font-semibold">Аналитика TELVORA</p>
        <p className="mt-2 text-sm">
          С вашего разрешения Яндекс Метрика собирает данные о посещениях,
          браузере и устройстве, чтобы улучшать магазин.
          Корзина и вход работают без аналитики.{' '}
          <a href="/cookies" className="underline">Подробнее</a>
        </p>
        <div className="mt-4 flex flex-wrap gap-3">
          <button type="button" onClick={() => choose('yes')}
            className="rounded-xl bg-orange-500 px-4 py-2 font-medium text-white">
            Разрешить аналитику
          </button>
          <button type="button" onClick={() => choose('no')}
            className="rounded-xl border border-gray-300 px-4 py-2 font-medium">
            Только необходимые
          </button>
        </div>
      </section>
    )}
    {pathname === '/cookies' && consent !== null && !settings && (
      <button type="button" onClick={() => setSettings(true)}
        className="fixed bottom-4 right-4 z-[90] rounded-xl border border-orange-200 bg-white px-4 py-3 text-gray-900 shadow-lg">
        Настройки аналитики
      </button>
    )}
  </>;
}