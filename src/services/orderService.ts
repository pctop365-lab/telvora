import type { Order, CheckoutFormData, CartItem } from '@/types';
import { siteContent } from '@/data/siteContent';

/**
 * Order service layer.
 *
 * Currently stores orders in localStorage to simulate a backend.
 * To switch to a real REST API (MySQL via PHP/Node/etc.),
 * replace the bodies of these functions with fetch calls:
 *
 *   export async function createOrder(data: CheckoutData): Promise<Order> {
 *     const res = await fetch('/api/orders', {
 *       method: 'POST',
 *       headers: { 'Content-Type': 'application/json' },
 *       body: JSON.stringify(data),
 *     });
 *     if (!res.ok) throw new Error('Failed to create order');
 *     return res.json();
 *   }
 *
 * The function signatures and return types stay the same,
 * so no UI component needs to change.
 */

const STORAGE_KEY = 'telvora_orders';

function generateOrderNumber(): string {
  const date = new Date();
  const ymd = `${date.getFullYear()}${String(date.getMonth() + 1).padStart(2, '0')}${String(date.getDate()).padStart(2, '0')}`;
  const rand = Math.floor(1000 + Math.random() * 9000);
  return `TLV-${ymd}-${rand}`;
}

function getStoredOrders(): Order[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? (JSON.parse(raw) as Order[]) : [];
  } catch {
    return [];
  }
}

function saveStoredOrders(orders: Order[]): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(orders));
  } catch {
    // storage may be full or unavailable — silently ignore
  }
}

function calculateDelivery(
  subtotal: number,
  method: CheckoutFormData['deliveryMethod']
): number {
  if (method === 'pickup') return 0;
  if (subtotal >= siteContent.freeDeliveryThreshold) return 0;
  return siteContent.deliveryFee;
}

export async function createOrder(
  formData: CheckoutFormData,
  cartItems: CartItem[]
): Promise<Order> {
  if (cartItems.length === 0) {
    throw new Error('Корзина пуста — невозможно оформить заказ');
  }

  const subtotal = cartItems.reduce((sum, item) => sum + item.price * item.quantity, 0);
  const delivery = calculateDelivery(subtotal, formData.deliveryMethod);
  const total = subtotal + delivery;

  const order: Order = {
    id: crypto.randomUUID(),
    orderNumber: generateOrderNumber(),
    items: cartItems.map((item) => ({
      productId: item.id,
      slug: item.slug,
      name: item.name,
      price: item.price,
      image: item.image,
      screenSize: item.screenSize,
      category: item.category,
      quantity: item.quantity,
    })),
    customer: formData,
    subtotal,
    delivery,
    total,
    status: 'pending',
    createdAt: new Date().toISOString(),
  };

  // Simulate network latency for realistic UX
  await new Promise((resolve) => setTimeout(resolve, 600));

  const orders = getStoredOrders();
  orders.push(order);
  saveStoredOrders(orders);

  return order;
}

export async function fetchOrderByNumber(orderNumber: string): Promise<Order | null> {
  await new Promise((resolve) => setTimeout(resolve, 300));

  const orders = getStoredOrders();
  return orders.find((o) => o.orderNumber === orderNumber) ?? null;
}

export async function fetchOrderById(id: string): Promise<Order | null> {
  await new Promise((resolve) => setTimeout(resolve, 300));

  const orders = getStoredOrders();
  return orders.find((o) => o.id === id) ?? null;
}
