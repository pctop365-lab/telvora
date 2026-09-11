import { ArrowUpRight, MessageCircle, Send } from 'lucide-react';
import { publicLinks } from '@/data/publicLinks';

export default function TelegramBanner() {
  return (
    <article
      aria-labelledby="telegram-heading"
      className="relative overflow-hidden rounded-4xl border border-accent-500/20 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-graphite-800 dark:shadow-none sm:p-8"
    >
      <img
        src="/images/tech-telegram-light.png"
        alt=""
        loading="lazy"
        decoding="async"
        className="absolute inset-0 h-full w-full object-cover object-center dark:hidden"
      />
      <img
        src="/images/tech-telegram-dark.png"
        alt=""
        loading="lazy"
        decoding="async"
        className="absolute inset-0 hidden h-full w-full object-cover object-center dark:block"
      />
      <div className="absolute inset-0 bg-[linear-gradient(90deg,rgba(255,255,255,0.96)_0%,rgba(255,251,247,0.82)_50%,rgba(255,255,255,0.16)_80%,rgba(255,255,255,0.02)_100%)] dark:hidden" />
      <div className="absolute inset-0 hidden bg-[linear-gradient(90deg,rgba(11,13,16,0.94)_0%,rgba(11,13,16,0.76)_50%,rgba(11,13,16,0.18)_80%,rgba(11,13,16,0.03)_100%)] dark:block" />
      <div aria-hidden="true" className="absolute -right-12 -top-16 h-48 w-48 rounded-full bg-accent-500/10 blur-3xl" />

      <div className="relative flex items-start gap-4">
        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-accent-500 text-white shadow-lg shadow-accent-500/20">
          <Send className="h-5 w-5" aria-hidden="true" />
        </span>

        <div className="min-w-0">
          <h3 id="telegram-heading" className="font-display text-2xl font-bold tracking-tight text-graphite-900 dark:text-white">
            TELVORA в Telegram
          </h3>
          <p className="mt-2 text-sm leading-relaxed text-graphite-700 dark:text-graphite-300">
            Новинки, сравнения телевизоров, акции, промокоды и розыгрыши — всё самое интересное в нашем канале.
          </p>
        </div>
      </div>

      <div className="relative mt-5 flex flex-col gap-3 sm:flex-row">
        <a
          href={publicLinks.telegramStore}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-accent-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-accent-500/20 transition-all hover:-translate-y-0.5 hover:bg-accent-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-graphite-800"
        >
          Подписаться
          <ArrowUpRight className="h-4 w-4" aria-hidden="true" />
        </a>
        <a
          href={publicLinks.telegramReviews}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-graphite-200 bg-graphite-50 px-4 py-2.5 text-sm font-semibold text-graphite-800 transition-all hover:-translate-y-0.5 hover:border-accent-500/40 hover:text-accent-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:border-accent-500/50 dark:hover:text-accent-500"
        >
          <MessageCircle className="h-4 w-4" aria-hidden="true" />
          Отзывы покупателей
        </a>
      </div>
    </article>
  );
}
