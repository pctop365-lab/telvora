import type { Order, CheckoutFormData, CartItem } from '@/types';
import { siteContent } from '@/data/siteContent';

const STORAGE_KEY = 'telvora_orders';
const API_URL = 'https://telvora.ru/api.php';

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
    // localStorage может быть недоступен
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

  const subtotal = cartItems.reduce(
    (sum, item) => sum + item.price * item.quantity,
    0
  );

  const delivery = calculateDelivery(
    subtotal,
    formData.deliveryMethod
  );

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
      assemblyCountry: item.assemblyCountry,
    })),

    customer: formData,
    subtotal,
    delivery,
    total,
    status: 'pending',
    createdAt: new Date().toISOString(),
  };

  // Отправляем заказ на сервер
  const response = await fetch(API_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      customer_name: formData.fullName,
      phone: formData.phone,
      email: formData.email,
      address: formData.address,
      delivery_method: formData.deliveryMethod,
      payment_method: formData.paymentMethod,
      delivery_time: formData.deliveryTime ?? '',
      comment: formData.comment ?? '',

      items: cartItems.map((item) => ({
        slug: item.slug,
        quantity: item.quantity,
        assembly_country: item.assemblyCountry ?? '',
      })),
    }),
  });

  let result: {
    success?: boolean;
    order_id?: number;
    message?: string;
  };

  try {
    result = await response.json();
  } catch {
    throw new Error('Сервер вернул некорректный ответ');
  }

  if (!response.ok || !result.success) {
    throw new Error(
      result.message || 'Не удалось сохранить заказ'
    );
  }

  // Сохраняем локальную копию для текущего интерфейса
  const orders = getStoredOrders();
  orders.push(order);
  saveStoredOrders(orders);

  return order;
}

export async function fetchOrderByNumber(
  orderNumber: string
): Promise<Order | null> {
  const orders = getStoredOrders();

  return (
    orders.find((order) => order.orderNumber === orderNumber) ?? null
  );
}

export async function fetchOrderById(
  id: string
): Promise<Order | null> {
  const orders = getStoredOrders();

  return orders.find((order) => order.id === id) ?? null;
}



