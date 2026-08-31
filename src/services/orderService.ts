import type { Order, CheckoutFormData, CartItem } from '@/types';
import { siteContent } from '@/data/siteContent';

const API_URL = 'https://telvora.ru/api.php';

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
    orderNumber: '',

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
    order_number?: string;
    message?: string;
  };

  try {
    result = await response.json();
  } catch {
    throw new Error('Сервер вернул некорректный ответ');
  }

  if (!response.ok || !result.success || !result.order_number) {
    throw new Error(
      result.message || 'Не удалось сохранить заказ'
    );
  }

  order.orderNumber = result.order_number;

  return order;
}



