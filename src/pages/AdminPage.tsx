import { Fragment, useEffect, useMemo, useState } from 'react';
import {
  LogOut,
  RefreshCw,
  Package,
  Truck,
  CheckCircle2,
  Clock3,
  XCircle,
  ChevronDown,
  Plus,
  Trash2,
  Save,
  Search,
  X,
    Pencil,
  Sun,
  Moon,
} from 'lucide-react';

type Spec = {
  label: string;
  value: string;
};

type OrderItem = {
  product_name: string;
  quantity: number;
  price: number;
};

type Order = {
  id: number;
  customer_name: string;
  phone: string;
  email: string | null;
  address: string | null;
  delivery_method: string;
  payment_method: string;
  comment: string | null;
  total: string | number;
  status: string;
  created_at: string;
  items: OrderItem[];
};

type ProductVariant = {
  country: string;
  price: string;
  old_price: string;
  is_active?: boolean;
};

type AdminProduct = {
  id: number;
  slug: string;
  name: string;
  series: string;
  country: string | null;
  category: string;
  screen_size: string;
  resolution: string;
  price: number;
  old_price: number | null;
  image: string;
  badge: string | null;
  rating: number;
  reviews: number;
  description: string;
  specs: Spec[];
  highlights: string[];
  variants: ProductVariant[];
  is_active: boolean;
  created_at: string;
  updated_at: string;
};

type Supplier = {
  id: number;
  name: string;
  internal_code: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
};

const MANAGER_API = '/manager.php';
const PRODUCTS_API = '/products.php';

async function parseSupplierResponse(response: Response) {
  const responseText = await response.text();

  try {
    return JSON.parse(responseText);
  } catch {
    throw new Error('Сервер вернул некорректный ответ');
  }
}

const statuses = [
  'Новый',
  'Принят',
  'В обработке',
  'Передан в доставку',
  'Выполнен',
  'Отменён',
];

const categories = ['OLED', 'QLED', 'LED', '8K'];

const countries = [
  'Россия',
  'Китай',
  'Южная Корея',
  'Европа',
];

function formatPrice(value: string | number) {
  return new Intl.NumberFormat('ru-RU').format(Number(value)) + ' ₽';
}

