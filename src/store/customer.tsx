import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { customerRequest, type Customer, type CustomerResponse } from '@/services/customerService';

type State = { customer: Customer | null; loading: boolean; error: string; recoveryAvailable: boolean; refresh: () => Promise<void>; act: (action: string, payload?: Record<string, unknown>) => Promise<CustomerResponse> };
const CustomerContext = createContext<State | null>(null);
export function CustomerProvider({ children }: { children: ReactNode }) {
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [recoveryAvailable, setRecoveryAvailable] = useState(false);
  const accept = useCallback((result: CustomerResponse) => {
    if ('customer' in result) setCustomer(result.customer ?? null);
    if ('recovery_available' in result) setRecoveryAvailable(Boolean(result.recovery_available));
  }, []);
  const refresh = useCallback(async () => {
    try { accept(await customerRequest()); setError(''); }
    catch (error) { setCustomer(null); setError(error instanceof Error ? error.message : 'Аккаунт временно недоступен.'); }
    finally { setLoading(false); }
  }, [accept]);
  useEffect(() => { void refresh(); }, [refresh]);
  const act = async (action: string, payload: Record<string, unknown> = {}) => {
    const session = await customerRequest();
    if (['profile', 'verify_request'].includes(action) && (!customer || Number(session.customer?.id) !== Number(customer.id))) throw new Error('Аккаунт изменился. Обновите страницу перед сохранением.');
    const result = await customerRequest(action, payload, session.csrf_token);
    accept(result); return result;
  };
  return <CustomerContext.Provider value={{ customer, loading, error, recoveryAvailable, refresh, act }}>{children}</CustomerContext.Provider>;
}
export function useCustomer() {
  const context = useContext(CustomerContext);
  if (!context) throw new Error('CustomerProvider missing');
  return context;
}
