export type Customer = { id: number; login: string; full_name: string; phone: string; email: string; address: string; email_verified_at: string | null; created_at: string };
export type CustomerOrder = { id: number; order_number: string; customer_name: string; phone: string; email: string; address: string; created_at: string; status: string; total: string | number | null; items: { product_name: string; quantity: number; price: string | number }[]; services: { service_name: string; quantity: number; total: string | number }[] };
export type OrderHistory = { orders: CustomerOrder[]; page: number; total: number };
export type CustomerResponse = { success: boolean; customer?: Customer | null; csrf_token?: string; recovery_available?: boolean; message?: string } & Partial<OrderHistory>;

export async function customerRequest(action = 'session', payload?: Record<string, unknown>, csrf?: string): Promise<CustomerResponse> {
  const response = await fetch(`/customer.php?action=${encodeURIComponent(action)}`, {
    method: payload ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
    headers: payload ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf || '' } : {},
    body: payload ? JSON.stringify({ ...payload, action }) : undefined,
  });
  const result = await response.json() as CustomerResponse;
  if (!response.ok || !result.success) throw new Error(result.message || 'Не удалось выполнить запрос.');
  return result;
}

export async function loadCustomerOrders(page: number): Promise<OrderHistory> {
  const response = await fetch(`/customer.php?action=orders&page=${page}`, { credentials: 'same-origin', cache: 'no-store' });
  const data = await response.json();
  if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось загрузить заказы.');
  return data;
}
