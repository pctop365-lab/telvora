import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useCustomer } from '@/store/customer';
import { loadCustomerOrders, type OrderHistory } from '@/services/customerService';
import CustomerOrders from '@/components/CustomerOrders';

const inputClass = 'mt-1 w-full rounded-xl border border-graphite-200 dark:border-white/10 bg-white dark:bg-graphite-900 px-4 py-3 focus:ring-2 focus:ring-accent-500 outline-none';
const buttonClass = 'rounded-xl bg-accent-500 hover:bg-accent-600 px-5 py-3 text-white font-medium disabled:opacity-50';
export default function AccountPage() {
  const { customer, loading, error, recoveryAvailable, refresh, act } = useCustomer();
  const [mode, setMode] = useState<'login' | 'register' | 'reset_request'>('login');
  const [login, setLogin] = useState(''); const [password, setPassword] = useState(''); const [repeat, setRepeat] = useState('');
  const [message, setMessage] = useState(''); const [busy, setBusy] = useState(false);
  const [profile, setProfile] = useState({ full_name: '', phone: '', email: '', address: '' });
  const [history, setHistory] = useState<OrderHistory>({ orders: [], page: 1, total: 0 });
  const [historyError, setHistoryError] = useState('');
  const [token, setToken] = useState<{ purpose: 'verify' | 'reset'; value: string } | null>(() => {
    const match = typeof window !== 'undefined' ? /^#(verify|reset)=([a-f0-9]{64})$/.exec(window.location.hash) : null;
    return match ? { purpose: match[1] as 'verify' | 'reset', value: match[2] } : null;
  });
  useEffect(() => { if (token) window.history.replaceState(null, '', window.location.pathname); }, [token]);
  useEffect(() => { if (customer) setProfile({ full_name: customer.full_name, phone: customer.phone, email: customer.email, address: customer.address }); }, [customer]);
  const loadOrders = async (page: number) => { try { setHistory(await loadCustomerOrders(page)); setHistoryError(''); } catch (error) { setHistoryError(error instanceof Error ? error.message : 'Не удалось загрузить заказы.'); } };
  useEffect(() => {
    let cancelled = false;
    setHistory({ orders: [], page: 1, total: 0 }); setHistoryError('');
    if (customer) loadCustomerOrders(1).then(result => { if (!cancelled) setHistory(result); }).catch(error => { if (!cancelled) setHistoryError(error instanceof Error ? error.message : 'Не удалось загрузить заказы.'); });
    return () => { cancelled = true; };
  }, [customer?.id]);
  const run = async (action: string, data: Record<string, unknown>, success: string) => {
    setBusy(true); setMessage('');
    try { const result = await act(action, data); setMessage(result.message || success); setPassword(''); setRepeat(''); if (['register', 'login', 'logout'].includes(action)) setMode('login'); if (action.endsWith('_token')) { setToken(null); await refresh(); } }
    catch (error) { setMessage(error instanceof Error ? error.message : 'Не удалось выполнить действие.'); }
    finally { setBusy(false); }
  };
  const submitAuth = (event: FormEvent) => { event.preventDefault(); void run(mode, { login, password, password_repeat: repeat }, ''); };
  return <div className="max-w-4xl mx-auto px-4 pt-36 pb-16">
    <h1 className="font-display text-3xl font-bold mb-6">Личный кабинет</h1>
    {loading ? <p>Загрузка…</p> : <>
      {error && <p role="alert" className="mb-4">{error} <button onClick={() => void refresh()} className="text-accent-500">Повторить</button></p>}
      {message && <p role="status" className="mb-4 rounded-xl border border-accent-500 p-4">{message}</p>}
      {token ? <form className="space-y-4" onSubmit={event => { event.preventDefault(); void run(`${token.purpose}_token`, { token: token.value, password, password_repeat: repeat }, 'Готово.'); }}>
        <h2 className="text-xl">{token.purpose === 'verify' ? 'Подтвердить email' : 'Новый пароль'}</h2>
        {token.purpose === 'reset' && <><label className="block">Новый пароль<input className={inputClass} type="password" autoComplete="new-password" required minLength={10} maxLength={72} value={password} onChange={e => setPassword(e.target.value)} /></label><label className="block">Повтор пароля<input className={inputClass} type="password" autoComplete="new-password" required value={repeat} onChange={e => setRepeat(e.target.value)} /></label></>}
        <button className={buttonClass} disabled={busy}>Подтвердить</button>
      </form> : customer ? <>
        <div className="flex flex-wrap justify-between gap-4 mb-6"><p className="break-all">Логин: <strong>{customer.login}</strong></p><button disabled={busy} onClick={() => void run('logout', {}, 'Вы вышли из аккаунта.')} className="text-accent-500">Выйти</button></div>
        <form className="rounded-3xl bg-graphite-100 dark:bg-graphite-800 p-6 space-y-4" onSubmit={e => { e.preventDefault(); void run('profile', profile, 'Профиль сохранён.'); }}>
          <h2 className="text-xl font-semibold">Данные для заказов</h2>
          {([{ key: 'full_name', label: 'ФИО', type: 'text', auto: 'name', max: 200 }, { key: 'phone', label: 'Телефон', type: 'tel', auto: 'tel', max: 32 }, { key: 'email', label: 'Email (необязательно)', type: 'email', auto: 'email', max: 254 }, { key: 'address', label: 'Адрес доставки', type: 'text', auto: 'street-address', max: 1000 }] as const).map(field => <label key={field.key} className="block">{field.label}<input className={inputClass} type={field.type} autoComplete={field.auto} maxLength={field.max} value={profile[field.key]} onChange={e => setProfile({ ...profile, [field.key]: e.target.value })} /></label>)}
          <button className={buttonClass} disabled={busy}>Сохранить профиль</button>
          <p className="text-sm opacity-70">Изменение профиля не меняет ранее оформленные заказы.</p>
        </form>
        <div className="my-6 space-y-2"><h2 className="font-semibold">Восстановление доступа</h2>{recoveryAvailable ? customer.email_verified_at ? <p>Email подтверждён. Он доступен для восстановления пароля.</p> : <><p>Подтвердите сохранённый email, чтобы при необходимости восстановить пароль. Это необязательно.</p><button disabled={busy || !customer.email} className="text-accent-500 disabled:opacity-40" onClick={() => void run('verify_request', {}, '')}>Отправить письмо для подтверждения</button></> : <p>Восстановление по email пока не подключено.</p>}</div>
        <h2 className="text-2xl font-display font-semibold mb-4">Мои заказы</h2>
        {historyError && <p role="alert">{historyError} <button onClick={() => void loadOrders(history.page)}>Повторить</button></p>}
        <CustomerOrders history={history} onPage={page => void loadOrders(page)} />
      </> : <div className="max-w-lg rounded-3xl bg-graphite-100 dark:bg-graphite-800 p-6">
        <div className="flex gap-6 mb-6"><button onClick={() => { setMode('login'); setMessage(''); }} className={mode === 'login' ? 'text-accent-500' : ''}>Вход</button><button onClick={() => { setMode('register'); setMessage(''); }} className={mode === 'register' ? 'text-accent-500' : ''}>Регистрация</button></div>
        <form onSubmit={submitAuth} className="space-y-4">
          <label className="block">Логин — никнейм или телефон<input className={inputClass} autoComplete="username" required maxLength={64} value={login} onChange={e => setLogin(e.target.value)} /></label>
          {mode === 'register' && <p className="text-sm opacity-70">Никнейм: 3–32 латинских символа, начинается с буквы; допустимы цифры, точка, _ и -. Регистр не учитывается. Телефон — с кодом страны; 8 в начале российского номера равнозначна +7.</p>}
          {mode !== 'reset_request' && <label className="block">Пароль<input className={inputClass} type="password" required minLength={mode === 'register' ? 10 : undefined} maxLength={72} autoComplete={mode === 'register' ? 'new-password' : 'current-password'} value={password} onChange={e => setPassword(e.target.value)} /></label>}
          {mode === 'register' && <><p className="text-sm opacity-70">Пароль: минимум 10 символов, максимум 72 байта (для кириллицы — до 36 символов).</p><label className="block">Повтор пароля<input className={inputClass} type="password" autoComplete="new-password" required value={repeat} onChange={e => setRepeat(e.target.value)} /></label><p className="text-sm">Создавая аккаунт, вы соглашаетесь с <Link className="text-accent-500" to="/privacy">политикой обработки персональных данных</Link>.</p></>}
          <button className={buttonClass} disabled={busy || Boolean(error)}>{mode === 'register' ? 'Зарегистрироваться' : mode === 'reset_request' ? 'Восстановить доступ' : 'Войти'}</button>
        </form>
        {recoveryAvailable ? <button className="mt-5 text-sm text-accent-500" onClick={() => setMode('reset_request')}>Забыли пароль?</button> : <p className="mt-5 text-sm opacity-70">Восстановление по email пока не подключено.</p>}
        <p className="mt-4 text-sm"><Link className="text-accent-500" to="/checkout">Оформить заказ без регистрации</Link></p>
      </div>}
    </>}
  </div>;
}
