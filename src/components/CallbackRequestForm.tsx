import { FormEvent, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { PhoneCall } from 'lucide-react';

type FormStatus = { kind: 'idle' | 'sending' | 'success' | 'error'; message: string };

export default function CallbackRequestForm() {
  const startedAt = useRef(Date.now());
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [preferredTime, setPreferredTime] = useState('');
  const [consent, setConsent] = useState(false);
  const [company, setCompany] = useState('');
  const [status, setStatus] = useState<FormStatus>({ kind: 'idle', message: '' });

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!consent) {
      setStatus({ kind: 'error', message: 'Подтвердите согласие на обработку персональных данных.' });
      return;
    }

    setStatus({ kind: 'sending', message: '' });
    try {
      const response = await fetch('/callback_request.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ name, phone, preferred_time: preferredTime, consent, company, started_at: startedAt.current }),
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.success) throw new Error(data?.message || 'Не удалось принять заявку. Позвоните или напишите нам.');
      setStatus({ kind: 'success', message: 'Заявка принята. Мы свяжемся с вами в указанное время.' });
      setName(''); setPhone(''); setPreferredTime(''); setConsent(false); setCompany(''); startedAt.current = Date.now();
    } catch (error) {
      setStatus({ kind: 'error', message: error instanceof Error ? error.message : 'Не удалось принять заявку. Позвоните или напишите нам.' });
    }
  };

  const fieldClass = 'mt-2 w-full rounded-xl border border-graphite-200 bg-graphite-50 px-4 py-3 text-graphite-900 outline-none transition placeholder:text-graphite-400 focus:border-accent-500 focus:ring-2 focus:ring-accent-500/10 dark:border-white/10 dark:bg-graphite-950 dark:text-white';

  return (
    <section id="callback" className="scroll-mt-24 rounded-3xl border border-graphite-200 bg-white p-6 shadow-sm dark:border-white/5 dark:bg-graphite-900 sm:p-8" aria-labelledby="callback-title">
      <div className="mb-6 flex items-start gap-4">
        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-500/10"><PhoneCall className="h-5 w-5 text-accent-600 dark:text-accent-500" /></div>
        <div><h2 id="callback-title" className="font-display text-2xl font-bold">Заказать обратный звонок</h2><p className="mt-1 text-graphite-600 dark:text-graphite-400">Оставьте номер и удобное время — заявка поступит в службу поддержки.</p></div>
      </div>
      <form onSubmit={submit} className="space-y-5">
        <div className="grid gap-4 md:grid-cols-2">
          <label className="block text-sm font-medium">Имя<input className={fieldClass} name="name" autoComplete="name" required minLength={2} maxLength={80} value={name} onChange={(e) => setName(e.target.value)} /></label>
          <label className="block text-sm font-medium">Телефон<input className={fieldClass} name="phone" type="tel" autoComplete="tel" required minLength={7} maxLength={32} placeholder="+7 (___) ___-__-__" value={phone} onChange={(e) => setPhone(e.target.value)} /></label>
        </div>
        <label className="block text-sm font-medium">Удобное время звонка<input className={fieldClass} name="preferred_time" required minLength={2} maxLength={120} placeholder="Например, сегодня после 18:00" value={preferredTime} onChange={(e) => setPreferredTime(e.target.value)} /></label>
        <label className="absolute -left-[10000px] top-auto h-px w-px overflow-hidden" aria-hidden="true">Компания<input name="company" tabIndex={-1} autoComplete="off" value={company} onChange={(e) => setCompany(e.target.value)} /></label>
        <div className="flex items-start gap-3">
          <input id="callback-consent" type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} className="mt-0.5 h-5 w-5 shrink-0 cursor-pointer accent-accent-500" aria-describedby="callback-consent-text" />
          <p id="callback-consent-text" className="text-sm leading-relaxed text-graphite-700 dark:text-graphite-300"><label htmlFor="callback-consent" className="cursor-pointer">Я даю </label><Link to="/personal-data-consent" target="_blank" rel="noopener noreferrer" className="font-medium text-accent-600 hover:underline dark:text-accent-500">согласие на обработку персональных данных</Link> и ознакомился с <Link to="/privacy" target="_blank" rel="noopener noreferrer" className="font-medium text-accent-600 hover:underline dark:text-accent-500">Политикой обработки персональных данных</Link>.</p>
        </div>
        <button type="submit" disabled={status.kind === 'sending'} className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent-500 px-6 py-3 font-semibold text-white transition-colors hover:bg-accent-600 disabled:cursor-wait disabled:opacity-60 sm:w-auto"><PhoneCall className="h-4 w-4" />{status.kind === 'sending' ? 'Отправляем...' : 'Заказать обратный звонок'}</button>
        {status.message && <p role={status.kind === 'error' ? 'alert' : 'status'} className={`text-sm ${status.kind === 'error' ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400'}`}>{status.message}</p>}
      </form>
    </section>
  );
}
