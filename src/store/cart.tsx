import { createContext, useContext, useReducer, useCallback, useMemo, useEffect, type ReactNode } from 'react';
import type { CartItem, Product } from '@/types';
import { siteContent } from '@/data/siteContent';

const CART_STORAGE_KEY = 'telvora_cart';

function loadCartFromStorage(): CartItem[] {
  try {
    const raw = localStorage.getItem(CART_STORAGE_KEY);
    return raw ? (JSON.parse(raw) as CartItem[]) : [];
  } catch {
    return [];
  }
}

// ---------- Actions ----------

type CartAction =
  | { type: 'ADD'; product: Product; quantity?: number; variant?: { country: string; price: number; oldPrice?: number } }
  | { type: 'REMOVE'; id: string }
  | { type: 'UPDATE_QTY'; id: string; delta: number }
  | { type: 'SET_QTY'; id: string; quantity: number }
  | { type: 'CLEAR' };

// ---------- Reducer ----------

function cartReducer(state: CartItem[], action: CartAction): CartItem[] {
  switch (action.type) {
    case 'ADD': {
      const qty = action.quantity ?? 1;
      const variant = action.variant;

      const cartId = variant
        ? `${action.product.id}__${variant.country.toLowerCase().trim()}`
        : action.product.id;

      const price = variant?.price ?? action.product.price;

      const existing = state.find((item) => item.id === cartId);

      if (existing) {
        return state.map((item) =>
          item.id === cartId
            ? { ...item, quantity: item.quantity + qty }
            : item
        );
      }

      return [
        ...state,
        {
          id: cartId,
          slug: action.product.slug,
          name: action.product.name,
          price,
          image: action.product.image,
          screenSize: action.product.screenSize,
          category: action.product.category,
          quantity: qty,
          assemblyCountry: variant?.country,
        },
      ];
    }
    case 'REMOVE':
      return state.filter((item) => item.id !== action.id);
    case 'UPDATE_QTY':
      return state
        .map((item) =>
          item.id === action.id
            ? { ...item, quantity: Math.max(0, item.quantity + action.delta) }
            : item
        )
        .filter((item) => item.quantity > 0);
    case 'SET_QTY':
      if (action.quantity <= 0) return state.filter((item) => item.id !== action.id);
      return state.map((item) =>
        item.id === action.id ? { ...item, quantity: action.quantity } : item
      );
    case 'CLEAR':
      return [];
    default:
      return state;
  }
}

// ---------- Context type ----------

type CartContextValue = {
  items: CartItem[];
  count: number;
  subtotal: number;
  delivery: number;
  total: number;
  addToCart: (product: Product, quantity?: number, variant?: { country: string; price: number; oldPrice?: number }) => void;
  removeFromCart: (id: string) => void;
  updateQuantity: (id: string, delta: number) => void;
  setQuantity: (id: string, quantity: number) => void;
  clearCart: () => void;
};

const CartContext = createContext<CartContextValue | null>(null);

// ---------- Provider ----------

export function CartProvider({ children }: { children: ReactNode }) {
  const [items, dispatch] = useReducer(cartReducer, undefined, loadCartFromStorage);

  useEffect(() => {
    try {
      localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(items));
    } catch {
      // storage may be full or unavailable — silently ignore
    }
  }, [items]);

  const addToCart = useCallback(
    (
      product: Product,
      quantity?: number,
      variant?: { country: string; price: number; oldPrice?: number }
    ) => {
      dispatch({ type: 'ADD', product, quantity, variant });
    },
    []
  );

  const removeFromCart = useCallback((id: string) => {
    dispatch({ type: 'REMOVE', id });
  }, []);

  const updateQuantity = useCallback((id: string, delta: number) => {
    dispatch({ type: 'UPDATE_QTY', id, delta });
  }, []);

  const setQuantity = useCallback((id: string, quantity: number) => {
    dispatch({ type: 'SET_QTY', id, quantity });
  }, []);

  const clearCart = useCallback(() => {
    dispatch({ type: 'CLEAR' });
  }, []);

  const value = useMemo<CartContextValue>(() => {
    const subtotal = items.reduce((sum, item) => sum + item.price * item.quantity, 0);
    const count = items.reduce((sum, item) => sum + item.quantity, 0);
    const delivery =
      subtotal === 0 || subtotal >= siteContent.freeDeliveryThreshold
        ? 0
        : siteContent.deliveryFee;
    return {
      items,
      count,
      subtotal,
      delivery,
      total: subtotal + delivery,
      addToCart,
      removeFromCart,
      updateQuantity,
      setQuantity,
      clearCart,
    };
  }, [items, addToCart, removeFromCart, updateQuantity, setQuantity, clearCart]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

// ---------- Hook ----------

export function useCart(): CartContextValue {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error('useCart must be used within CartProvider');
  return ctx;
}
