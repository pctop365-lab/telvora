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
  brand?: string;
  series: string;
  category: ProductCategory;
  screenSize: string;
  resolution: string;
  price: number;
  oldPrice?: number;
  image: string;
  images?: string[];
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

export type ServiceCategory = 'mounting' | 'pixel_test' | 'other';
export type ServiceCatalogItem = { id:number; service_key:string; category:ServiceCategory; name:string; description:string; min_screen_size:number|null; max_screen_size:number|null; price:number|null; is_active:boolean; sort_order:number; requires_tv:boolean; metadata?:Record<string,unknown>|null };
export type CartServiceItem = { id:string; serviceId:number; serviceKey:string; category:ServiceCategory; name:string; price:number; quantity:number; targetCartItemId:string; televisionName:string; screenSize:number };

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
  outsideMkad?: boolean;
  outsideMkadKm?: number;
};


export type Order = {
  id: string;
  orderNumber: string;
  items: OrderItem[];
  customer: CheckoutFormData;
  subtotal: number;
  services?: CartServiceItem[];
  servicesTotal?: number;
  delivery: number | null;
  deliveryStatus?: 'confirmed' | 'pending';
  deliveryEstimate?: number | null;
  total: number | null;
  status: 'pending' | 'confirmed' | 'shipped' | 'delivered' | 'cancelled';
  createdAt: string;
};

