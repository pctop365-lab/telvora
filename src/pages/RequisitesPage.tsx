import LegalMetadata from '@/components/LegalMetadata';
import { businessDetails as b } from '@/data/publicContacts';

const rows = [
  ['Продавец', b.sellerShortName], ['ИНН', b.inn], ['ОГРНИП', b.ogrnip], ['Дата регистрации', b.registrationDate],
  ['Регистрирующий орган', b.registrationAuthority], ['Налоговый режим', b.taxSystem], ['НДС', b.vatNotice],
  ['Расчётный счёт', b.bankAccount], ['Банк', b.bankName], ['БИК', b.bik], ['Корреспондентский счёт', b.correspondentAccount],
  ['Телефон', b.phoneDisplay], ['Заказы', b.ordersEmail], ['Поддержка', b.supportEmail], ['Сайт', b.site],
] as const;

export default function RequisitesPage() {
  return <main className="min-h-screen bg-graphite-50 py-20 text-graphite-900 dark:bg-graphite-950 dark:text-white sm:py-24">
    <LegalMetadata title="Реквизиты TELVORA" description="Официальные реквизиты продавца TELVORA — ИП Помякшев Иван Владимирович." path="/requisites" />
    <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8"><header className="mb-10"><span className="text-sm font-semibold uppercase tracking-widest text-accent-600 dark:text-accent-500">TELVORA</span><h1 className="mt-2 font-display text-4xl font-extrabold sm:text-5xl">Реквизиты TELVORA</h1></header>
      <dl className="overflow-hidden rounded-3xl border border-graphite-200 bg-white shadow-sm dark:border-white/5 dark:bg-graphite-900">{rows.map(([label, value]) => <div key={label} className="grid gap-1 border-b border-graphite-200 px-5 py-4 last:border-b-0 dark:border-white/5 sm:grid-cols-[210px_1fr] sm:gap-5 sm:px-7"><dt className="font-semibold text-graphite-700 dark:text-graphite-200">{label}</dt><dd className="break-words text-graphite-600 dark:text-graphite-400">{value}</dd></div>)}</dl>
    </div>
  </main>;
}
