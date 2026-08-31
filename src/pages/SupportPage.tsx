import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, ChevronDown, CircleHelp, Headphones, PackageCheck, Search, ShieldCheck, Truck } from 'lucide-react';

const helpItems = [
  { title: 'Доставка', description: 'Узнайте о доступных способах получения заказа и о том, где уточняются сроки доставки.', action: 'Подробнее о доставке', to: '/delivery', icon: Truck },
  { title: 'Гарантия', description: 'Посмотрите общую информацию о гарантийном обслуживании и дальнейших действиях.', action: 'Условия гарантии', to: '/warranty', icon: ShieldCheck },
] as const;

const faqItems = [
  { question: 'Как проверить статус заказа?', answer: 'Введите номер заказа и телефон, указанный при оформлении, в форме проверки выше.' },
  { question: 'Можно ли изменить данные заказа после оформления?', answer: 'Если заказ ещё не выполнен, свяжитесь с поддержкой и сообщите номер заказа. Возможность изменения зависит от текущего статуса.' },
  { question: 'Где посмотреть информацию о доставке?', answer: 'Основная информация о способах получения заказа собрана на странице «Доставка».' },
  { question: 'Где посмотреть условия гарантии?', answer: 'Общая информация о гарантийном обслуживании находится на странице «Гарантия».' },
  { question: 'Что делать, если нужна помощь по заказу?', answer: 'Подготовьте номер заказа и телефон, указанный при оформлении. Это поможет подтвердить заказ и уточнить его статус.' },
];