function formatDate(value: string) {
  return new Date(value).toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function getStatusClass(status: string) {
  switch (status) {
    case 'Новый':
      return 'bg-blue-50 text-blue-700 border-blue-200';
    case 'Принят':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    case 'В обработке':
      return 'bg-yellow-50 text-yellow-700 border-yellow-200';
    case 'Передан в доставку':
      return 'bg-purple-50 text-purple-700 border-purple-200';
    case 'Выполнен':
      return 'bg-green-50 text-green-700 border-green-200';
    case 'Отменён':
      return 'bg-red-50 text-red-700 border-red-200';
    default:
      return 'bg-gray-50 text-gray-600 border-gray-200';
  }
}

function getStatusIcon(status: string) {
  switch (status) {
    case 'Новый':
      return <Package className="w-4 h-4" />;
    case 'Принят':
      return <CheckCircle2 className="w-4 h-4" />;
    case 'В обработке':
      return <Clock3 className="w-4 h-4" />;
    case 'Передан в доставку':
      return <Truck className="w-4 h-4" />;
    case 'Выполнен':
      return <CheckCircle2 className="w-4 h-4" />;
    case 'Отменён':
      return <XCircle className="w-4 h-4" />;
    default:
      return <Package className="w-4 h-4" />;
  }
}

const emptyProductForm = {
  name: '',
  slug: '',
  series: '',
  country: '',
  category: 'OLED',
  screen_size: '',
  resolution: '',
  price: '',
  old_price: '',
  image: '',
  badge: '',
  rating: '0',
  reviews: '0',
  description: '',
  is_active: true,
};

const emptySupplierForm = {
  name: '',
  internal_code: '',
  is_active: true,
};

export default function AdminPage() {
  const [authenticated, setAuthenticated] = useState(false);
  const [csrfToken, setCsrfToken] = useState<string | null>(null);

  const [darkMode, setDarkMode] = useState(() => {
    const saved = localStorage.getItem('telvora-admin-theme');
    return saved !== 'light';
  });
    useEffect(() => {
    const root = document.documentElement;

    root.classList.toggle('admin-dark', darkMode);
    root.style.colorScheme = darkMode ? 'dark' : 'light';

    return () => {
      root.classList.remove('admin-dark');
    };
  }, [darkMode]);

  const [password, setPassword] = useState('');
  const [loginError, setLoginError] = useState('');
  const [loggingIn, setLoggingIn] = useState(false);

  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [filter, setFilter] = useState('Все');
  const [expanded, setExpanded] = useState<number | null>(null);

  const [activeTab, setActiveTab] = useState<
    'orders' | 'products' | 'suppliers'
  >(
    'orders'
  );

  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [suppliersLoading, setSuppliersLoading] = useState(false);
  const [suppliersError, setSuppliersError] = useState('');
  const [suppliersSuccess, setSuppliersSuccess] = useState('');
  const [showSupplierForm, setShowSupplierForm] = useState(false);
  const [editingSupplierId, setEditingSupplierId] = useState<number | null>(null);
  const [supplierForm, setSupplierForm] = useState(emptySupplierForm);
  const [savingSupplier, setSavingSupplier] = useState(false);
  const [togglingSupplierId, setTogglingSupplierId] = useState<number | null>(null);

  const [products, setProducts] = useState<AdminProduct[]>([]);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [imageUploading, setImageUploading] = useState(false);
  const [imageUploadError, setImageUploadError] = useState('');
  const [productsLoading, setProductsLoading] = useState(false);
  const [productsError, setProductsError] = useState('');
  const [productSearch, setProductSearch] = useState('');
  const [productCategoryFilter, setProductCategoryFilter] =
    useState('Все категории');
  const [productCountryFilter, setProductCountryFilter] =
    useState('Все страны');

  const [showProductForm, setShowProductForm] = useState(false);
  const [editingProductId, setEditingProductId] = useState<number | null>(
    null
  );
  const [productForm, setProductForm] = useState(emptyProductForm);

  const [specs, setSpecs] = useState<Spec[]>([]);
  const [highlights, setHighlights] = useState<string[]>([]);
  const [variants, setVariants] = useState<ProductVariant[]>([]);

  const [specName, setSpecName] = useState('');
  const [specValue, setSpecValue] = useState('');
  const [highlightValue, setHighlightValue] = useState('');

  const [savingProduct, setSavingProduct] = useState(false);
  const [deletingProductId, setDeletingProductId] = useState<number | null>(
    null
  );

  const [editingVariantsId, setEditingVariantsId] = useState<number | null>(
    null
  );
  const [savingVariants, setSavingVariants] = useState(false);

    const toggleTheme = () => {
    setDarkMode((current) => {
      const next = !current;
      localStorage.setItem(
        'telvora-admin-theme',
        next ? 'dark' : 'light'
      );
      return next;
    });
  };

const login = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!password.trim()) {
      setLoginError('Введите пароль');
      return;
    }

    setLoggingIn(true);
    setLoginError('');

    try {
      const response = await fetch(MANAGER_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'login',
          password,
        }),
      });

      const data = await response.json();

      if (!data.success) {
        setLoginError(data.message || 'Неверный пароль');
        return;
      }

      if (typeof data.csrf_token !== 'string' || !data.csrf_token) {
        setLoginError('Login verification failed');
        return;
      }

      setCsrfToken(data.csrf_token);
      setAuthenticated(true);
      setPassword('');
    } catch {
      setLoginError('Не удалось подключиться к серверу');
    } finally {
      setLoggingIn(false);
    }
  };

  const loadOrders = async () => {
    setLoading(true);
    setError('');

    try {
      const response = await fetch(`${MANAGER_API}?action=orders`, {
        method: 'GET',
        credentials: 'include',
      });

      const data = await response.json();

      if (!data.success) {
        if (response.status === 401) {
          setAuthenticated(false);
        }

        setError(data.message || 'Не удалось загрузить заказы');
        return;
      }

      setOrders(data.orders || []);
    } catch {
      setError('Не удалось подключиться к серверу');
    } finally {
      setLoading(false);
    }
  };

  const loadProducts = async () => {
    setProductsLoading(true);
    setProductsError('');

    try {
      const response = await fetch(`${PRODUCTS_API}?action=admin_list`, {
        method: 'GET',
        credentials: 'include',
        headers: {
          Accept: 'application/json',
        },
      });

      const data = await response.json();

      if (!data.success) {
        if (response.status === 401) {
          setAuthenticated(false);
        }

        throw new Error(data.message || 'Не удалось загрузить товары');
      }

      setProducts(data.products || []);
    } catch (err) {
      console.error(err);
      setProductsError('Не удалось загрузить товары');
    } finally {
      setProductsLoading(false);
    }
  };

  const loadSuppliers = async () => {
    setSuppliersLoading(true);
    setSuppliersError('');

    try {
      const response = await fetch(`${MANAGER_API}?action=suppliers_list`, {
        method: 'GET',
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
      const data = await parseSupplierResponse(response);

      if (!response.ok || !data.success) {
        if (response.status === 401 || response.status === 403) {
          setAuthenticated(false);
          setCsrfToken(null);
        }

        throw new Error(data.message || 'Не удалось загрузить поставщиков');
      }

      setSuppliers(Array.isArray(data.suppliers) ? data.suppliers : []);
    } catch (err) {
      setSuppliersError(
        err instanceof Error ? err.message : 'Не удалось загрузить поставщиков'
      );
    } finally {
      setSuppliersLoading(false);
    }
  };

  const logout = async () => {
    try {
      await fetch(MANAGER_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify({
          action: 'logout',
        }),
      });
    } finally {
      setAuthenticated(false);
      setCsrfToken(null);
      setOrders([]);
      setProducts([]);
      setSuppliers([]);
      setShowProductForm(false);
      setShowSupplierForm(false);
    }
  };

  const updateStatus = async (orderId: number, status: string) => {
    try {
      const response = await fetch(MANAGER_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify({
          action: 'update_status',
          order_id: orderId,
          status,
        }),
      });

      const data = await response.json();

      if (!data.success) {
        setError(data.message || 'Не удалось изменить статус');
        return;
      }

      setOrders((current) =>
        current.map((order) =>
          order.id === orderId ? { ...order, status } : order
        )
      );
    } catch {
      setError('Не удалось подключиться к серверу');
    }
  };

  useEffect(() => {
    if (authenticated) {
      loadOrders();
    }
  }, [authenticated]);
  useEffect(() => {
    if (authenticated && activeTab === 'products') {
      loadProducts();
    }
  }, [authenticated, activeTab]);
  useEffect(() => {
    if (authenticated && activeTab === 'suppliers') {
      loadSuppliers();
    }
  }, [authenticated, activeTab]);

  const openAddSupplier = () => {
    setEditingSupplierId(null);
    setSupplierForm(emptySupplierForm);
    setSuppliersError('');
    setSuppliersSuccess('');
    setShowSupplierForm(true);
  };

  const openEditSupplier = (supplier: Supplier) => {
    setEditingSupplierId(supplier.id);
    setSupplierForm({
      name: supplier.name,
      internal_code: supplier.internal_code,
      is_active: supplier.is_active,
    });
    setSuppliersError('');
    setSuppliersSuccess('');
    setShowSupplierForm(true);
  };

  const closeSupplierForm = () => {
    setShowSupplierForm(false);
    setEditingSupplierId(null);
    setSupplierForm(emptySupplierForm);
  };

  const saveSupplier = async (event: React.FormEvent) => {
    event.preventDefault();
    const name = supplierForm.name.trim();
    const internalCode = supplierForm.internal_code.trim();

    if (!name || !internalCode) {
      setSuppliersError('Заполните название и внутренний код');
      return;
    }

    setSavingSupplier(true);
    setSuppliersError('');
    setSuppliersSuccess('');

    try {
      const action = editingSupplierId
        ? 'supplier_update'
        : 'supplier_create';
      const response = await fetch(MANAGER_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify({
          action,
          ...(editingSupplierId ? { id: editingSupplierId } : {}),
          name,
          internal_code: internalCode,
          is_active: supplierForm.is_active,
        }),
      });
      const data = await parseSupplierResponse(response);

      if (!response.ok || !data.success) {
        if (response.status === 401 || response.status === 403) {
          setAuthenticated(false);
          setCsrfToken(null);
        }

        throw new Error(data.message || 'Не удалось сохранить поставщика');
      }

      closeSupplierForm();
      setSuppliersSuccess(data.message || 'Поставщик сохранён');
      await loadSuppliers();
    } catch (err) {
      setSuppliersError(
        err instanceof Error ? err.message : 'Не удалось сохранить поставщика'
      );
    } finally {
      setSavingSupplier(false);
    }
  };

  const setSupplierActive = async (supplier: Supplier) => {
    const nextActive = !supplier.is_active;

    if (
      !nextActive &&
      !window.confirm(`Отключить поставщика «${supplier.name}»?`)
    ) {
      return;
    }

    setTogglingSupplierId(supplier.id);
    setSuppliersError('');
    setSuppliersSuccess('');

    try {
      const response = await fetch(MANAGER_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify({
          action: 'supplier_set_active',
          id: supplier.id,
          is_active: nextActive,
        }),
      });
      const data = await parseSupplierResponse(response);

      if (!response.ok || !data.success) {
        if (response.status === 401 || response.status === 403) {
          setAuthenticated(false);
          setCsrfToken(null);
        }

        throw new Error(data.message || 'Не удалось изменить статус поставщика');
      }

      setSuppliersSuccess(data.message || 'Статус поставщика изменён');
      await loadSuppliers();
    } catch (err) {
      setSuppliersError(
        err instanceof Error
          ? err.message
          : 'Не удалось изменить статус поставщика'
      );
    } finally {
      setTogglingSupplierId(null);
    }
  };

  const filteredOrders = useMemo(() => {
    if (filter === 'Все') {
      return orders;
    }

    return orders.filter((order) => order.status === filter);
  }, [orders, filter]);

  const filteredProducts = useMemo(() => {
    const query = productSearch.trim().toLowerCase();

    return products.filter((product) => {
      const matchesSearch =
        !query ||
        product.name.toLowerCase().includes(query) ||
        product.series.toLowerCase().includes(query) ||
        product.category.toLowerCase().includes(query) ||
        product.screen_size.toLowerCase().includes(query) ||
        (product.country || '').toLowerCase().includes(query);

      const matchesCategory =
        productCategoryFilter === 'Все категории' ||
        product.category === productCategoryFilter;

      const matchesCountry =
        productCountryFilter === 'Все страны' ||
        product.country === productCountryFilter;

      return matchesSearch && matchesCategory && matchesCountry;
    });
  }, [
    products,
    productSearch,
    productCategoryFilter,
    productCountryFilter,
  ]);

  const totalSum = orders
    .filter((order) => order.status === 'Выполнен')
    .reduce(
      (sum, order) => sum + Number(order.total),
      0
    );

  const newOrders = orders.filter(
    (order) => order.status === 'Новый'
  ).length;

    const uploadProductImage = async () => {
    if (!imageFile) {
      setImageUploadError('Сначала выберите изображение');
      return;
    }

    setImageUploading(true);
    setImageUploadError('');

    try {
      const formData = new FormData();
      formData.append('action', 'upload_image');
      formData.append('image', imageFile);
console.log('UPLOAD IMAGE:', imageFile.name, imageFile.size, imageFile.type);

const response = await fetch(PRODUCTS_API, {
  method: 'POST',
  credentials: 'include',
  headers: {
    'X-CSRF-Token': csrfToken || '',
  },
  body: formData,
});

const data = await response.json();

console.log('UPLOAD RESPONSE:', response.status, response.ok);
console.log('UPLOAD SUCCESS:', data.success);
console.log('UPLOAD IMAGE URL:', data.image);
if (!response.ok || !data.success || !data.image) {
        setImageUploadError(
          data.message || 'Не удалось загрузить изображение'
        );
        return;
      }

      setProductForm((current) => ({
        ...current,
        image: data.image,
      }));

      setImageFile(null);
    } catch {
      setImageUploadError(
        'Не удалось подключиться к серверу'
      );
    } finally {
      setImageUploading(false);
    }
  };
