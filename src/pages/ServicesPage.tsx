import { Settings, Sparkles, Tv, Wrench } from 'lucide-react';

const services = [
  { title: 'Установка телевизора', text: 'Размещение телевизора и согласование подходящего варианта монтажа.', icon: Tv },
  { title: 'Настройка', text: 'Первичная настройка изображения, каналов и подключений с учётом техники пользователя.', icon: Settings },
  { title: 'Проверка на битые пиксели', text: 'Проверка экрана по согласованной процедуре до передачи или установки.', icon: Sparkles },
  { title: 'Дополнительные услуги', text: 'Сложный монтаж, кабельные работы и другие задачи оцениваются после уточнения условий.', icon: Wrench },
];

export default function ServicesPage() { return <main className="min-h-screen bg-graphite-50 dark:bg-graphite-950 py-24 sm:py-32"><div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8"><header className="max-w-3xl mb-12"><span className="text-sm font-semibold uppercase tracking-widest text-accent-500">Помощь специалистов</span><h1 className="mt-3 font-display text-4xl sm:text-5xl font-extrabold">Сервисные услуги</h1><p className="mt-5 text-lg text-graphite-600 dark:text-graphite-300">Услуги заказываются отдельно и не добавляются к заказу автоматически. Стоимость и возможность выполнения подтверждает менеджер.</p></header><div className="grid sm:grid-cols-2 gap-6">{services.map(({title,text,icon:Icon})=><section key={title} className="rounded-3xl border border-graphite-200 dark:border-white/10 bg-white dark:bg-graphite-900 p-7"><div className="w-12 h-12 rounded-2xl bg-accent-500/15 flex items-center justify-center mb-5"><Icon className="w-6 h-6 text-accent-500" /></div><h2 className="font-display text-xl font-bold">{title}</h2><p className="mt-3 text-graphite-600 dark:text-graphite-300 leading-relaxed">{text}</p><p className="mt-5 font-semibold text-accent-500">Стоимость уточняется</p></section>)}</div></div></main>; }
