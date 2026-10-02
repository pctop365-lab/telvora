import { Link } from 'react-router-dom';
import { Headphones, Mail, Phone, ShieldCheck, ShoppingBag, Truck } from 'lucide-react';
import CallbackRequestForm from '@/components/CallbackRequestForm';
import { publicContacts } from '@/data/publicContacts';

const contactSections = [
  { title: 'Поддержка покупателей', text: 'Проверка статуса заказа и ответы на частые вопросы.', to: '/support', action: 'Перейти в поддержку', icon: Headphones },
  { title: 'Доставка', text: 'Информация о способах получения заказа.', to: '/delivery', action: 'О доставке', icon: Truck },
  { title: 'Гарантия', text: 'Общая информация о гарантийном обслуживании.', to: '/warranty', action: 'О гарантии', icon: ShieldCheck },
];

export default function ContactsPage() {
  return (
    <main className="min-h-screen bg-graphite-50 text-graphite-900 dark:bg-graphite-950 dark:text-white py-20 sm:py-24">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <header className="max-w-3xl mb-10"><span className="text-sm font-semibold text-accent-600 dark:text-accent-500 uppercase tracking-widest">TELVORA</span><h1 className="font-display font-extrabold text-4xl sm:text-5xl mt-2">Контакты</h1><p className="text-lg text-graphite-600 dark:text-graphite-300 mt-4">Свяжитесь с нами по вопросам заказа, доставки, сервиса или поддержки.</p><a href="#callback" className="mt-6 inline-flex items-center justify-center rounded-xl bg-accent-500 px-6 py-3 font-semibold text-white transition-colors hover:bg-accent-600">Заказать обратный звонок</a></header>
        <section aria-label="Контактные данные" className="grid gap-5 mb-5 sm:grid-cols-2 lg:grid-cols-3">
          <article className="p-6 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm"><div className="w-11 h-11 rounded-2xl bg-accent-500/10 flex items-center justify-center mb-5"><Phone className="w-5 h-5 text-accent-600" /></div><h2 className="font-display font-bold text-xl">{'\u0422\u0435\u043b\u0435\u0444\u043e\u043d'}</h2><div className="mt-2 space-y-1">{publicContacts.phones.map((phone) => <a key={phone.href} href={`tel:${phone.href}`} className="block break-all text-sm font-semibold text-accent-600 hover:text-accent-700 dark:text-accent-500">{phone.display}</a>)}</div></article>
          <ContactCard icon={ShoppingBag} title="Заказы" value={publicContacts.ordersEmail} href={publicContacts.ordersMailto} />
          <ContactCard icon={Mail} title="Поддержка" value={publicContacts.supportEmail} href={publicContacts.supportMailto} />
        </section>
        <div className="grid md:grid-cols-3 gap-5 mb-5">
          {contactSections.map((item) => <article key={item.title} className="flex flex-col p-6 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm"><div className="w-11 h-11 rounded-2xl bg-accent-500/10 flex items-center justify-center mb-5"><item.icon className="w-5 h-5 text-accent-600" /></div><h2 className="font-display font-bold text-xl">{item.title}</h2><p className="text-sm text-graphite-600 dark:text-graphite-400 mt-2 mb-5 flex-1">{item.text}</p><Link to={item.to} className="text-sm font-semibold text-accent-600 hover:text-accent-700">{item.action}</Link></article>)}
        </div>
        <section aria-labelledby="pickup-title" className="mb-5 overflow-hidden rounded-3xl border border-graphite-200 bg-white shadow-sm dark:border-white/5 dark:bg-graphite-900">
          <div className="grid lg:grid-cols-2">
            <div className="p-6 sm:p-8">
              <span className="text-sm font-semibold uppercase tracking-widest text-accent-600 dark:text-accent-500">Получение заказа</span>
              <h2 id="pickup-title" className="mt-3 font-display text-2xl font-bold">Самовывоз на Горбушке</h2>
              <p className="mt-4 font-semibold">Москва, Багратионовский проезд, 7, корпус 3</p>
              <p className="mt-3 leading-relaxed text-graphite-600 dark:text-graphite-300">
                Самовывоз после подтверждения заказа. Перед поездкой согласуйте время получения с менеджером. Подробности получения сообщим при подтверждении.
              </p>
              <a
                href="https://yandex.ru/maps/?org=57799743091"
                target="_blank"
                rel="noopener noreferrer"
                className="mt-6 inline-flex items-center justify-center rounded-xl bg-accent-500 px-5 py-3 font-semibold text-white transition-colors hover:bg-accent-600"
              >
                Открыть в Яндекс Картах
              </a>
            </div>
            <iframe
              src="https://yandex.ru/map-widget/v1/?z=16&ol=biz&oid=57799743091"
              title="Точка самовывоза TELVORA на Яндекс Картах"
              loading="lazy"
              referrerPolicy="no-referrer"
              className="block h-[320px] w-full border-0 sm:h-[400px]"
            />
          </div>
        </section>
        <CallbackRequestForm />
        <section className="mt-5 p-6 sm:p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm"><h2 className="font-display font-bold text-2xl">Продавец</h2><div className="mt-3 space-y-1 text-graphite-600 dark:text-graphite-400"><p className="font-semibold text-graphite-900 dark:text-white">{publicContacts.sellerShortName}</p><p>ИНН {publicContacts.inn}</p><p>ОГРНИП {publicContacts.ogrnip}</p><p className="pt-2"><strong>Регистрирующий орган:</strong><br />{publicContacts.registrationAuthority}</p></div><Link to="/requisites" className="mt-4 inline-block font-semibold text-accent-600 hover:text-accent-700">Полные реквизиты</Link></section>
      </div>
    </main>
  );
}

function ContactCard({ icon: Icon, title, value, href }: { icon: typeof Phone; title: string; value: string; href: string }) {
  return <article className="p-6 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm"><div className="w-11 h-11 rounded-2xl bg-accent-500/10 flex items-center justify-center mb-5"><Icon className="w-5 h-5 text-accent-600" /></div><h2 className="font-display font-bold text-xl">{title}</h2><a href={href} className="mt-2 block break-all text-sm font-semibold text-accent-600 hover:text-accent-700 dark:text-accent-500">{value}</a></article>;
}