export default function SupportPage() {
  const [openFaq, setOpenFaq] = useState<number | null>(null);
  const [orderNumber, setOrderNumber] = useState('');
  const [phone, setPhone] = useState('');
  const [trackingLoading, setTrackingLoading] = useState(false);
  const [trackingError, setTrackingError] = useState('');
  const [trackingResult, setTrackingResult] = useState<{ id: number; status: string } | null>(null);

  const trackOrder = async () => {
    const value = orderNumber.trim();
    const phoneValue = phone.trim();

    if (!value || !phoneValue) {
      setTrackingError('Введите номер заказа и телефон');
      setTrackingResult(null);
      return;
    }

    setTrackingLoading(true);
    setTrackingError('');
    setTrackingResult(null);

    try {
      const response = await fetch('/manager.php?action=track_order', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order_number: value, phone: phoneValue }),
      });
      const data = await response.json();

      if (!response.ok || !data.success) {
        setTrackingError(data.message || 'Заказ не найден');
        return;
      }
      setTrackingResult(data.order);
    } catch {
      setTrackingError('Не удалось получить статус заказа');
    } finally {
      setTrackingLoading(false);
    }
  };

  const scrollToTracking = () => document.getElementById('order-tracking')?.scrollIntoView({ behavior: 'smooth', block: 'start' });

  return (
    <main className="min-h-screen bg-graphite-50 text-graphite-900 dark:bg-graphite-950 dark:text-white py-20 sm:py-24">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <header className="max-w-3xl mb-10 sm:mb-12">
          <span className="text-sm font-semibold text-accent-600 dark:text-accent-500 uppercase tracking-widest">Служба поддержки</span>
          <h1 className="font-display font-extrabold text-4xl sm:text-5xl mt-2 tracking-tight">Поддержка TELVORA</h1>
          <p className="text-graphite-600 dark:text-graphite-300 text-lg mt-4 leading-relaxed">Поможем с заказом, доставкой, гарантией и использованием техники.</p>
        </header>

        <section id="order-tracking" className="scroll-mt-24 mb-14 sm:mb-16">
          <div className="p-6 sm:p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm">
            <div className="flex items-start gap-4 mb-6">
              <div className="w-11 h-11 rounded-2xl bg-accent-500/10 flex items-center justify-center shrink-0"><PackageCheck className="w-5 h-5 text-accent-600 dark:text-accent-500" /></div>
              <div>
                <h2 className="font-display font-bold text-2xl">Проверить заказ</h2>
                <p className="text-graphite-600 dark:text-graphite-400 mt-1">Укажите номер заказа и телефон, использованный при оформлении.</p>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <label className="block">
                <span className="block text-sm font-medium mb-2">Номер заказа</span>
                <input type="text" required autoComplete="off" value={orderNumber} onChange={(event) => { setOrderNumber(event.target.value); setTrackingError(''); }} onKeyDown={(event) => { if (event.key === 'Enter') trackOrder(); }} placeholder="TLV-20260831-0040" className="w-full px-4 py-3 rounded-xl bg-graphite-50 dark:bg-graphite-950 border border-graphite-200 dark:border-white/10 text-graphite-900 dark:text-white placeholder:text-graphite-400 outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-500/10 transition" />
                <span className="block text-xs text-graphite-500 mt-2">Пример: TLV-20260831-0040</span>
              </label>
              <label className="block">
                <span className="block text-sm font-medium mb-2">Телефон</span>
                <input type="tel" required autoComplete="tel" value={phone} onChange={(event) => { setPhone(event.target.value); setTrackingError(''); }} onKeyDown={(event) => { if (event.key === 'Enter') trackOrder(); }} placeholder="Телефон, указанный при оформлении" className="w-full px-4 py-3 rounded-xl bg-graphite-50 dark:bg-graphite-950 border border-graphite-200 dark:border-white/10 text-graphite-900 dark:text-white placeholder:text-graphite-400 outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-500/10 transition" />
              </label>
            </div>

            <button type="button" onClick={trackOrder} disabled={trackingLoading} className="mt-5 w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-colors disabled:opacity-60">
              <Search className="w-4 h-4" />{trackingLoading ? 'Проверяем...' : 'Проверить статус'}
            </button>

            {trackingError && <p role="alert" className="mt-4 text-sm text-red-600 dark:text-red-400">{trackingError}</p>}
            {trackingResult && (
              <div className="mt-5 p-5 rounded-2xl bg-accent-50 dark:bg-graphite-950 border border-accent-200 dark:border-accent-500/20">
                <div className="text-sm text-graphite-600 dark:text-graphite-400">Заказ №{trackingResult.id}</div>
                <div className="mt-1 text-xl font-bold">{trackingResult.status}</div>
              </div>
            )}
          </div>
        </section>

        <section className="mb-14 sm:mb-16">
          <div className="mb-6">
            <span className="text-sm font-semibold text-accent-600 dark:text-accent-500 uppercase tracking-widest">Быстрые ответы</span>
            <h2 className="font-display font-bold text-3xl mt-2">Чем можем помочь</h2>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
            {helpItems.map((item) => (
              <article key={item.title} className="flex flex-col p-6 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm">
                <div className="w-11 h-11 rounded-2xl bg-accent-500/10 flex items-center justify-center mb-5"><item.icon className="w-5 h-5 text-accent-600 dark:text-accent-500" /></div>
                <h3 className="font-display font-bold text-xl">{item.title}</h3>
                <p className="text-sm text-graphite-600 dark:text-graphite-400 leading-relaxed mt-2 mb-6 flex-1">{item.description}</p>
                <Link to={item.to} className="inline-flex items-center gap-2 text-sm font-semibold text-accent-600 hover:text-accent-700 dark:text-accent-500 transition-colors">{item.action}<ArrowRight className="w-4 h-4" /></Link>
              </article>
            ))}
            <article className="flex flex-col p-6 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm">
              <div className="w-11 h-11 rounded-2xl bg-accent-500/10 flex items-center justify-center mb-5"><CircleHelp className="w-5 h-5 text-accent-600 dark:text-accent-500" /></div>
              <h3 className="font-display font-bold text-xl">Заказ</h3>
              <p className="text-sm text-graphite-600 dark:text-graphite-400 leading-relaxed mt-2 mb-6 flex-1">Проверьте актуальный статус по номеру заказа и телефону из оформления.</p>
              <button type="button" onClick={scrollToTracking} className="inline-flex items-center gap-2 text-left text-sm font-semibold text-accent-600 hover:text-accent-700 dark:text-accent-500 transition-colors">Проверить заказ<ArrowRight className="w-4 h-4" /></button>
            </article>
          </div>
        </section>

        <section id="faq" className="scroll-mt-24 mb-14 sm:mb-16">
          <div className="mb-6">
            <span className="text-sm font-semibold text-accent-600 dark:text-accent-500 uppercase tracking-widest">Полезная информация</span>
            <h2 className="font-display font-bold text-3xl mt-2">Частые вопросы</h2>
          </div>
          <div className="overflow-hidden bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm divide-y divide-graphite-200 dark:divide-white/5">
            {faqItems.map((item, index) => {
              const isOpen = openFaq === index;
              return (
                <div key={item.question}>
                  <button type="button" onClick={() => setOpenFaq(isOpen ? null : index)} aria-expanded={isOpen} aria-controls={`faq-answer-${index}`} className="w-full flex items-center justify-between gap-4 p-5 sm:px-6 text-left hover:bg-graphite-50 dark:hover:bg-white/[0.03] transition-colors">
                    <span className="font-semibold">{item.question}</span>
                    <ChevronDown className={`w-5 h-5 text-accent-600 shrink-0 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                  </button>
                  {isOpen && <div id={`faq-answer-${index}`} className="px-5 sm:px-6 pb-5 text-sm text-graphite-600 dark:text-graphite-400 leading-relaxed">{item.answer}</div>}
                </div>
              );
            })}
          </div>
        </section>

        <section id="returns" className="scroll-mt-24 mb-5 p-6 sm:p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm">
          <span className="text-sm font-semibold text-accent-600 dark:text-accent-500 uppercase tracking-widest">Возврат</span>
          <h2 className="font-display font-bold text-2xl mt-2">Вопросы о возврате</h2>
          <p className="text-graphite-600 dark:text-graphite-400 mt-2 leading-relaxed">
            Возможность и порядок возврата зависят от товара и причины обращения. Подготовьте номер заказа и документы о покупке, чтобы поддержка могла уточнить дальнейшие действия.
          </p>
        </section>

        <section className="flex flex-col sm:flex-row sm:items-center gap-5 p-6 sm:p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm">
          <div className="w-12 h-12 rounded-2xl bg-accent-500/10 flex items-center justify-center shrink-0"><Headphones className="w-6 h-6 text-accent-600 dark:text-accent-500" /></div>
          <div>
            <h2 className="font-display font-bold text-2xl">Нужна дополнительная помощь?</h2>
            <p className="text-graphite-600 dark:text-graphite-400 mt-2 leading-relaxed">Подготовьте номер заказа и данные, указанные при оформлении. Это поможет службе поддержки быстрее разобраться в вопросе.</p>
          </div>
        </section>
      </div>
    </main>
  );
}