const resetProductForm = () => {
    setProductForm(emptyProductForm);
    setSpecs([]);
    setHighlights([]);
    setVariants([]);
    setSpecName('');
    setSpecValue('');
    setHighlightValue('');
    setEditingProductId(null);
  };

  const openAddProduct = () => {
    resetProductForm();
    setShowProductForm(true);
  };

  const openEditProduct = (product: AdminProduct) => {
    setProductForm({
      name: product.name || '',
      slug: product.slug || '',
      series: product.series || '',
      country: product.country || '',
      category: product.category || 'OLED',
      screen_size: product.screen_size || '',
      resolution: product.resolution || '',
      price: String(product.price ?? ''),
      old_price:
        product.old_price !== null
          ? String(product.old_price)
          : '',
      image: product.image || '',
      badge: product.badge || '',
      rating: String(product.rating ?? 0),
      reviews: String(product.reviews ?? 0),
      description: product.description || '',
      is_active: Boolean(product.is_active),
    });

    setSpecs(
      Array.isArray(product.specs)
        ? product.specs.map((item) => ({
            label: String(item.label || ''),
            value: String(item.value || ''),
          }))
        : []
    );

    setHighlights(
      Array.isArray(product.highlights)
        ? product.highlights.map((item) => String(item))
        : []
    );

    setVariants(
      Array.isArray(product.variants)
        ? product.variants.map((variant) => ({
            country: String(variant.country || ''),
            price: String(variant.price ?? ''),
            old_price: String(variant.old_price ?? ''),
            is_active:
              variant.is_active === undefined
                ? true
                : Boolean(variant.is_active),
          }))
        : []
    );

    setEditingProductId(product.id);
    setShowProductForm(true);
  };

  const addSpec = () => {
    const label = specName.trim();
    const value = specValue.trim();

    if (!label || !value) {
      return;
    }

    setSpecs((current) => [...current, { label, value }]);
    setSpecName('');
    setSpecValue('');
  };

  const removeSpec = (index: number) => {
    setSpecs((current) =>
      current.filter((_, itemIndex) => itemIndex !== index)
    );
  };

  const addHighlight = () => {
    const value = highlightValue.trim();

    if (!value) {
      return;
    }

    setHighlights((current) => [...current, value]);
    setHighlightValue('');
  };

  const removeHighlight = (index: number) => {
    setHighlights((current) =>
      current.filter((_, itemIndex) => itemIndex !== index)
    );
  };

  const addVariantToForm = () => {
    setVariants((current) => [
      ...current,
      {
        country: '',
        price: '',
        old_price: '',
        is_active: true,
      },
    ]);
  };

  const updateFormVariant = (
    index: number,
    field: 'country' | 'price' | 'old_price',
    value: string
  ) => {
    setVariants((current) =>
      current.map((variant, variantIndex) =>
        variantIndex === index
          ? {
              ...variant,
              [field]: value,
            }
          : variant
      )
    );
  };

  const removeFormVariant = (index: number) => {
    setVariants((current) =>
      current.filter((_, variantIndex) => variantIndex !== index)
    );
  };

  const saveProduct = async () => {
    if (!productForm.name.trim()) {
      setError('Введите название товара');
      return;
    }

    if (!productForm.slug.trim()) {
      setError('Введите slug товара');
      return;
    }

    if (!productForm.category.trim()) {
      setError('Выберите категорию товара');
      return;
    }

    const normalizedVariants = variants
      .map((variant) => ({
        country: String(variant.country || '').trim(),
        price: Number(variant.price || 0),
        old_price: variant.old_price
          ? Number(variant.old_price)
          : null,
        is_active:
          variant.is_active === undefined
            ? true
            : Boolean(variant.is_active),
      }))
      .filter((variant) => variant.country || variant.price > 0);

    if (normalizedVariants.length === 0) {
      setError('Добавьте хотя бы одну сборку и укажите её цену');
      return;
    }

    if (
      normalizedVariants.some(
        (variant) =>
          !variant.country ||
          !variant.price ||
          variant.price <= 0
      )
    ) {
      setError('Для каждой сборки укажите страну и корректную цену');
      return;
    }

    const cheapestVariant = [...normalizedVariants]
      .sort((a, b) => a.price - b.price)[0];

    const basePrice = cheapestVariant.price;

    const baseOldPrice =
      cheapestVariant.old_price &&
      cheapestVariant.old_price > basePrice
        ? cheapestVariant.old_price
        : null;

    setSavingProduct(true);
    setError('');
    setProductsError('');

    try {
      const payload: Record<string, unknown> = {
        action: editingProductId ? 'update' : 'add',
        name: productForm.name.trim(),
        slug: productForm.slug.trim(),
        series: productForm.series.trim(),
        country: productForm.country.trim(),
        category: productForm.category,
        screen_size: productForm.screen_size.trim(),
        resolution: productForm.resolution.trim(),
        price: Number(productForm.price),
        old_price:
          productForm.old_price.trim() !== ''
            ? Number(productForm.old_price)
            : null,
        image: productForm.image.trim(),
        badge: productForm.badge.trim(),
        rating: Number(productForm.rating || 0),
        reviews: Number(productForm.reviews || 0),
        description: productForm.description.trim(),
        specs,
        highlights,
        variants: variants.map((variant) => ({
          country: String(variant.country || '').trim(),
          price: Number(variant.price || 0),
          old_price: variant.old_price
            ? Number(variant.old_price)
            : null,
          is_active:
            variant.is_active === undefined
              ? true
              : Boolean(variant.is_active),
        })),
        is_active: productForm.is_active,
      };

      if (editingProductId) {
        payload.id = editingProductId;
      }

      const response = await fetch(PRODUCTS_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify(payload),
      });

      const data = await response.json();

      if (!data.success) {
        setError(data.message || 'Не удалось сохранить товар');
        return;
      }

      setShowProductForm(false);
      resetProductForm();
      await loadProducts();
    } catch {
      setError('Не удалось подключиться к серверу');
    } finally {
      setSavingProduct(false);
    }
  };
