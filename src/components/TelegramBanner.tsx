import { ArrowUpRight, MessageCircle, Send } from 'lucide-react';
import { publicLinks } from '@/data/publicLinks';

export default function TelegramBanner() {
  return <section aria-labelledby="telegram-heading" className="bg-white py-8 sm:py-10 dark:bg-graphite-900">
    <div className="mx-auto max-w-8xl px-4 sm:px-6 lg:px-8">
      <div className="relative overflow-hidden rounded-3xl border border-accent-500/20 bg-graphite-50 px-6 py-7 shadow-sm dark:border-white/10 dark:bg-graphite-800 sm:px-8 sm:py-8">
        <div aria-hidden="true" className="absolute -right-12 -top-16 h-48 w-48 rounded-full bg-accent-500/10 blur-3xl" />
        <div className="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex max-w-2xl items-start gap-4"><span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-accent-500 text-white shadow-lg shadow-accent-500/20"><Send className="h-5 w-5" aria-hidden="true" /></span><div>
            <h2 id="telegram-heading" className="font-display text-2xl font-bold tracking-tight text-graphite-900 dark:text-white sm:text-3xl">TELVORA в Telegram</h2>
            <p className="mt-2 text-sm leading-relaxed text-graphite-600 dark:text-graphite-300 sm:text-base">Новинки, сравнения телевизоров, акции, промокоды и розыгрыши — всё самое интересное в нашем канале.</p>
          </div></div>
          <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row lg:shrink-0">
            <a href={publicLinks.telegramStore} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-accent-500 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-accent-500/20 transition-all hover:-translate-y-0.5 hover:bg-accent-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-graphite-800">Подписаться<ArrowUpRight className="h-4 w-4" aria-hidden="true" /></a>
            <a href={publicLinks.telegramReviews} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl border border-graphite-200 bg-white px-5 py-3 text-sm font-semibold text-graphite-800 transition-all hover:-translate-y-0.5 hover:border-accent-500/40 hover:text-accent-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:border-accent-500/50 dark:hover:text-accent-500"><MessageCircle className="h-4 w-4" aria-hidden="true" />Отзывы покупателей</a>
          </div>
        </div>
        <p className="relative mt-4 pl-16 text-xs text-graphite-500 dark:text-graphite-400">Реальные отзывы о покупке, доставке и сервисе</p>
      </div>
    </div>
  </section>;
}
