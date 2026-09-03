import type { Order, CheckoutFormData, CartItem } from '@/types';

const API_URL = 'https://telvora.ru/api.php';

type CartValidationItem = {
  product_id: number | null;
  product_variant_id: number | null;
  slug: string | null;
  assembly_country: string | null;
  price: number | null;
  status: 'in_stock' | 'out_of_stock' | 'expected' | 'unknown';
  orderable: boolean;
  expected_arrival_at: string | null;
  message: string | null;
};

function cartPayload(items: CartItem[]) {
  return items.map((item) => ({
    product_id: Number(item.productId),
    product_variant_id: item.productVariantId,
    slug: item.slug,
    assembly_country: item.assemblyCountry ?? '',
    quantity: item.quantity,
  }));
}

export async function validateCart(items: CartItem[]): Promise<{ allOrderable: boolean; items: CartValidationItem[] }> {
  const response = await fetch(`${API_URL}?action=validate_cart`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ items: cartPayload(items) }),
  });
  const result = await response.json() as { success?: boolean; all_orderable?: boolean; items?: CartValidationItem[]; message?: string };
  if (!response.ok || !result.success || !Array.isArray(result.items)) throw new Error(result.message || 'Не удалось проверить наличие товаров');
  return { allOrderable: Boolean(result.all_orderable), items: result.items };
}

export async function createOrder(
  formData: CheckoutFormData,
  cartItems: CartItem[]
): Promise<Order> {
  if (cartItems.length === 0) {
    throw new Error('Корзина пуста — невозможно оформить заказ');
  }

  const order: Order = {
    id: crypto.randomUUID(),
    orderNumber: '',

    items: cartItems.map((item) => ({
      productId: item.productId,
      slug: item.slug,
      name: item.name,
      price: item.price,
      image: item.image,
      screenSize: item.screenSize,
      category: item.category,
      quantity: item.quantity,
      assemblyCountry: item.assemblyCountry,
      productVariantId: item.productVariantId,
    })),

    customer: formData,
    subtotal: 0,
    delivery: 0,
    total: 0,
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
        product_id: Number(item.productId), product_variant_id: item.productVariantId,
        slug: item.slug, quantity: item.quantity, assembly_country: item.assemblyCountry ?? '',
      })),
    }),
  });

  let result: {
    success?: boolean;
    order_id?: number;
    order_number?: string;
    message?: string;
    code?: string;
    subtotal?: number;
    delivery?: number;
    total?: number;
    items?: Array<{
      product_id: number;
      product_variant_id: number;
      slug: string;
      assembly_country: string;
      name: string;
      quantity: number;
      price: number;
    }>;
  };

  try {
    result = await response.json();
  } catch {
    throw new Error('Сервер вернул некорректный ответ');
  }

  if (!response.ok || !result.success || !result.order_number ||
      typeof result.subtotal !== 'number' || typeof result.delivery !== 'number' || typeof result.total !== 'number' ||
      !Array.isArray(result.items) || result.items.length !== cartItems.length) {
    throw new Error(
      result.message || 'Не удалось сохранить заказ'
    );
  }

  order.orderNumber = result.order_number;
  order.subtotal = result.subtotal;
  order.delivery = result.delivery;
  order.total = result.total;
  order.items = result.items.map((serverItem, index) => ({
    ...order.items[index],
    productId: String(serverItem.product_id),
    productVariantId: serverItem.product_variant_id,
    slug: serverItem.slug,
    name: serverItem.name,
    assemblyCountry: serverItem.assembly_country,
    quantity: serverItem.quantity,
    price: serverItem.price,
  }));

  return order;
}