const toggleProductStatus = async (product: AdminProduct) => {
  const newStatus = !product.is_active;

  try {
    const response = await fetch(PRODUCTS_API, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken || '',
      },
      body: JSON.stringify({
        action: 'update',
        id: product.id,
        is_active: newStatus,
      }),
    });

    const data = await response.json();

    if (!data.success) {
      setError(
        data.message || 'Не удалось изменить статус товара'
      );
      return;
    }

    setProducts((current) =>
      current.map((item) =>
        item.id === product.id
          ? { ...item, is_active: newStatus }
          : item
      )
    );
  } catch {
    setError('Не удалось подключиться к серверу');
  }
};
  const deleteProduct = async (product: AdminProduct) => {
    const confirmed = window.confirm(
      `Удалить товар «${product.name}»?`
    );

    if (!confirmed) {
      return;
    }

    setDeletingProductId(product.id);
    setError('');

    try {
      const response = await fetch(PRODUCTS_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify({
          action: 'delete',
          id: product.id,
        }),
      });

      const data = await response.json();

      if (!data.success) {
        setError(data.message || 'Не удалось удалить товар');
        return;
      }

      setProducts((current) =>
        current.filter((item) => item.id !== product.id)
      );

      if (editingVariantsId === product.id) {
        setEditingVariantsId(null);
      }
    } catch {
      setError('Не удалось подключиться к серверу');
    } finally {
      setDeletingProductId(null);
    }
  };

  const startVariantsEdit = (product: AdminProduct) => {
    setEditingVariantsId(product.id);

    setProducts((current) =>
      current.map((item) =>
        item.id === product.id
          ? {
              ...item,
              variants: (item.variants || []).map((variant) => ({
                country: String(variant.country || ''),
                price: String(variant.price ?? ''),
                old_price: String(variant.old_price ?? ''),
                is_active:
                  variant.is_active === undefined
                    ? true
                    : Boolean(variant.is_active),
              })),
            }
          : item
      )
    );
  };

  const cancelVariantsEdit = () => {
    setEditingVariantsId(null);
    loadProducts();
  };

  const updateVariant = (
    productId: number,
    index: number,
    field: 'country' | 'price' | 'old_price',
    value: string
  ) => {
    setProducts((current) =>
      current.map((product) =>
        product.id === productId
          ? {
              ...product,
              variants: (product.variants || []).map(
                (variant, variantIndex) =>
                  variantIndex === index
                    ? {
                        ...variant,
                        [field]: value,
                      }
                    : variant
              ),
            }
          : product
      )
    );
  };

  const updateVariantStatus = (
    productId: number,
    index: number,
    is_active: boolean
  ) => {
    setProducts((current) =>
      current.map((product) =>
        product.id === productId
          ? {
              ...product,
              variants: (product.variants || []).map(
                (variant, variantIndex) =>
                  variantIndex === index
                    ? {
                        ...variant,
                        is_active,
                      }
                    : variant
              ),
            }
          : product
      )
    );
  };

  const addVariant = (productId: number) => {
    setProducts((current) =>
      current.map((product) =>
        product.id === productId
          ? {
              ...product,
              variants: [
                ...(product.variants || []),
                {
                  country: '',
                  price: '',
                  old_price: '',
                  is_active: true,
                },
              ],
            }
          : product
      )
    );
  };

  const removeVariant = (
    productId: number,
    index: number
  ) => {
    setProducts((current) =>
      current.map((product) =>
        product.id === productId
          ? {
              ...product,
              variants: (product.variants || []).filter(
                (_, variantIndex) => variantIndex !== index
              ),
            }
          : product
      )
    );
  };

  const saveVariants = async (product: AdminProduct) => {
    const normalizedVariants = (product.variants || []).map(
      (variant) => ({
        country: String(variant.country || '').trim(),
        price: Number(variant.price || 0),
        old_price: variant.old_price
          ? Number(variant.old_price)
          : null,
        is_active:
          variant.is_active === undefined
            ? true
            : Boolean(variant.is_active),
      })
    );

    if (
      normalizedVariants.some(
        (variant) =>
          !variant.country ||
          !variant.price ||
          variant.price <= 0
      )
    ) {
      setError(
        'Для каждого варианта укажите страну и корректную цену'
      );
      return;
    }

    setSavingVariants(true);
    setError('');

    try {
      const response = await fetch(PRODUCTS_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify({
          action: 'update',
          id: product.id,
          variants: normalizedVariants,
        }),
      });

      const data = await response.json();

      if (!data.success) {
        setError(
          data.message || 'Не удалось сохранить варианты'
        );
        return;
      }

      setProducts((current) =>
        current.map((item) =>
          item.id === product.id
            ? {
                ...item,
                variants: normalizedVariants.map((variant) => ({
                  country: variant.country,
                  price: String(variant.price),
                  old_price:
                    variant.old_price === null
                      ? ''
                      : String(variant.old_price),
                  is_active: variant.is_active,
                })),
              }
            : item
        )
      );

      setEditingVariantsId(null);
    } catch {
      setError('Не удалось подключиться к серверу');
    } finally {
      setSavingVariants(false);
    }
  };

  if (!authenticated) {
    return (
      <div className="min-h-[calc(100vh-120px)] bg-white flex items-center justify-center px-4">
        <div className="w-full max-w-md">
          <div className="bg-white border border-gray-200 rounded-3xl p-8 shadow-xl">
            <div className="text-center mb-8">
              <div className="text-3xl font-display font-bold text-graphite-900">
                TELVORA
              </div>

              <h1 className="text-xl font-display font-semibold text-graphite-900 mt-6">
                Панель администратора
              </h1>

              <p className="text-sm text-gray-500 mt-2">
                Введите пароль для доступа к заказам
              </p>
            </div>

            <form onSubmit={login} className="space-y-5">
              <div>
                <label className="block text-sm text-gray-700 mb-2">
                  Пароль
                </label>

                <input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Введите пароль"
                  autoFocus
                  className="w-full px-4 py-3 rounded-xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-400 outline-none focus:border-accent-500 focus:ring-2 focus:ring-accent-500/10"
                />
              </div>

              {loginError && (
                <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                  {loginError}
                </div>
              )}

              <button
                type="submit"
                disabled={loggingIn}
                className="w-full py-3 rounded-xl bg-accent-500 text-white font-semibold hover:bg-accent-600 transition disabled:opacity-50"
              >
                {loggingIn ? 'Вход...' : 'Войти'}
              </button>
            </form>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div
  className={
    darkMode
      ? 'admin-page min-h-full bg-graphite-900 text-white'
      : 'admin-page min-h-full bg-gray-50 text-graphite-900'
  }
>
      <div className="max-w-7xl mx-auto w-full px-4 py-8">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
          <div>
            <div className="text-2xl font-display font-bold text-graphite-900">
              TELVORA
            </div>

            <h1 className="text-2xl font-display font-semibold text-graphite-900 mt-2">
              Панель администратора
            </h1>

            <p className="text-sm text-gray-500 mt-1">
              Управление магазином
            </p>
          </div>

          <div className="flex gap-3">
<button
  type="button"
  onClick={toggleTheme}
  className={`flex items-center justify-center w-11 h-11 rounded-xl border transition shadow-sm ${
  darkMode
    ? 'bg-[#222] border-white/20 text-white hover:bg-[#333]'
    : 'bg-white border-gray-200 text-gray-700 hover:bg-gray-50'
}`}
  title={darkMode ? 'Светлая тема' : 'Тёмная тема'}
>
  {darkMode ? (
    <Sun className="w-5 h-5" />
  ) : (
    <Moon className="w-5 h-5" />
  )}
</button>
            <button
              onClick={() => {
                if (activeTab === 'orders') {
                  loadOrders();
                } else if (activeTab === 'products') {
                  loadProducts();
                } else {
                  loadSuppliers();
                }
              }}
              disabled={loading || productsLoading || suppliersLoading}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition shadow-sm"
            >
              <RefreshCw
                className={`w-4 h-4 ${
                  loading || productsLoading || suppliersLoading
                    ? 'animate-spin'
                    : ''
                }`}
              />

              Обновить
            </button>

            <button
              onClick={logout}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 transition shadow-sm"
            >
              <LogOut className="w-4 h-4" />
              Выйти
            </button>
          </div>
        </div>

        {error && (
          <div className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
            {error}
          </div>
        )}

        <div className="flex gap-2 mb-8 border-b border-gray-200 pb-3">
          <button
            onClick={() => setActiveTab('orders')}
            className={`px-5 py-2.5 rounded-xl text-sm font-medium border transition ${
              activeTab === 'orders'
                ? 'bg-accent-50 border-accent-200 text-accent-600'
                : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'
            }`}
          >
            Заказы
          </button>

          <button
            onClick={() => setActiveTab('products')}
            className={`px-5 py-2.5 rounded-xl text-sm font-medium border transition ${
              activeTab === 'products'
                ? 'bg-accent-50 border-accent-200 text-accent-600'
                : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'
            }`}
          >
            Товары
          </button>

          <button
            onClick={() => setActiveTab('suppliers')}
            className={`px-5 py-2.5 rounded-xl text-sm font-medium border transition ${
              activeTab === 'suppliers'
                ? 'bg-accent-50 border-accent-200 text-accent-600'
                : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'
            }`}
          >
            Поставщики
          </button>
        </div>

        {activeTab === 'orders' ? (
          <>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
              <div className="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
                <div className="text-sm text-gray-500">
                  Всего заказов
                </div>

                <div className="text-3xl font-bold text-graphite-900 mt-2">
                  {orders.length}
                </div>
              </div>

              <div className="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
                <div className="text-sm text-gray-500">
                  Новых
                </div>

                <div className="text-3xl font-bold text-graphite-900 mt-2">
                  {newOrders}
                </div>
              </div>

              <div className="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
                <div className="text-sm text-gray-500">
                  Сумма заказов
                </div>

                <div className="text-3xl font-bold text-graphite-900 mt-2">
                  {formatPrice(totalSum)}
                </div>
              </div>
            </div>

            <div className="flex flex-wrap gap-2 mb-6">
              {['Все', ...statuses].map((status) => (
                <button
                  key={status}
                  onClick={() => setFilter(status)}
                  className={`px-4 py-2 rounded-xl text-sm border transition ${
                    filter === status
                      ? 'bg-accent-50 border-accent-200 text-accent-600'
                      : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'
                  }`}
                >
                  {status}
                </button>
              ))}
            </div>

            {loading ? (
              <div className="text-center py-16 text-gray-500">
                Загрузка заказов...
              </div>
            ) : filteredOrders.length === 0 ? (
              <div className="bg-white border border-gray-200 rounded-2xl p-12 text-center shadow-sm">
                <Package className="w-10 h-10 mx-auto text-gray-400" />

                <div className="text-graphite-900 font-semibold mt-4">
                  Заказов пока нет
                </div>

                <div className="text-sm text-gray-500 mt-2">
                  Здесь будут отображаться новые заказы.
                </div>
              </div>
            ) : (
              <div className="space-y-4">
                {filteredOrders.map((order) => (
                  <div
                    key={order.id}
                    className="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm"
                  >
                    <button
                      onClick={() =>
                        setExpanded(
                          expanded === order.id
                            ? null
                            : order.id
                        )
                      }
                      className="w-full p-5 text-left"
                    >
                      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div>
                          <div className="flex items-center gap-3">
                            <span className="text-graphite-900 font-semibold">
                              Заказ #{order.id}
                            </span>

                            <span
                              className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs ${getStatusClass(
                                order.status
                              )}`}
                            >
                              {getStatusIcon(order.status)}
                              {order.status}
                            </span>
                          </div>

                          <div className="text-sm text-gray-500 mt-2">
                            {formatDate(order.created_at)}
                          </div>
                        </div>

                        <div className="flex items-center gap-6">
                          <div>
                            <div className="text-sm text-gray-500">
                              Покупатель
                            </div>

                            <div className="text-graphite-900 font-medium mt-1">
                              {order.customer_name}
                            </div>
                          </div>

                          <div>
                            <div className="text-sm text-gray-500">
                              Сумма
                            </div>

                            <div className="text-graphite-900 font-semibold mt-1">
                              {formatPrice(order.total)}
                            </div>
                          </div>

                          <ChevronDown className="w-5 h-5 text-gray-400" />
                        </div>
                      </div>
                    </button>

                    {expanded === order.id && (
                      <div className="border-t border-gray-200 p-5 space-y-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                          <div>
                            <div className="text-xs uppercase text-gray-400">
                              Телефон
                            </div>

                            <div className="text-graphite-900 mt-1">
                              {order.phone}
                            </div>
                          </div>

                          <div>
                            <div className="text-xs uppercase text-gray-400">
                              Email
                            </div>

                            <div className="text-graphite-900 mt-1">
                              {order.email || '—'}
                            </div>
                          </div>

                          <div>
                            <div className="text-xs uppercase text-gray-400">
                              Адрес
                            </div>

                            <div className="text-graphite-900 mt-1">
                              {order.address || '—'}
                            </div>
                          </div>

                          <div>
                            <div className="text-xs uppercase text-gray-400">
                              Доставка
                            </div>

                            <div className="text-graphite-900 mt-1">
                              {order.delivery_method}
                            </div>
                          </div>

                          <div>
                            <div className="text-xs uppercase text-gray-400">
                              Оплата
                            </div>

                            <div className="text-graphite-900 mt-1">
                              {order.payment_method === 'cash'
                                ? 'Наличными'
                                : order.payment_method}
                            </div>
                          </div>

                          <div>
                            <div className="text-xs uppercase text-gray-400">
                              Комментарий
                            </div>

                            <div className="text-graphite-900 mt-1">
                              {order.comment || '—'}
                            </div>
                          </div>
                        </div>

                        <div>
                          <div className="text-sm font-semibold text-graphite-900 mb-3">
                            Состав заказа
                          </div>

                          <div className="space-y-2">
                            {order.items.map((item, index) => (
                              <div
                                key={`${order.id}-${index}`}
                                className="flex items-center justify-between gap-4 bg-gray-50 border border-gray-200 rounded-xl p-3"
                              >
                                <div>
                                  <div className="text-graphite-900">
                                    {item.product_name}
                                  </div>

                                  <div className="text-xs text-gray-500 mt-1">
                                    {item.quantity} шт. ×{' '}
                                    {formatPrice(item.price)}
                                  </div>
                                </div>

                                <div className="text-graphite-900 font-semibold">
                                  {formatPrice(
                                    Number(item.price) *
                                      item.quantity
                                  )}
                                </div>
                              </div>
                            ))}
                          </div>
                        </div>

                        <div>
                          <div className="text-sm font-semibold text-graphite-900 mb-3">
                            Изменить статус
                          </div>

                          <select
                            value={order.status}
                            onChange={(e) =>
                              updateStatus(
                                order.id,
                                e.target.value
                              )
                            }
                            className="w-full md:w-auto min-w-[240px] px-4 py-3 rounded-xl bg-white border border-gray-300 text-graphite-900 outline-none focus:border-accent-500"
                          >
                            {statuses.map((status) => (
                              <option
                                key={status}
                                value={status}
                              >
                                {status}
                              </option>
                            ))}
                          </select>
                        </div>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </>
        ) : activeTab === 'products' ? (
          <>
            <div className="flex flex-col gap-4 mb-6">
              <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                  <h2 className="text-xl font-display font-semibold text-graphite-900">
                    Товары
                  </h2>

                  <p className="text-sm text-gray-500 mt-1">
                    Управление товарами магазина
                  </p>
                </div>

                <button
                  type="button"
                  onClick={openAddProduct}
                  className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-accent-500 text-white font-semibold hover:bg-accent-600 transition"
                >
                  <Plus className="w-4 h-4" />
                  Добавить товар
                </button>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-[1fr_180px_180px] gap-3">
                <div className="relative">
                  <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />

                  <input
                    type="text"
                    value={productSearch}
                    onChange={(e) =>
                      setProductSearch(e.target.value)
                    }
                    placeholder="Поиск товара..."
                    className="w-full pl-10 pr-4 py-3 rounded-xl bg-white border border-gray-300 text-gray-900 placeholder:text-gray-400 outline-none focus:border-accent-500"
                  />
                </div>

                <select
                  value={productCategoryFilter}
                  onChange={(e) =>
                    setProductCategoryFilter(e.target.value)
                  }
                  className="px-4 py-3 rounded-xl bg-white border border-gray-300 text-gray-900 outline-none focus:border-accent-500"
                >
                  <option value="Все категории">
                    Все категории
                  </option>

                  {categories.map((category) => (
                    <option
                      key={category}
                      value={category}
                    >
                      {category}
                    </option>
                  ))}
                </select>

                <select
                  value={productCountryFilter}
                  onChange={(e) =>
                    setProductCountryFilter(e.target.value)
                  }
                  className="px-4 py-3 rounded-xl bg-white border border-gray-300 text-gray-900 outline-none focus:border-accent-500"
                >
                  <option value="Все страны">
                    Все страны
                  </option>

                  {countries.map((country) => (
                    <option
                      key={country}
                      value={country}
                    >
                      {country}
                    </option>
                  ))}
                </select>
              </div>

              <div className="text-sm text-gray-500">
                Найдено товаров: {filteredProducts.length}
              </div>
            </div>

            {productsError && (
              <div className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                {productsError}
              </div>
            )}

            {productsLoading ? (
              <div className="text-center py-16 text-gray-500">
                Загрузка товаров...
              </div>
            ) : filteredProducts.length === 0 ? (
              <div className="bg-white border border-gray-200 rounded-2xl p-12 text-center shadow-sm">
                <Package className="w-10 h-10 mx-auto text-gray-400" />

                <div className="text-graphite-900 font-semibold mt-4">
                  Товары не найдены
                </div>

                <div className="text-sm text-gray-500 mt-2">
                  Добавьте товар или измените параметры поиска.
                </div>
              </div>
            ) : (
              <div className="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[1250px] table-fixed">
                    <thead>
                      <tr className="border-b border-gray-200 bg-gray-50">
                        <th className="w-[34%] text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">
  Товар
</th>

                        <th className="w-[10%] text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">
  Категория
</th>

                        <th className="w-[10%] text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">
  Страна
</th>

                        <th className="w-[12%] text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">
  Цена
</th>

                        <th className="w-[10%] text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">
  Рейтинг
</th>

                        <th className="w-[11%] text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">
  Статус
</th>
                        <th className="w-[13%] text-right px-5 py-4 text-xs font-semibold uppercase text-gray-500">
  Действия
</th>
                      </tr>
                    </thead>

                    <tbody>
                      {filteredProducts.map((product) => (
                        <Fragment key={product.id}>
                          <tr className="border-b border-gray-100">
                            <td className="px-5 py-4">
                              <div className="flex items-center gap-4">
                                <div className="w-20 h-16 rounded-xl bg-gray-50 border border-gray-200 overflow-hidden flex items-center justify-center shrink-0">
                                  {product.image ? (
                                    <img
                                      src={product.image}
                                      alt={product.name}
                                      className="w-full h-full object-contain p-2"
                                    />
                                  ) : (
                                    <Package className="w-7 h-7 text-gray-300" />
                                  )}
                                </div>

                                <div>
                                  <div className="text-sm font-semibold text-graphite-900">
                                    {product.name}
                                  </div>

                                  <div className="text-xs text-gray-500 mt-1">
                                    {product.series || 'Без серии'}
                                  </div>

                                  <div className="text-xs text-gray-400 mt-1">
                                    {product.screen_size || '—'} ·{' '}
                                    {product.resolution || '—'}
                                  </div>
                                </div>
                              </div>
                            </td>

                            <td className="px-5 py-4">
                              <span className="inline-flex px-2.5 py-1 rounded-lg bg-accent-50 border border-accent-200 text-xs text-accent-600">
                                {product.category}
                              </span>
                            </td>

                            <td className="px-5 py-4 text-sm text-gray-600">
                              {product.country || '—'}
                            </td>

                            <td className="px-5 py-4">
                              <div className="text-sm font-semibold text-graphite-900">
                                {formatPrice(product.price)}
                              </div>

                              {product.old_price !== null && (
                                <div className="text-xs text-gray-400 line-through mt-1">
                                  {formatPrice(product.old_price)}
                                </div>
                              )}
                            </td>

                            <td className="px-5 py-4">
                              <div className="flex items-center gap-1 text-sm text-graphite-900">
                                <span>★</span>
                                {product.rating}
                              </div>

                              <div className="text-xs text-gray-400 mt-1">
                                {product.reviews} отзывов
                              </div>
                            </td>

                            <td className="px-5 py-4">
  <button
    type="button"
    onClick={() => toggleProductStatus(product)}
    className={`inline-flex px-2.5 py-1 rounded-full border text-xs transition ${
      product.is_active
        ? 'bg-green-50 text-green-700 border-green-200 hover:bg-green-100'
        : 'bg-red-50 text-red-700 border-red-200 hover:bg-red-100'
    }`}
  >
    {product.is_active ? 'Активен' : 'Скрыт'}
  </button>
</td>
                            <td className="px-5 py-4">
                              <div className="flex items-center justify-end gap-2">
                                <button
                                  type="button"
                                  onClick={() =>
                                    startVariantsEdit(product)
                                  }
                                  className="p-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition"
                                  title="Варианты"
                                >
                                  <Package className="w-4 h-4" />
                                </button>

                                <button
                                  type="button"
                                  onClick={() =>
                                    openEditProduct(product)
                                  }
                                  className="p-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition"
                                  title="Редактировать"
                                >
                                  <Pencil className="w-4 h-4" />
                                </button>

                                <button
                                  type="button"
                                  onClick={() =>
                                    deleteProduct(product)
                                  }
                                  disabled={
                                    deletingProductId ===
                                    product.id
                                  }
                                  className="p-2 rounded-lg border border-red-200 text-red-500 hover:bg-red-50 transition disabled:opacity-50"
                                  title="Удалить"
                                >
                                  <Trash2 className="w-4 h-4" />
                                </button>
                              </div>
                            </td>
                          </tr>

                          {editingVariantsId === product.id && (
                            <tr className="border-b border-gray-200 bg-gray-50">
                              <td
                                colSpan={7}
                                className="px-5 py-5"
                              >
                                <div className="rounded-2xl border border-accent-200 bg-white p-5">
                                  <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-5">
                                    <div>
                                      <div className="text-base font-semibold text-graphite-900">
                                        Варианты товара
                                      </div>

                                      <div className="text-sm text-gray-500 mt-1">
                                        Можно задавать отдельные цены по странам.
                                      </div>
                                    </div>

                                    <button
                                      type="button"
                                      onClick={() =>
                                        addVariant(product.id)
                                      }
                                      className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-accent-500 text-white hover:bg-accent-600 transition"
                                    >
                                      <Plus className="w-4 h-4" />
                                      Добавить вариант
                                    </button>
                                  </div>

                                  <div className="space-y-3">
                                    {(product.variants || []).length === 0 ? (
                                      <div className="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                                        Вариантов пока нет.
                                      </div>
                                    ) : (
                                      (product.variants || []).map(
                                        (variant, index) => (
                                          <div
                                            key={`${product.id}-${index}`}
                                            className="grid grid-cols-1 md:grid-cols-[1.2fr_1fr_1fr_1fr_auto] gap-3 items-end rounded-xl border border-gray-200 bg-white p-4"
                                          >
                                            <div>
                                              <label className="block text-xs text-gray-500 mb-1.5">
                                                Страна
                                              </label>
<input
  type="text"
  value={variant.country || ''}
  onChange={(e) =>
    updateVariant(
      product.id,
      index,
      'country',
      e.target.value
    )
  }
  className="w-full px-3 py-2.5 rounded-lg bg-white border border-gray-300 text-gray-900 outline-none focus:border-accent-500"
  placeholder="Например: США"
/>
                                            </div>

                                            <div>
                                              <label className="block text-xs text-gray-500 mb-1.5">
                                                Цена
                                              </label>

                                              <input
                                                type="number"
                                                value={
                                                  variant.price
                                                }
                                                onChange={(e) =>
                                                  updateVariant(
                                                    product.id,
                                                    index,
                                                    'price',
                                                    e.target.value
                                                  )
                                                }
                                                className="w-full px-3 py-2.5 rounded-lg bg-white border border-gray-300 text-gray-900 outline-none focus:border-accent-500"
                                              />
                                            </div>

                                            <div>
                                              <label className="block text-xs text-gray-500 mb-1.5">
                                                Старая цена
                                              </label>

                                              <input
                                                type="number"
                                                value={
                                                  variant.old_price
                                                }
                                                onChange={(e) =>
                                                  updateVariant(
                                                    product.id,
                                                    index,
                                                    'old_price',
                                                    e.target.value
                                                  )
                                                }
                                                className="w-full px-3 py-2.5 rounded-lg bg-white border border-gray-300 text-gray-900 outline-none focus:border-accent-500"
                                              />
                                            </div>

                                            <div>
                                              <label className="block text-xs text-gray-500 mb-1.5">
                                                Статус
                                              </label>

                                              <button
                                                type="button"
                                                onClick={() =>
                                                  updateVariantStatus(
                                                    product.id,
                                                    index,
                                                    !Boolean(
                                                      variant.is_active
                                                    )
                                                  )
                                                }
                                                className={`w-full px-3 py-2.5 rounded-lg border text-sm transition ${
                                                  variant.is_active
                                                    ? 'bg-green-50 text-green-700 border-green-200 hover:bg-green-100'
                                                    : 'bg-red-50 text-red-700 border-red-200 hover:bg-red-100'
                                                }`}
                                              >
                                                {variant.is_active
                                                  ? 'Активен'
                                                  : 'Скрыт'}
                                              </button>
                                            </div>

                                            <button
                                              type="button"
                                              onClick={() =>
                                                removeVariant(
                                                  product.id,
                                                  index
                                                )
                                              }
                                              className="px-4 py-2.5 rounded-lg border border-red-200 text-red-500 hover:bg-red-50 transition"
                                            >
                                              <Trash2 className="w-4 h-4" />
                                            </button>
                                          </div>
                                        )
                                      )
                                    )}
                                  </div>

                                  <div className="flex flex-wrap justify-end gap-3 mt-5">
                                    <button
                                      type="button"
                                      onClick={cancelVariantsEdit}
                                      className="px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 transition"
                                    >
                                      Отмена
                                    </button>

                                    <button
                                      type="button"
                                      onClick={() =>
                                        saveVariants(product)
                                      }
                                      disabled={savingVariants}
                                      className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-green-600 text-white hover:bg-green-700 transition disabled:opacity-50"
                                    >
                                      <Save className="w-4 h-4" />

                                      {savingVariants
                                        ? 'Сохранение...'
                                        : 'Сохранить варианты'}
                                    </button>
                                  </div>
                                </div>
                              </td>
                            </tr>
                          )}
                        </Fragment>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </>
        ) : (
          <>
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
              <div>
                <h2 className="text-xl font-display font-semibold text-graphite-900">
                  Поставщики
                </h2>
                <p className="text-sm text-gray-500 mt-1">
                  Управление поставщиками и их доступностью
                </p>
              </div>
              <button
                type="button"
                onClick={openAddSupplier}
                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-accent-500 text-white font-semibold hover:bg-accent-600 transition"
              >
                <Plus className="w-4 h-4" />
                Добавить поставщика
              </button>
            </div>

            {suppliersError && (
              <div className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                {suppliersError}
              </div>
            )}
            {suppliersSuccess && (
              <div className="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                {suppliersSuccess}
              </div>
            )}

            {showSupplierForm && (
              <form
                onSubmit={saveSupplier}
                className="mb-6 bg-white border border-gray-200 rounded-2xl p-6 shadow-sm"
              >
                <div className="flex items-center justify-between gap-4 mb-5">
                  <div>
                    <h3 className="font-semibold text-graphite-900">
                      {editingSupplierId
                        ? 'Редактирование поставщика'
                        : 'Новый поставщик'}
                    </h3>
                    <p className="text-sm text-gray-500 mt-1">
                      Код: строчные латинские буквы, цифры, дефис и подчёркивание.
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={closeSupplierForm}
                    className="p-2 rounded-lg text-gray-500 hover:bg-gray-100"
                    aria-label="Закрыть форму"
                  >
                    <X className="w-5 h-5" />
                  </button>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm text-gray-600 mb-2">
                      Название
                    </label>
                    <input
                      type="text"
                      value={supplierForm.name}
                      onChange={(event) =>
                        setSupplierForm((current) => ({
                          ...current,
                          name: event.target.value,
                        }))
                      }
                      maxLength={255}
                      required
                      className="admin-input"
                      placeholder="Название поставщика"
                    />
                  </div>
                  <div>
                    <label className="block text-sm text-gray-600 mb-2">
                      Внутренний код
                    </label>
                    <input
                      type="text"
                      value={supplierForm.internal_code}
                      onChange={(event) =>
                        setSupplierForm((current) => ({
                          ...current,
                          internal_code: event.target.value,
                        }))
                      }
                      maxLength={100}
                      pattern="[a-z0-9][a-z0-9_-]*"
                      required
                      className="admin-input"
                      placeholder="supplier_code"
                    />
                  </div>
                </div>

                <label className="inline-flex items-center gap-3 mt-5 text-sm text-gray-700">
                  <input
                    type="checkbox"
                    checked={supplierForm.is_active}
                    onChange={(event) =>
                      setSupplierForm((current) => ({
                        ...current,
                        is_active: event.target.checked,
                      }))
                    }
                    className="w-4 h-4 rounded border-gray-300 text-accent-500 focus:ring-accent-500"
                  />
                  Поставщик активен
                </label>

                <div className="flex justify-end gap-3 mt-6">
                  <button
                    type="button"
                    onClick={closeSupplierForm}
                    className="px-5 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition"
                  >
                    Отмена
                  </button>
                  <button
                    type="submit"
                    disabled={savingSupplier}
                    className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-accent-500 text-white font-semibold hover:bg-accent-600 transition disabled:opacity-50"
                  >
                    <Save className="w-4 h-4" />
                    {savingSupplier ? 'Сохранение...' : 'Сохранить'}
                  </button>
                </div>
              </form>
            )}

            {suppliersLoading ? (
              <div className="text-center py-16 text-gray-500">
                Загрузка поставщиков...
              </div>
            ) : suppliers.length === 0 ? (
              <div className="bg-white border border-gray-200 rounded-2xl p-12 text-center shadow-sm">
                <Truck className="w-10 h-10 mx-auto text-gray-400" />
                <div className="text-graphite-900 font-semibold mt-4">
                  Поставщиков пока нет
                </div>
                <div className="text-sm text-gray-500 mt-2">
                  Добавьте первого поставщика, когда будете готовы.
                </div>
              </div>
            ) : (
              <div className="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[850px]">
                    <thead>
                      <tr className="border-b border-gray-200 bg-gray-50">
                        <th className="text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">Название</th>
                        <th className="text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">Внутренний код</th>
                        <th className="text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">Статус</th>
                        <th className="text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">Создан</th>
                        <th className="text-left px-5 py-4 text-xs font-semibold uppercase text-gray-500">Изменён</th>
                        <th className="text-right px-5 py-4 text-xs font-semibold uppercase text-gray-500">Действия</th>
                      </tr>
                    </thead>
                    <tbody>
                      {suppliers.map((supplier) => (
                        <tr key={supplier.id} className="border-b border-gray-100 last:border-b-0">
                          <td className="px-5 py-4 text-sm font-semibold text-graphite-900">{supplier.name}</td>
                          <td className="px-5 py-4 text-sm font-mono text-gray-600">{supplier.internal_code}</td>
                          <td className="px-5 py-4">
                            <span className={`inline-flex px-2.5 py-1 rounded-full border text-xs ${
                              supplier.is_active
                                ? 'bg-green-50 text-green-700 border-green-200'
                                : 'bg-gray-50 text-gray-600 border-gray-200'
                            }`}>
                              {supplier.is_active ? 'Активен' : 'Неактивен'}
                            </span>
                          </td>
                          <td className="px-5 py-4 text-sm text-gray-500">{formatDate(supplier.created_at)}</td>
                          <td className="px-5 py-4 text-sm text-gray-500">{formatDate(supplier.updated_at)}</td>
                          <td className="px-5 py-4">
                            <div className="flex justify-end gap-2">
                              <button
                                type="button"
                                onClick={() => openEditSupplier(supplier)}
                                className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition"
                              >
                                <Pencil className="w-4 h-4" />
                                Изменить
                              </button>
                              <button
                                type="button"
                                onClick={() => setSupplierActive(supplier)}
                                disabled={togglingSupplierId === supplier.id}
                                className={`px-3 py-2 rounded-lg border text-sm transition disabled:opacity-50 ${
                                  supplier.is_active
                                    ? 'border-red-200 text-red-600 hover:bg-red-50'
                                    : 'border-green-200 text-green-700 hover:bg-green-50'
                                }`}
                              >
                                {supplier.is_active ? 'Отключить' : 'Включить'}
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {showProductForm && (
        <div className="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm overflow-y-auto p-4">
          <div className="min-h-full flex items-start justify-center py-8">
            <div className="w-full max-w-5xl bg-white rounded-3xl shadow-2xl border border-gray-200 overflow-hidden">
              <div className="flex items-center justify-between px-6 py-5 border-b border-gray-200">
                <div>
                  <div className="text-xl font-display font-semibold text-graphite-900">
                    {editingProductId
                      ? 'Редактирование товара'
                      : 'Добавление товара'}
                  </div>

                  <div className="text-sm text-gray-500 mt-1">
                    Заполните основные данные и сохраните товар.
                  </div>
                </div>

                <button
                  type="button"
                  onClick={() => {
                    setShowProductForm(false);
                    resetProductForm();
                  }}
                  className="p-2 rounded-xl hover:bg-gray-100 text-gray-500"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <div className="p-6 space-y-8">
                <section>
                  <div className="text-sm font-semibold text-graphite-900 mb-4">
                    Основная информация
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm text-gray-600 mb-2">
                        Название *
                      </label>

                      <input
                        value={productForm.name}
                        onChange={(e) =>
                          setProductForm((current) => ({
                            ...current,
                            name: e.target.value,
                          }))
                        }
                        className="admin-input"
                        placeholder="Телевизор TELVORA OLED"
                      />
                    </div>

                    <div>
                      <label className="block text-sm text-gray-600 mb-2">
                        Slug *
                      </label>

                      <input
                        value={productForm.slug}
                        onChange={(e) =>
                          setProductForm((current) => ({
                            ...current,
                            slug: e.target.value,
                          }))
                        }
                        className="admin-input"
                        placeholder="telvora-oled-65"
                      />
                    </div>

                    <div>
                      <label className="block text-sm text-gray-600 mb-2">
                        Серия
                      </label>

                      <input
                        value={productForm.series}
                        onChange={(e) =>
                          setProductForm((current) => ({
                            ...current,
                            series: e.target.value,
                          }))
                        }
                        className="admin-input"
                        placeholder="OLED X1"
                      />
                    </div>

                    <div>
                      <label className="block text-sm text-gray-600 mb-2">
                        Страна
                      </label>

                      <select
                        value={productForm.country}
                        onChange={(e) =>
                          setProductForm((current) => ({
                            ...current,
                            country: e.target.value,
                          }))
                        }
                        className="admin-input"
                      >
                        <option value="">Выберите страну</option>

                        {countries.map((country) => (
                          <option
                            key={country}
                            value={country}
                          >
                            {country}
                          </option>
                        ))}
                      </select>
                    </div>

                    <div>
                      <label className="block text-sm text-gray-600 mb-2">
                        Категория *
                      </label>

                      <select
                        value={productForm.category}
                        onChange={(e) =>
                          setProductForm((current) => ({
                            ...current,
                            category: e.target.value,
                          }))
                        }
                        className="admin-input"
                      >
                        {categories.map((category) => (
                          <option
                            key={category}
                            value={category}
                          >
                            {category}
                          </option>
                        ))}
                      </select>
                    </div>

                    <div>
                      <label className="block text-sm text-gray-600 mb-2">
                        Диагональ
                      </label>

                      <input
                        value={productForm.screen_size}
                        onChange={(e) =>
                          setProductForm((current) => ({
                            ...current,
                            screen_size: e.target.value,
                          }))
                        }
                        className="admin-input"
                        placeholder="65"
                      />
                    </div>

                    <div>
                      <label className="block text-sm text-gray-600 mb-2">
                        Разрешение
                      </label>

                      <input
                        value={productForm.resolution}
                        onChange={(e) =>
                          setProductForm((current) => ({
                            ...current,
                            resolution: e.target.value,
                          }))
                        }
                        className="admin-input"
                        placeholder="4K"
                      />
                    </div>

                    <div>
  <label className="block text-sm text-gray-600 mb-2">
    Изображение
  </label>

  <div className="space-y-3">
    {productForm.image && (
      <div className="w-full h-48 rounded-2xl border border-gray-200 bg-gray-50 overflow-hidden flex items-center justify-center">
        <img
          src={productForm.image}
          alt="Предпросмотр"
          className="max-w-full max-h-full object-contain"
        />
      </div>
    )}

    <input
      value={productForm.image}
      onChange={(e) =>
        setProductForm((current) => ({
          ...current,
          image: e.target.value,
        }))
      }
      className="admin-input"
      placeholder="/images/tv.jpg"
    />

    <input
      type="file"
      accept="image/jpeg,image/png,image/webp"
      onChange={(e) => {
        const file = e.target.files?.[0] || null;
        setImageFile(file);
        setImageUploadError('');
      }}
      className="block w-full text-sm text-gray-600"
    />

    <button
      type="button"
      onClick={uploadProductImage}
      disabled={!imageFile || imageUploading}
      className="px-4 py-2 rounded-xl bg-accent-500 text-white font-semibold disabled:opacity-50"
    >
      {imageUploading ? 'Загрузка...' : 'Загрузить изображение'}
    </button>

    {imageFile && (
      <p className="text-sm text-gray-500">
        Выбран файл: {imageFile.name}
      </p>
    )}

    {imageUploadError && (
      <p className="text-sm text-red-500">
        {imageUploadError}
      </p>
    )}
  </div>
</div>
                  </div>
                </section>

                <section>
                  <div className="text-sm font-semibold text-graphite-900 mb-4">
                    Цена и рейтинг
                  </div>                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div>
                      <label className="block text-sm text-gray-600 mb-2">
                        Бейдж
                      </label>

                      <input
                        value={productForm.badge}
                        onChange={(e) =>
                          setProductForm((current) => ({
                            ...current,
                            badge: e.target.value,
                          }))
                        }
                        className="admin-input"
                        placeholder="Хит продаж"
                      />
                    </div>

                    <label className="flex items-center gap-3 rounded-xl border border-gray-200 px-4 py-3 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={productForm.is_active}
                        onChange={(e) =>
                          setProductForm((current) => ({
                            ...current,
                            is_active: e.target.checked,
                          }))
                        }
                      />

                      <span className="text-sm text-gray-700">
                        Товар активен
                      </span>
                    </label>
                  </div>
                </section>

                <section>
                  <div className="text-sm font-semibold text-graphite-900 mb-4">
                    Описание
                  </div>

                  <textarea
                    value={productForm.description}
                    onChange={(e) =>
                      setProductForm((current) => ({
                        ...current,
                        description: e.target.value,
                      }))
                    }
                    rows={5}
                    className="admin-input resize-y"
                    placeholder="Описание товара..."
                  />
                </section>

                <section>
                  <div className="flex items-center justify-between gap-4 mb-4">
                    <div>
                      <div className="text-sm font-semibold text-graphite-900">
                        Характеристики
                      </div>

                      <div className="text-xs text-gray-500 mt-1">
                        Добавьте характеристики товара.
                      </div>
                    </div>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3">
                    <input
                      value={specName}
                      onChange={(e) =>
                        setSpecName(e.target.value)
                      }
                      className="admin-input"
                      placeholder="Название характеристики"
                    />

                    <input
                      value={specValue}
                      onChange={(e) =>
                        setSpecValue(e.target.value)
                      }
                      className="admin-input"
                      placeholder="Значение"
                    />

                    <button
                      type="button"
                      onClick={addSpec}
                      className="px-4 py-3 rounded-xl bg-gray-900 text-white hover:bg-gray-800 transition"
                    >
                      <Plus className="w-4 h-4" />
                    </button>
                  </div>

                  <div className="space-y-2 mt-4">
                    {specs.map((spec, index) => (
                      <div
                        key={`${spec.label}-${index}`}
                        className="flex items-center justify-between gap-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3"
                      >
                        <div>
                          <div className="text-sm font-medium text-graphite-900">
                            {spec.label}
                          </div>

                          <div className="text-sm text-gray-500 mt-1">
                            {spec.value}
                          </div>
                        </div>

                        <button
                          type="button"
                          onClick={() => removeSpec(index)}
                          className="p-2 rounded-lg text-red-500 hover:bg-red-50"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    ))}
                  </div>
                </section>

                <section>
                  <div className="text-sm font-semibold text-graphite-900 mb-4">
                    Основные преимущества
                  </div>

                  <div className="flex gap-3">
                    <input
                      value={highlightValue}
                      onChange={(e) =>
                        setHighlightValue(e.target.value)
                      }
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                          e.preventDefault();
                          addHighlight();
                        }
                      }}
                      className="admin-input flex-1"
                      placeholder="Например: OLED-панель"
                    />

                    <button
                      type="button"
                      onClick={addHighlight}
                      className="px-4 py-3 rounded-xl bg-gray-900 text-white hover:bg-gray-800 transition"
                    >
                      <Plus className="w-4 h-4" />
                    </button>
                  </div>

                  <div className="flex flex-wrap gap-2 mt-4">
                    {highlights.map((highlight, index) => (
                      <div
                        key={`${highlight}-${index}`}
                        className="inline-flex items-center gap-2 rounded-xl bg-accent-50 border border-accent-200 text-accent-700 px-3 py-2 text-sm"
                      >
                        <span>{highlight}</span>

                        <button
                          type="button"
                          onClick={() =>
                            removeHighlight(index)
                          }
                          className="text-accent-500 hover:text-accent-700"
                        >
                          <X className="w-4 h-4" />
                        </button>
                      </div>
                    ))}
                  </div>
                </section>

                <section>
                  <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                    <div>
                      <div className="text-sm font-semibold text-graphite-900">
                        Варианты товара
                      </div>

                      <div className="text-xs text-gray-500 mt-1">
                        Разные страны и цены одного товара.
                      </div>
                    </div>

                    <button
                      type="button"
                      onClick={addVariantToForm}
                      className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-accent-500 text-white hover:bg-accent-600 transition"
                    >
                      <Plus className="w-4 h-4" />
                      Добавить вариант
                    </button>
                  </div>

                  {variants.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-gray-300 p-5 text-center text-sm text-gray-500">
                      Вариантов пока нет.
                    </div>
                  ) : (
                    <div className="space-y-3">
                      {variants.map((variant, index) => (
                        <div
                          key={index}
                          className="grid grid-cols-1 md:grid-cols-[1fr_1fr_1fr_auto] gap-3 items-end rounded-xl border border-gray-200 bg-gray-50 p-4"
                        >
                          <div>
                            <label className="block text-sm text-gray-600 mb-2">
                              Страна
                            </label>

                            <input
  type="text"
  value={variant.country || ''}
  onChange={(e) =>
    updateFormVariant(
      index,
      'country',
      e.target.value
    )
  }
  className="admin-input"
  placeholder="Например: США"
/>
                          </div>

                          <div>
                            <label className="block text-sm text-gray-600 mb-2">
                              Цена
                            </label>

                            <input
                              type="number"
                              value={variant.price}
                              onChange={(e) =>
                                updateFormVariant(
                                  index,
                                  'price',
                                  e.target.value
                                )
                              }
                              className="admin-input"
                              placeholder="189990"
                            />
                          </div>

                          <div>
                            <label className="block text-sm text-gray-600 mb-2">
                              Старая цена
                            </label>

                            <input
                              type="number"
                              value={variant.old_price}
                              onChange={(e) =>
                                updateFormVariant(
                                  index,
                                  'old_price',
                                  e.target.value
                                )
                              }
                              className="admin-input"
                              placeholder="219990"
                            />
                          </div>

                          <button
                            type="button"
                            onClick={() =>
                              removeFormVariant(index)
                            }
                            className="px-4 py-3 rounded-xl border border-red-200 text-red-500 hover:bg-red-50 transition"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      ))}
                    </div>
                  )}
                </section>
              </div>

              <div className="flex flex-wrap justify-end gap-3 px-6 py-5 border-t border-gray-200 bg-gray-50">
                <button
                  type="button"
                  onClick={() => {
                    setShowProductForm(false);
                    resetProductForm();
                  }}
                  className="px-5 py-3 rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition"
                >
                  Отмена
                </button>

                <button
                  type="button"
                  onClick={saveProduct}
                  disabled={savingProduct}
                  className="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-accent-500 text-white font-semibold hover:bg-accent-600 transition disabled:opacity-50"
                >
                  <Save className="w-4 h-4" />

                  {savingProduct
                    ? 'Сохранение...'
                    : editingProductId
                    ? 'Сохранить изменения'
                    : 'Добавить товар'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
