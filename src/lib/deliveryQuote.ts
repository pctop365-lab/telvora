import type { CartItem, DeliveryMethod } from '@/types';

export type DeliveryQuote = {
  status: 'confirmed' | 'pending';
  price: number | null;
  estimate: number | null;
  reason: 'pickup' | 'moscow' | 'moscow_terminal' | 'multiple_televisions' | 'size_not_tariffed' | 'outside_mkad_requires_confirmation';
};

export const deliveryTariffs = [
  { label: '43–55″', min: 43, max: 55, price: 1000 },
  { label: '56–65″', min: 56, max: 65, price: 1500 },
  { label: '75–77″', min: 75, max: 77, price: 2000 },
  { label: '83–85″', min: 83, max: 85, price: 3000 },
  { label: '98–100″', min: 98, max: 100, price: 5000 },
  { label: '115–116″', min: 115, max: 116, price: 8000 },
  { label: '136–146″', min: 136, max: 146, price: 25000 },
] as const;

export function parseScreenSize(value: string): number | null {
  const match = value.match(/\d{2,3}/);
  const size = match ? Number(match[0]) : NaN;
  return Number.isInteger(size) && size >= 20 && size <= 200 ? size : null;
}

export function moscowDeliveryRate(size: number | null): number | null {
  return deliveryTariffs.find((tariff) => size !== null && size >= tariff.min && size <= tariff.max)?.price ?? null;
}

export function calculateDeliveryQuote(method: DeliveryMethod, items: CartItem[], outsideMkad = false, requestedKm?: number): DeliveryQuote {
  if (method === 'pickup') return { status: 'confirmed', price: 0, estimate: 0, reason: 'pickup' };
  const units = items.reduce((sum, item) => sum + item.quantity, 0);
  if (items.length !== 1 || units !== 1) return { status: 'pending', price: null, estimate: null, reason: 'multiple_televisions' };
  const base = moscowDeliveryRate(parseScreenSize(items[0].screenSize));
  if (base === null) return { status: 'pending', price: null, estimate: null, reason: 'size_not_tariffed' };
  if (outsideMkad) {
    const km = Number.isInteger(requestedKm) && (requestedKm ?? 0) > 0 && (requestedKm ?? 0) <= 500 ? requestedKm! : null;
    return { status: 'pending', price: null, estimate: km === null ? null : base + km * 60, reason: 'outside_mkad_requires_confirmation' };
  }
  return { status: 'confirmed', price: base, estimate: base, reason: method === 'post' ? 'moscow_terminal' : 'moscow' };
}

export function deliveryQuoteLabel(quote: DeliveryQuote): string {
  if (quote.status === 'confirmed') return quote.price === 0 ? 'Без доплаты' : `${quote.price!.toLocaleString('ru-RU')} ₽`;
  if (quote.estimate !== null) return `Ориентировочно ${quote.estimate.toLocaleString('ru-RU')} ₽ · подтвердит менеджер`;
  return 'Стоимость согласовывается';
}
