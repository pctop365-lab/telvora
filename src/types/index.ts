export type ProductCategory = 'OLED' | 'QLED' | 'LED' | '8K';

export type Spec = {
  label: string;
  value: string;
};

export type AvailabilityStatus = 'in_stock' | 'out_of_stock' | 'expected' | 'unknown';

export type PublicVariantAvailability = {
  productVariantId: number;
  status: AvailabilityStatus;
  orderable: boolean;
  expectedArrivalAt?: string | null;
};

export type ProductVariant = {
  productVariantId: number;
  country: string;
  displayName?: string;
  price: number;
  oldPrice?: number;
  isActive?: boolean;
  availability: PublicVariantAvailability;
};

export type Product = {
  id: string;
  slug: string;
  name: string;
  series: string;
  category: ProductCategory;
  screenSize: string;
  resolution: string;
  price: number;
  oldPrice?: number;
  image: string;
  badge?: string;
  rating: number;
  reviews: number;
  description: string;
  specs: Spec[];
  highlights: string[];
  variants?: ProductVariant[];
};

export type Category = {
  slug: string;
  label: string;
  description: string;
};

export type CartItem = {
  id: string;
  slug: string;
  name: string;
  price: number;
  image: string;
  screenSize: string;
  category: ProductCategory;
  quantity: number;
  assemblyCountry?: string;
  productId: string;
  productVariantId?: number;
  availability?: PublicVariantAvailability;
  validationError?: string;
};

export type SortKey = 'default' | 'price-asc' | 'price-desc' | 'rating';

export type DeliveryMethod = 'courier' | 'pickup' | 'post';

export type OrderItem = {
  productId: string;
  slug: string;
  name: string;
  price: number;
  image: string;
  screenSize: string;
  category: ProductCategory;
  quantity: number;
  assemblyCountry?: string;
  productVariantId?: number;
};

export type CheckoutFormData = {
  fullName: string;
  phone: string;
  email: string;
  address: string;
  deliveryMethod: DeliveryMethod;
  deliveryTime?: string;
  paymentMethod: 'cash' | 'sbp';
  comment?: string;
};


export type Order = {
  id: string;
  orderNumber: string;
  items: OrderItem[];
  customer: CheckoutFormData;
  subtotal: number;
  delivery: number;
  total: number;
  status: 'pending' | 'confirmed' | 'shipped' | 'delivered' | 'cancelled';
  createdAt: string;
};

