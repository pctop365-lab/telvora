import { createContext, useContext, useState, useCallback, type ReactNode } from 'react';

type UIContextValue = {
  cartOpen: boolean;
  openCart: () => void;
  closeCart: () => void;
  searchQuery: string;
  setSearchQuery: (q: string) => void;
};

const UIContext = createContext<UIContextValue | null>(null);

export function UIProvider({ children }: { children: ReactNode }) {
  const [cartOpen, setCartOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');

  const openCart = useCallback(() => setCartOpen(true), []);
  const closeCart = useCallback(() => setCartOpen(false), []);

  return (
    <UIContext.Provider
      value={{ cartOpen, openCart, closeCart, searchQuery, setSearchQuery }}
    >
      {children}
    </UIContext.Provider>
  );
}

export function useUI(): UIContextValue {
  const ctx = useContext(UIContext);
  if (!ctx) throw new Error('useUI must be used within UIProvider');
  return ctx;
}
