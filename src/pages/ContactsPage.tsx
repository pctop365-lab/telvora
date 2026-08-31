import { Link } from 'react-router-dom';
import { Headphones, ShieldCheck, Truck } from 'lucide-react';

const contactSections = [
  { title: 'Поддержка покупателей', text: 'Проверка статуса заказа и ответы на частые вопросы.', to: '/support', action: 'Перейти в поддержку', icon: Headphones },
  { title: 'Доставка', text: 'Информация о способах получения заказа.', to: '/delivery', action: 'О доставке', icon: Truck },
  { title: 'Гарантия', text: 'Общая информация о гарантийном обслуживании.', to: '/warranty', action: 'О гарантии', icon: ShieldCheck },
];

export default function ContactsPage() {
  return (
    <main className="min-h-screen bg-graphite-50 text-graphite-900 dark:bg-graphite-950 dark:text-white py-20 sm:py-24">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <header className="max-w-3xl mb-10"><span className="text-sm font-semibold text-accent-600 dark:text-accent-500 uppercase tracking-widest">TELVORA</span><h1 className="font-display font-extrabold text-4xl sm:text-5xl mt-2">Контакты</h1><p className="text-lg text-graphite-600 dark:text-graphite-300 mt-4">Полезные разделы для покупателей и подготовленная область реквизитов продавца.</p></header>
        <div className="grid md:grid-cols-3 gap-5 mb-5">
          {contactSections.map((item) => <article key={item.title} className="flex flex-col p-6 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm"><div className="w-11 h-11 rounded-2xl bg-accent-500/10 flex items-center justify-center mb-5"><item.icon className="w-5 h-5 text-accent-600" /></div><h2 className="font-display font-bold text-xl">{item.title}</h2><p className="text-sm text-graphite-600 dark:text-graphite-400 mt-2 mb-5 flex-1">{item.text}</p><Link to={item.to} className="text-sm font-semibold text-accent-600 hover:text-accent-700">{item.action}</Link></article>)}
        </div>
        <section className="p-6 sm:p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm"><h2 className="font-display font-bold text-2xl">Реквизиты продавца</h2><p className="text-graphite-600 dark:text-graphite-400 mt-2">Будут опубликованы после регистрации продавца и до начала коммерческих продаж.</p></section>
      </div>
    </main>
  );
}
