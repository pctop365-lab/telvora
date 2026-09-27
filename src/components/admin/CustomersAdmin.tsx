import { useEffect, useState } from 'react';
import type { Customer, OrderHistory } from '@/services/customerService';
import CustomerOrders from '@/components/CustomerOrders';
export default function CustomersAdmin() {
  const [query, setQuery] = useState(''); const [search, setSearch] = useState(''); const [page, setPage] = useState(1);
  const [customers, setCustomers] = useState<Customer[]>([]); const [total, setTotal] = useState(0);
  const [selected, setSelected] = useState<number | null>(null); const [detail, setDetail] = useState<(OrderHistory & { customer: Customer }) | null>(null);
  const [error, setError] = useState(''); const [loading, setLoading] = useState(false);
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError(''); setDetail(null);
    const params = selected ? `action=customer_detail&id=${selected}&page=${page}` : `action=customers_list&q=${encodeURIComponent(search)}&page=${page}`;
    fetch(`/manager.php?${params}`, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal }).then(async response => { const data = await response.json(); if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось загрузить клиентов.'); if (selected) setDetail(data); else { setCustomers(data.customers); setTotal(data.total); } }).catch(error => { if (!controller.signal.aborted) setError(error instanceof Error ? error.message : 'Ошибка загрузки.'); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [selected, search, page]);
  return <section className="space-y-5"><h2 className="text-xl font-semibold">Клиенты</h2>
    {selected ? <button onClick={() => { setSelected(null); setPage(1); }} className="text-accent-600">← Все клиенты</button> : <form className="flex gap-3" onSubmit={e => { e.preventDefault(); setSearch(query); setPage(1); }}><input aria-label="Поиск клиентов" className="admin-input" placeholder="Логин, ФИО или телефон" maxLength={100} value={query} onChange={e => setQuery(e.target.value)} /><button className="rounded-xl bg-accent-500 px-5 text-white">Найти</button></form>}
    {error && <p role="alert">{error}</p>}{loading && <p>Загрузка…</p>}
    {!loading && !error && (detail ? <><div className="rounded-2xl border p-5 space-y-2 break-words"><h3 className="font-semibold">{detail.customer.login}</h3><p>{detail.customer.full_name || 'ФИО не указано'}</p><p>{detail.customer.phone || 'Телефон не указан'}</p><p>{detail.customer.email || 'Email не указан'}{detail.customer.email_verified_at && ' (подтверждён)'}</p><p>{detail.customer.address || 'Адрес не указан'}</p></div><h3 className="font-semibold">Связанные заказы</h3><CustomerOrders history={detail} onPage={setPage} /></> : <><div className="space-y-3">{customers.length === 0 && <p>Клиенты не найдены.</p>}{customers.map(customer => <button key={customer.id} onClick={() => { setSelected(customer.id); setPage(1); }} className="block w-full rounded-xl border bg-white p-4 text-left break-words hover:border-accent-500"><strong>{customer.login}</strong><p>{customer.full_name}</p><p>{customer.phone}</p></button>)}</div><div className="flex gap-4 items-center"><button disabled={page <= 1} onClick={() => setPage(page - 1)} className="disabled:opacity-40">Назад</button><span>{page} / {Math.max(1, Math.ceil(total / 20))}</span><button disabled={page * 20 >= total} onClick={() => setPage(page + 1)} className="disabled:opacity-40">Далее</button></div></>)}
  </section>;
}
