import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
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

type SupplierImportMappingKey =
  | 'supplier_sku'
  | 'product_name'
  | 'purchase_price'
  | 'currency_code'
  | 'availability'
  | 'arrival_info'
  | 'model'
  | 'assembly_country'
  | 'market_region'
  | 'certification_supply_type';

type SupplierImportProfile = {
  id: number;
  supplier_id: number;
  name: string;
  sheet_name: string | null;
  header_row_number: number;
  column_mapping: Partial<Record<SupplierImportMappingKey, string>>;
  parser_options: {
    trim_values?: boolean;
    skip_empty_rows?: boolean;
    decimal_separator?: '.' | ',';
    default_currency_code?: string | null;
  };
  arrival_date_format: 'dmy_dot' | 'ymd_dash' | 'dmy_slash' | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
};

type SupplierAvailabilityStatus = 'in_stock' | 'out_of_stock' | 'expected' | 'unknown';

type SupplierAvailabilityMapping = {
  id: number;
  profile_id: number;
  raw_value: string;
  normalized_status: Exclude<SupplierAvailabilityStatus, 'unknown'>;
  is_active: boolean;
  created_at: string;
  updated_at: string;
};

type SupplierAvailabilityNormalization = {
  status: SupplierAvailabilityStatus;
  stock_quantity: number | null;
  expected_arrival_at: string | null;
  warnings: Array<{ code: string; message: string }>;
  raw_availability: string | null;
  raw_arrival: string | null;
  raw_stock: string | null;
};

type SupplierImportPreviewRow = {
  source_row_number: number;
  values: Partial<Record<SupplierImportMappingKey, string>>;
  normalized: Partial<Record<SupplierImportMappingKey, string | null>>;
  errors: string[];
  warnings: string[];
};

type SupplierImportPreview = {
  supplier_id: number;
  profile_id: number;
  original_filename: string;
  format: 'csv' | 'xls' | 'xlsx';
  sheet_name: string;
  header_row_number: number;
  detected_headers: Partial<Record<SupplierImportMappingKey, string>>;
  mapping: Partial<Record<SupplierImportMappingKey, string>>;
  rows_scanned: number;
  rows_skipped: number;
  rows_with_errors: number;
  preview_truncated: boolean;
  rows: SupplierImportPreviewRow[];
};

type SupplierImportJob = {
  id: number;
  supplier_id: number;
  import_profile_id: number | null;
  original_filename: string;
  profile_name: string | null;
  status: string;
  rows_total: number;
  rows_matched: number;
  rows_unmatched: number;
  rows_errors: number;
  created_at: string;
  finished_at: string | null;
};

type SupplierImportStagedRow = {
  id: number;
  source_row_number: number;
  supplier_sku: string | null;
  raw_product_name: string | null;
  normalized_model: string | null;
  purchase_price: string | null;
  currency_code: string | null;
  raw_availability: string | null;
  raw_arrival_info: string | null;
  availability_normalization: SupplierAvailabilityNormalization;
  status: string;
  errors: string[];
  warnings: string[];
  matched_product_id: number | null;
  matched_product_variant_id: number | null;
  matched_product_name: string | null;
  matched_variant_name: string | null;
  matched_variant_key: string | null;
};

type SupplierImportProductResult = {
  id: number;
  name: string;
  series: string;
  variants: Array<{
    id: number;
    product_id: number;
    variant_key: string;
    display_name: string | null;
    assembly_country: string | null;
    manufacturer_part_number: string | null;
  }>;
};

type SupplierOfferPublishSummary = {
  total_rows: number;
  eligible_rows: number;
  offers_to_create: number;
  offers_to_update: number;
  skipped_errors: number;
  skipped_unmatched: number;
  skipped_no_variant: number;
  skipped_invalid_price: number;
  skipped_invalid_currency: number;
  skipped_missing_sku: number;
  skipped_duplicate_sku: number;
  skipped_missing_name: number;
  skipped_offer_conflict: number;
  skipped_stale_source: number;
  skipped_unknown_source: number;
};

type SupplierOfferPricingPreview = {
  id: number;
  supplier_name: string;
  product_name: string;
  variant_name: string | null;
  variant_key: string;
  supplier_sku: string | null;
  purchase_price: string;
  currency_code: string;
  availability_status: string;
  stock_quantity: number | null;
  expected_arrival_at: string | null;
  raw_availability: string | null;
  raw_arrival_info: string | null;
  delivery_info: string | null;
  source_import_row_id: number;
  pricing: {
    calculable: boolean;
    rule: { id: number; name: string; markup_percent: string | null } | null;
    price_before_rounding?: string;
    candidate_retail_price?: string;
    expected_margin?: string;
    expected_margin_percent?: string;
    warnings: string[];
  };
};

type PricePublicationPreview = {
  can_publish: boolean;
  blocking_reasons: string[];
  warnings: string[];
  snapshot_token: string;
  offer: {
    id: number;
    supplier_id: number;
    supplier_name: string;
    supplier_sku: string | null;
    purchase_price: string;
    currency_code: string;
    imported_at: string;
    source_import_row_id: number | null;
    source_import_job_id: number | null;
  };
  product: { id: number; name: string; base_price: string; base_old_price: string | null };
  variant: {
    id: number;
    variant_key: string;
    assembly_country: string | null;
    display_name: string | null;
    current_live_price: string | null;
  };
  pricing: SupplierOfferPricingPreview['pricing'];
  delta_amount: string | null;
  delta_percent: string | null;
};

type PricePublicationHistoryRow = {
  id: number;
  product_name: string;
  assembly_country: string;
  old_live_price: string;
  new_live_price: string;
  supplier_name: string;
  supplier_sku: string | null;
  purchase_price: string;
  currency_code: string;
  pricing_rule_name: string;
  admin_actor: string;
  admin_comment: string | null;
  created_at: string;
};

type PricingRule = {
  id: number;
  name: string;
  priority: number;
  category_scope: string | null;
  purchase_price_min: string | null;
  purchase_price_max: string | null;
  markup_percent: string | null;
  minimum_margin: string | null;
  rounding_strategy: string | null;
  valid_from: string | null;
  valid_until: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  supported_by_stage6: boolean;
  warning: string | null;
};

const MANAGER_API = '/manager.php';
const PRODUCTS_API = '/products.php';

async function parseSupplierResponse(response: Response) {
  const responseText = await response.text();

  try {
    return JSON.parse(responseText);
  } catch {
    return {
      success: false,
      message: 'Сервер вернул некорректный ответ',
    };
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

const emptyPricingRuleForm = {
  name: '',
  priority: '100',
  category_scope: '',
  purchase_price_min: '',
  purchase_price_max: '',
  markup_percent: '',
  minimum_margin: '',
  valid_from: '',
  valid_until: '',
  is_active: false,
};

function toDatetimeLocal(value: string | null) {
  return value ? value.replace(' ', 'T').slice(0, 16) : '';
}

const supplierImportMappingFields: Array<{
  key: SupplierImportMappingKey;
  label: string;
}> = [
  { key: 'supplier_sku', label: 'Артикул поставщика' },
  { key: 'product_name', label: 'Название товара' },
  { key: 'purchase_price', label: 'Закупочная цена' },
  { key: 'currency_code', label: 'Код валюты' },
  { key: 'availability', label: 'Наличие / остаток' },
  { key: 'arrival_info', label: 'Информация о поступлении' },
  { key: 'model', label: 'Модель' },
  { key: 'assembly_country', label: 'Страна сборки' },
  { key: 'market_region', label: 'Регион рынка' },
  { key: 'certification_supply_type', label: 'Тип сертификации / поставки' },
];

const supplierAvailabilityLabels: Record<SupplierAvailabilityStatus, string> = {
  in_stock: 'В наличии',
  out_of_stock: 'Нет в наличии',
  expected: 'Ожидается',
  unknown: 'Неизвестно',
};

const emptySupplierImportProfileForm = {
  name: '',
  sheet_name: '',
  header_row_number: '1',
  column_mapping: {} as Partial<Record<SupplierImportMappingKey, string>>,
  trim_values: true,
  skip_empty_rows: true,
  decimal_separator: '.' as '.' | ',',
  default_currency_code: 'RUB',
  arrival_date_format: '' as '' | 'dmy_dot' | 'ymd_dash' | 'dmy_slash',
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
  const [pricingRules, setPricingRules] = useState<PricingRule[]>([]);
  const [pricingCategories, setPricingCategories] = useState<string[]>([]);
  const [pricingRulesLoading, setPricingRulesLoading] = useState(false);
  const [pricingRulesError, setPricingRulesError] = useState('');
  const [pricingRulesSuccess, setPricingRulesSuccess] = useState('');
  const [showPricingRuleForm, setShowPricingRuleForm] = useState(false);
  const [editingPricingRule, setEditingPricingRule] = useState<PricingRule | null>(null);
  const [pricingRuleForm, setPricingRuleForm] = useState(emptyPricingRuleForm);
  const [savingPricingRule, setSavingPricingRule] = useState(false);
  const [togglingPricingRuleId, setTogglingPricingRuleId] = useState<number | null>(null);
  const pricingRuleWritePendingRef = useRef(false);
  const [profileSupplier, setProfileSupplier] = useState<Supplier | null>(null);
  const [supplierImportProfiles, setSupplierImportProfiles] = useState<SupplierImportProfile[]>([]);
  const [supplierImportProfilesLoading, setSupplierImportProfilesLoading] = useState(false);
  const [supplierImportProfilesError, setSupplierImportProfilesError] = useState('');
  const [supplierImportProfilesSuccess, setSupplierImportProfilesSuccess] = useState('');
  const [showSupplierImportProfileForm, setShowSupplierImportProfileForm] = useState(false);
  const [editingSupplierImportProfileId, setEditingSupplierImportProfileId] = useState<number | null>(null);
  const [supplierImportProfileForm, setSupplierImportProfileForm] = useState(emptySupplierImportProfileForm);
  const [savingSupplierImportProfile, setSavingSupplierImportProfile] = useState(false);
  const [togglingSupplierImportProfileId, setTogglingSupplierImportProfileId] = useState<number | null>(null);
  const [availabilityProfile, setAvailabilityProfile] = useState<SupplierImportProfile | null>(null);
  const [availabilityMappings, setAvailabilityMappings] = useState<SupplierAvailabilityMapping[]>([]);
  const [availabilityMappingsLoading, setAvailabilityMappingsLoading] = useState(false);
  const [availabilityMappingError, setAvailabilityMappingError] = useState('');
  const [availabilityMappingForm, setAvailabilityMappingForm] = useState({
    id: null as number | null,
    updated_at: '',
    raw_value: '',
    normalized_status: 'in_stock' as Exclude<SupplierAvailabilityStatus, 'unknown'>,
    is_active: true,
  });
  const availabilityMappingPendingRef = useRef(false);
  const profileSupplierIdRef = useRef<number | null>(null);
  const supplierImportProfileWritePendingRef = useRef(false);
  const supplierImportPreviewPendingRef = useRef(false);
  const [previewProfile, setPreviewProfile] = useState<SupplierImportProfile | null>(null);
  const [previewFile, setPreviewFile] = useState<File | null>(null);
  const [supplierImportPreview, setSupplierImportPreview] = useState<SupplierImportPreview | null>(null);
  const [supplierImportPreviewLoading, setSupplierImportPreviewLoading] = useState(false);
  const [supplierImportPreviewError, setSupplierImportPreviewError] = useState('');
  const [previewFileInputKey, setPreviewFileInputKey] = useState(0);
  const supplierImportStagePendingRef = useRef(false);
  const [supplierImportStageLoading, setSupplierImportStageLoading] = useState(false);
  const [supplierImportJobs, setSupplierImportJobs] = useState<SupplierImportJob[]>([]);
  const [supplierImportJobsLoading, setSupplierImportJobsLoading] = useState(false);
  const [selectedImportJob, setSelectedImportJob] = useState<SupplierImportJob | null>(null);
  const [stagedRows, setStagedRows] = useState<SupplierImportStagedRow[]>([]);
  const [stagedRowsLoading, setStagedRowsLoading] = useState(false);
  const [stagedRowsFilter, setStagedRowsFilter] = useState('all');
  const [stagedRowsPage, setStagedRowsPage] = useState(1);
  const [stagedRowsPages, setStagedRowsPages] = useState(1);
  const [manualMatchRow, setManualMatchRow] = useState<SupplierImportStagedRow | null>(null);
  const [manualProductQuery, setManualProductQuery] = useState('');
  const [manualProductResults, setManualProductResults] = useState<SupplierImportProductResult[]>([]);
  const [manualMatchLoading, setManualMatchLoading] = useState(false);
  const [offerPublishSummary, setOfferPublishSummary] = useState<SupplierOfferPublishSummary | null>(null);
  const [offerPublishLoading, setOfferPublishLoading] = useState(false);
  const offerPublishPendingRef = useRef(false);
  const [offerPricingRows, setOfferPricingRows] = useState<SupplierOfferPricingPreview[]>([]);
  const [offerPricingPage, setOfferPricingPage] = useState(1);
  const [offerPricingPages, setOfferPricingPages] = useState(1);
  const [offerPricingLoading, setOfferPricingLoading] = useState(false);
  const [pricePublicationPreview, setPricePublicationPreview] = useState<PricePublicationPreview | null>(null);
  const [pricePublicationLoading, setPricePublicationLoading] = useState(false);
  const [pricePublicationError, setPricePublicationError] = useState('');
  const [pricePublicationSuccess, setPricePublicationSuccess] = useState('');
  const [pricePublicationComment, setPricePublicationComment] = useState('');
  const pricePublicationPendingRef = useRef(false);
  const [pricePublicationHistory, setPricePublicationHistory] = useState<PricePublicationHistoryRow[]>([]);
  const [pricePublicationHistoryPage, setPricePublicationHistoryPage] = useState(1);
  const [pricePublicationHistoryPages, setPricePublicationHistoryPages] = useState(1);
  const [pricePublicationHistoryLoading, setPricePublicationHistoryLoading] = useState(false);

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

      const loadedSuppliers = Array.isArray(data.suppliers)
        ? data.suppliers
        : [];
      setSuppliers(loadedSuppliers);
      setProfileSupplier((current) =>
        current
          ? loadedSuppliers.find(
              (supplier: Supplier) => supplier.id === current.id
            ) || null
          : null
      );
    } catch (err) {
      setSuppliersError(
        err instanceof Error ? err.message : 'Не удалось загрузить поставщиков'
      );
    } finally {
      setSuppliersLoading(false);
    }
  };

  const loadPricingRules = async () => {
    setPricingRulesLoading(true);
    setPricingRulesError('');
    try {
      const response = await fetch(`${MANAGER_API}?action=pricing_rules_list`, {
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
        throw new Error(data.message || 'Не удалось загрузить правила ценообразования');
      }
      setPricingRules(Array.isArray(data.rules) ? data.rules : []);
      setPricingCategories(Array.isArray(data.categories) ? data.categories : []);
    } catch (err) {
      setPricingRulesError(err instanceof Error ? err.message : 'Не удалось загрузить правила ценообразования');
    } finally {
      setPricingRulesLoading(false);
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
      setPricingRules([]);
      setPricingCategories([]);
      setShowProductForm(false);
      setShowSupplierForm(false);
      profileSupplierIdRef.current = null;
      setProfileSupplier(null);
      setSupplierImportProfiles([]);
      setShowSupplierImportProfileForm(false);
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
      loadPricingRules();
    }
  }, [authenticated, activeTab]);

  const openAddPricingRule = () => {
    setEditingPricingRule(null);
    setPricingRuleForm(emptyPricingRuleForm);
    setPricingRulesError('');
    setPricingRulesSuccess('');
    setShowPricingRuleForm(true);
  };

  const openEditPricingRule = (rule: PricingRule) => {
    if (!rule.supported_by_stage6) {
      setPricingRulesError(rule.warning || 'Это правило нельзя редактировать в Stage 8');
      return;
    }
    setEditingPricingRule(rule);
    setPricingRuleForm({
      name: rule.name,
      priority: String(rule.priority),
      category_scope: rule.category_scope || '',
      purchase_price_min: rule.purchase_price_min || '',
      purchase_price_max: rule.purchase_price_max || '',
      markup_percent: rule.markup_percent || '',
      minimum_margin: rule.minimum_margin || '',
      valid_from: toDatetimeLocal(rule.valid_from),
      valid_until: toDatetimeLocal(rule.valid_until),
      is_active: rule.is_active,
    });
    setPricingRulesError('');
    setPricingRulesSuccess('');
    setShowPricingRuleForm(true);
  };

  const closePricingRuleForm = () => {
    setShowPricingRuleForm(false);
    setEditingPricingRule(null);
    setPricingRuleForm(emptyPricingRuleForm);
  };

  const savePricingRule = async (event: React.FormEvent) => {
    event.preventDefault();
    if (pricingRuleWritePendingRef.current) return;
    const priority = Number(pricingRuleForm.priority);
    if (!Number.isInteger(priority)) {
      setPricingRulesError('Приоритет должен быть целым числом');
      return;
    }
    pricingRuleWritePendingRef.current = true;
    setSavingPricingRule(true);
    setPricingRulesError('');
    setPricingRulesSuccess('');
    try {
      const response = await fetch(MANAGER_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken || '',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          action: editingPricingRule ? 'pricing_rule_update' : 'pricing_rule_create',
          ...(editingPricingRule ? { id: editingPricingRule.id, updated_at: editingPricingRule.updated_at } : {}),
          name: pricingRuleForm.name,
          priority,
          category_scope: pricingRuleForm.category_scope.trim() || null,
          purchase_price_min: pricingRuleForm.purchase_price_min.trim() || null,
          purchase_price_max: pricingRuleForm.purchase_price_max.trim() || null,
          markup_percent: pricingRuleForm.markup_percent.trim() || null,
          minimum_margin: pricingRuleForm.minimum_margin.trim() || null,
          valid_from: pricingRuleForm.valid_from || null,
          valid_until: pricingRuleForm.valid_until || null,
          is_active: pricingRuleForm.is_active,
        }),
      });
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Не удалось сохранить правило');
      }
      closePricingRuleForm();
      setPricingRulesSuccess(data.message || 'Правило сохранено');
      await loadPricingRules();
    } catch (err) {
      setPricingRulesError(err instanceof Error ? err.message : 'Не удалось сохранить правило');
    } finally {
      pricingRuleWritePendingRef.current = false;
      setSavingPricingRule(false);
    }
  };

  const setPricingRuleActive = async (rule: PricingRule) => {
    if (pricingRuleWritePendingRef.current) return;
    const nextActive = !rule.is_active;
    if (nextActive && !rule.supported_by_stage6) {
      setPricingRulesError(rule.warning || 'Неподдерживаемое правило нельзя активировать');
      return;
    }
    pricingRuleWritePendingRef.current = true;
    setTogglingPricingRuleId(rule.id);
    setPricingRulesError('');
    setPricingRulesSuccess('');
    try {
      const response = await fetch(MANAGER_API, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken || '', Accept: 'application/json' },
        body: JSON.stringify({ action: 'pricing_rule_set_active', id: rule.id, updated_at: rule.updated_at, is_active: nextActive }),
      });
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось изменить статус правила');
      setPricingRulesSuccess(data.message || 'Статус правила изменён');
      await loadPricingRules();
    } catch (err) {
      setPricingRulesError(err instanceof Error ? err.message : 'Не удалось изменить статус правила');
    } finally {
      pricingRuleWritePendingRef.current = false;
      setTogglingPricingRuleId(null);
    }
  };

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

  const loadSupplierImportProfiles = async (supplier: Supplier) => {
    setSupplierImportProfilesLoading(true);
    setSupplierImportProfilesError('');

    try {
      const response = await fetch(
        `${MANAGER_API}?action=supplier_import_profiles_list&supplier_id=${supplier.id}`,
        {
          method: 'GET',
          credentials: 'include',
          headers: { Accept: 'application/json' },
        }
      );
      const data = await parseSupplierResponse(response);

      if (!response.ok || !data.success) {
        if (response.status === 401 || response.status === 403) {
          setAuthenticated(false);
          setCsrfToken(null);
        }

        throw new Error(data.message || 'Не удалось загрузить профили импорта');
      }

      if (profileSupplierIdRef.current === supplier.id) {
        setSupplierImportProfiles(
          Array.isArray(data.profiles) ? data.profiles : []
        );
      }
    } catch (err) {
      if (profileSupplierIdRef.current === supplier.id) {
        setSupplierImportProfilesError(
          err instanceof Error
            ? err.message
            : 'Не удалось загрузить профили импорта'
        );
      }
    } finally {
      if (profileSupplierIdRef.current === supplier.id) {
        setSupplierImportProfilesLoading(false);
      }
    }
  };

  const loadSupplierImportJobs = async (supplier: Supplier) => {
    setSupplierImportJobsLoading(true);
    try {
      const response = await fetch(
        `${MANAGER_API}?action=supplier_import_jobs_list&supplier_id=${supplier.id}`,
        { method: 'GET', credentials: 'include', headers: { Accept: 'application/json' } }
      );
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Не удалось загрузить историю импортов');
      }
      if (profileSupplierIdRef.current === supplier.id) {
        setSupplierImportJobs(Array.isArray(data.jobs) ? data.jobs : []);
      }
    } catch (err) {
      if (profileSupplierIdRef.current === supplier.id) {
        setSupplierImportProfilesError(
          err instanceof Error ? err.message : 'Не удалось загрузить историю импортов'
        );
      }
    } finally {
      if (profileSupplierIdRef.current === supplier.id) {
        setSupplierImportJobsLoading(false);
      }
    }
  };

  const openSupplierImportProfiles = (supplier: Supplier) => {
    profileSupplierIdRef.current = supplier.id;
    setProfileSupplier(supplier);
    setSupplierImportProfiles([]);
    setSupplierImportProfilesError('');
    setSupplierImportProfilesSuccess('');
    setShowSupplierImportProfileForm(false);
    setEditingSupplierImportProfileId(null);
    setAvailabilityProfile(null);
    setAvailabilityMappings([]);
    setSupplierImportProfileForm(emptySupplierImportProfileForm);
    setPreviewProfile(null);
    setPreviewFile(null);
    setSupplierImportPreview(null);
    setSupplierImportPreviewError('');
    setSupplierImportJobs([]);
    setSelectedImportJob(null);
    setStagedRows([]);
    setManualMatchRow(null);
    setOfferPublishSummary(null);
    setOfferPricingRows([]);
    setPreviewFileInputKey((current) => current + 1);
    loadSupplierImportProfiles(supplier);
    loadSupplierImportJobs(supplier);
  };

  const closeSupplierImportProfiles = () => {
    profileSupplierIdRef.current = null;
    setProfileSupplier(null);
    setSupplierImportProfiles([]);
    setSupplierImportProfilesError('');
    setSupplierImportProfilesSuccess('');
    setShowSupplierImportProfileForm(false);
    setEditingSupplierImportProfileId(null);
    setAvailabilityProfile(null);
    setAvailabilityMappings([]);
    setSupplierImportProfileForm(emptySupplierImportProfileForm);
    setPreviewProfile(null);
    setPreviewFile(null);
    setSupplierImportPreview(null);
    setSupplierImportPreviewError('');
    setSupplierImportJobs([]);
    setSelectedImportJob(null);
    setStagedRows([]);
    setManualMatchRow(null);
    setOfferPublishSummary(null);
    setOfferPricingRows([]);
    setPreviewFileInputKey((current) => current + 1);
  };

  const openAddSupplierImportProfile = () => {
    setEditingSupplierImportProfileId(null);
    setSupplierImportProfileForm(emptySupplierImportProfileForm);
    setSupplierImportProfilesError('');
    setSupplierImportProfilesSuccess('');
    setShowSupplierImportProfileForm(true);
  };

  const openEditSupplierImportProfile = (profile: SupplierImportProfile) => {
    setEditingSupplierImportProfileId(profile.id);
    setSupplierImportProfileForm({
      name: profile.name,
      sheet_name: profile.sheet_name || '',
      header_row_number: String(profile.header_row_number),
      column_mapping: { ...profile.column_mapping },
      trim_values: profile.parser_options.trim_values ?? true,
      skip_empty_rows: profile.parser_options.skip_empty_rows ?? true,
      decimal_separator: profile.parser_options.decimal_separator ?? '.',
      default_currency_code:
        profile.parser_options.default_currency_code || '',
      arrival_date_format: profile.arrival_date_format || '',
      is_active: profile.is_active,
    });
    setSupplierImportProfilesError('');
    setSupplierImportProfilesSuccess('');
    setShowSupplierImportProfileForm(true);
  };

  const closeSupplierImportProfileForm = () => {
    setShowSupplierImportProfileForm(false);
    setEditingSupplierImportProfileId(null);
    setSupplierImportProfileForm(emptySupplierImportProfileForm);
  };

  const saveSupplierImportProfile = async (event: React.FormEvent) => {
    event.preventDefault();

    if (!profileSupplier || supplierImportProfileWritePendingRef.current) {
      return;
    }

    const name = supplierImportProfileForm.name.trim();
    const headerRowValue = supplierImportProfileForm.header_row_number.trim();

    if (!name) {
      setSupplierImportProfilesError('Введите название профиля');
      return;
    }

    if (!/^\d+$/.test(headerRowValue)) {
      setSupplierImportProfilesError(
        'Номер строки заголовков должен быть целым числом от 0'
      );
      return;
    }

    const headerRowNumber = Number(headerRowValue);
    if (!Number.isSafeInteger(headerRowNumber) || headerRowNumber > 1048576) {
      setSupplierImportProfilesError(
        'Номер строки заголовков должен быть от 0 до 1048576'
      );
      return;
    }

    const columnMapping = Object.fromEntries(
      supplierImportMappingFields.flatMap(({ key }) => {
        const value = supplierImportProfileForm.column_mapping[key]?.trim();
        return value ? [[key, value]] : [];
      })
    );
    const currencyCode = supplierImportProfileForm.default_currency_code
      .trim()
      .toUpperCase();

    supplierImportProfileWritePendingRef.current = true;
    setSavingSupplierImportProfile(true);
    setSupplierImportProfilesError('');
    setSupplierImportProfilesSuccess('');

    try {
      const action = editingSupplierImportProfileId
        ? 'supplier_import_profile_update'
        : 'supplier_import_profile_create';
      const response = await fetch(MANAGER_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify({
          action,
          ...(editingSupplierImportProfileId
            ? { id: editingSupplierImportProfileId }
            : {}),
          supplier_id: profileSupplier.id,
          name,
          sheet_name: supplierImportProfileForm.sheet_name.trim() || null,
          header_row_number: headerRowNumber,
          column_mapping: columnMapping,
          parser_options: {
            trim_values: supplierImportProfileForm.trim_values,
            skip_empty_rows: supplierImportProfileForm.skip_empty_rows,
            decimal_separator: supplierImportProfileForm.decimal_separator,
            default_currency_code: currencyCode || null,
          },
          arrival_date_format: supplierImportProfileForm.arrival_date_format || null,
          is_active: supplierImportProfileForm.is_active,
        }),
      });
      const data = await parseSupplierResponse(response);

      if (!response.ok || !data.success) {
        if (response.status === 401 || response.status === 403) {
          setAuthenticated(false);
          setCsrfToken(null);
        }

        throw new Error(data.message || 'Не удалось сохранить профиль импорта');
      }

      closeSupplierImportProfileForm();
      setSupplierImportProfilesSuccess(
        data.message || 'Профиль импорта сохранён'
      );
      await loadSupplierImportProfiles(profileSupplier);
    } catch (err) {
      setSupplierImportProfilesError(
        err instanceof Error
          ? err.message
          : 'Не удалось сохранить профиль импорта'
      );
    } finally {
      supplierImportProfileWritePendingRef.current = false;
      setSavingSupplierImportProfile(false);
    }
  };

  const loadAvailabilityMappings = async (profile: SupplierImportProfile) => {
    setAvailabilityProfile(profile);
    setAvailabilityMappingsLoading(true);
    setAvailabilityMappingError('');
    try {
      const response = await fetch(`${MANAGER_API}?action=supplier_availability_mappings_list&profile_id=${profile.id}`, {
        credentials: 'include', headers: { Accept: 'application/json' },
      });
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось загрузить правила наличия');
      setAvailabilityMappings(Array.isArray(data.mappings) ? data.mappings : []);
    } catch (err) {
      setAvailabilityMappingError(err instanceof Error ? err.message : 'Не удалось загрузить правила наличия');
    } finally {
      setAvailabilityMappingsLoading(false);
    }
  };

  const resetAvailabilityMappingForm = () => setAvailabilityMappingForm({
    id: null, updated_at: '', raw_value: '', normalized_status: 'in_stock', is_active: true,
  });

  const saveAvailabilityMapping = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!availabilityProfile || availabilityMappingPendingRef.current) return;
    availabilityMappingPendingRef.current = true;
    setAvailabilityMappingError('');
    try {
      const editing = availabilityMappingForm.id !== null;
      const response = await fetch(MANAGER_API, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken || '' },
        body: JSON.stringify({
          action: editing ? 'supplier_availability_mapping_update' : 'supplier_availability_mapping_create',
          ...(editing ? { id: availabilityMappingForm.id, updated_at: availabilityMappingForm.updated_at } : { profile_id: availabilityProfile.id }),
          raw_value: availabilityMappingForm.raw_value.trim(),
          normalized_status: availabilityMappingForm.normalized_status,
          is_active: availabilityMappingForm.is_active,
        }),
      });
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось сохранить правило наличия');
      resetAvailabilityMappingForm();
      await loadAvailabilityMappings(availabilityProfile);
      if (selectedImportJob) await loadSupplierImportJobRows(selectedImportJob, stagedRowsPage, stagedRowsFilter);
    } catch (err) {
      setAvailabilityMappingError(err instanceof Error ? err.message : 'Не удалось сохранить правило наличия');
    } finally {
      availabilityMappingPendingRef.current = false;
    }
  };

  const toggleAvailabilityMapping = async (mapping: SupplierAvailabilityMapping) => {
    if (!availabilityProfile || availabilityMappingPendingRef.current) return;
    availabilityMappingPendingRef.current = true;
    setAvailabilityMappingError('');
    try {
      const response = await fetch(MANAGER_API, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken || '' },
        body: JSON.stringify({ action: 'supplier_availability_mapping_set_active', id: mapping.id, updated_at: mapping.updated_at, is_active: !mapping.is_active }),
      });
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось изменить статус правила');
      await loadAvailabilityMappings(availabilityProfile);
    } catch (err) {
      setAvailabilityMappingError(err instanceof Error ? err.message : 'Не удалось изменить статус правила');
    } finally {
      availabilityMappingPendingRef.current = false;
    }
  };

  const setSupplierImportProfileActive = async (
    profile: SupplierImportProfile
  ) => {
    if (!profileSupplier || supplierImportProfileWritePendingRef.current) {
      return;
    }

    const nextActive = !profile.is_active;
    if (
      !nextActive &&
      !window.confirm(`Отключить профиль импорта «${profile.name}»?`)
    ) {
      return;
    }

    supplierImportProfileWritePendingRef.current = true;
    setTogglingSupplierImportProfileId(profile.id);
    setSupplierImportProfilesError('');
    setSupplierImportProfilesSuccess('');

    try {
      const response = await fetch(MANAGER_API, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify({
          action: 'supplier_import_profile_set_active',
          id: profile.id,
          supplier_id: profileSupplier.id,
          is_active: nextActive,
        }),
      });
      const data = await parseSupplierResponse(response);

      if (!response.ok || !data.success) {
        if (response.status === 401 || response.status === 403) {
          setAuthenticated(false);
          setCsrfToken(null);
        }

        throw new Error(
          data.message || 'Не удалось изменить статус профиля импорта'
        );
      }

      setSupplierImportProfilesSuccess(
        data.message || 'Статус профиля импорта изменён'
      );
      await loadSupplierImportProfiles(profileSupplier);
    } catch (err) {
      setSupplierImportProfilesError(
        err instanceof Error
          ? err.message
          : 'Не удалось изменить статус профиля импорта'
      );
    } finally {
      supplierImportProfileWritePendingRef.current = false;
      setTogglingSupplierImportProfileId(null);
    }
  };

  const openSupplierImportPreview = (profile: SupplierImportProfile) => {
    setPreviewProfile(profile);
    setPreviewFile(null);
    setSupplierImportPreview(null);
    setSupplierImportPreviewError('');
    setPreviewFileInputKey((current) => current + 1);
  };

  const closeSupplierImportPreview = () => {
    if (supplierImportPreviewLoading || supplierImportStageLoading) {
      return;
    }
    setPreviewProfile(null);
    setPreviewFile(null);
    setSupplierImportPreview(null);
    setSupplierImportPreviewError('');
    setPreviewFileInputKey((current) => current + 1);
  };

  const requestSupplierImportPreview = async (event: React.FormEvent) => {
    event.preventDefault();

    if (
      !profileSupplier ||
      !previewProfile ||
      !previewFile ||
      supplierImportPreviewPendingRef.current
    ) {
      setSupplierImportPreviewError('Выберите файл прайс-листа');
      return;
    }

    if (previewFile.size <= 0 || previewFile.size > 2 * 1024 * 1024) {
      setSupplierImportPreviewError(
        previewFile.size > 2 * 1024 * 1024
          ? 'Файл слишком большой. Максимальный размер — 2 МБ.'
          : 'Выбранный файл пуст'
      );
      return;
    }

    if (!/\.(csv|xls|xlsx)$/i.test(previewFile.name)) {
      setSupplierImportPreviewError('Поддерживаются только файлы CSV, XLS и XLSX');
      return;
    }

    const formData = new FormData();
    formData.append('supplier_id', String(profileSupplier.id));
    formData.append('profile_id', String(previewProfile.id));
    formData.append('file', previewFile);

    supplierImportPreviewPendingRef.current = true;
    setSupplierImportPreviewLoading(true);
    setSupplierImportPreviewError('');
    setSupplierImportPreview(null);

    let responseReceived = false;
    try {
      const response = await fetch(
        `${MANAGER_API}?action=supplier_import_preview`,
        {
          method: 'POST',
          credentials: 'include',
          headers: {
            Accept: 'application/json',
            'X-CSRF-Token': csrfToken || '',
          },
          body: formData,
        }
      );
      responseReceived = true;
      const data = await parseSupplierResponse(response);

      if (!response.ok || !data.success || !data.preview) {
        if (response.status === 401 || response.status === 403) {
          setAuthenticated(false);
          setCsrfToken(null);
        }

        if (response.status === 401) {
          throw new Error('Сессия администратора завершена. Войдите снова.');
        }
        if (response.status === 403) {
          throw new Error('Не удалось подтвердить запрос. Войдите снова.');
        }
        if (response.status === 413) {
          throw new Error('Файл слишком большой. Максимальный размер — 2 МБ.');
        }
        if (response.status >= 500) {
          throw new Error('Предварительный просмотр временно недоступен');
        }
        throw new Error(
          typeof data.message === 'string'
            ? data.message
            : 'Не удалось проверить прайс-лист'
        );
      }

      setSupplierImportPreview(data.preview as SupplierImportPreview);
    } catch (err) {
      setSupplierImportPreviewError(
        responseReceived && err instanceof Error
          ? err.message
          : 'Не удалось проверить прайс-лист. Проверьте соединение и повторите попытку.'
      );
    } finally {
      supplierImportPreviewPendingRef.current = false;
      setSupplierImportPreviewLoading(false);
    }
  };

  const loadSupplierImportJobRows = async (
    job: SupplierImportJob,
    page = 1,
    filter = stagedRowsFilter
  ) => {
    setStagedRowsLoading(true);
    setSupplierImportProfilesError('');
    try {
      const response = await fetch(
        `${MANAGER_API}?action=supplier_import_job_rows&job_id=${job.id}&page=${page}&filter=${encodeURIComponent(filter)}`,
        { method: 'GET', credentials: 'include', headers: { Accept: 'application/json' } }
      );
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Не удалось загрузить строки импорта');
      }
      setSelectedImportJob(data.job as SupplierImportJob);
      setStagedRows(Array.isArray(data.rows) ? data.rows : []);
      setStagedRowsPage(Number(data.page) || 1);
      setStagedRowsPages(Number(data.pages) || 1);
      setStagedRowsFilter(filter);
    } catch (err) {
      setSupplierImportProfilesError(
        err instanceof Error ? err.message : 'Не удалось загрузить строки импорта'
      );
    } finally {
      setStagedRowsLoading(false);
    }
  };

  const loadSupplierOfferSummary = async (job: SupplierImportJob) => {
    try {
      const response = await fetch(
        `${MANAGER_API}?action=supplier_import_job_offer_summary&job_id=${job.id}`,
        { method: 'GET', credentials: 'include', headers: { Accept: 'application/json' } }
      );
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success || !data.summary) {
        throw new Error(data.message || 'Не удалось проверить готовность предложений');
      }
      setOfferPublishSummary(data.summary as SupplierOfferPublishSummary);
    } catch (err) {
      setOfferPublishSummary(null);
      setSupplierImportProfilesError(
        err instanceof Error ? err.message : 'Не удалось проверить готовность предложений'
      );
    }
  };

  const loadSupplierOfferPricing = async (job: SupplierImportJob, page = 1) => {
    setOfferPricingLoading(true);
    try {
      const response = await fetch(
        `${MANAGER_API}?action=supplier_offer_pricing_preview&job_id=${job.id}&page=${page}`,
        { method: 'GET', credentials: 'include', headers: { Accept: 'application/json' } }
      );
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Не удалось рассчитать цены');
      }
      setOfferPricingRows(Array.isArray(data.offers) ? data.offers : []);
      setOfferPricingPage(Number(data.page) || 1);
      setOfferPricingPages(Number(data.pages) || 1);
    } catch (err) {
      setSupplierImportProfilesError(err instanceof Error ? err.message : 'Не удалось рассчитать цены');
    } finally {
      setOfferPricingLoading(false);
    }
  };

  const loadPricePublicationHistory = async (page = 1) => {
    setPricePublicationHistoryLoading(true);
    try {
      const response = await fetch(`${MANAGER_API}?action=price_publication_history&page=${page}`, {
        method: 'GET', credentials: 'include', headers: { Accept: 'application/json' },
      });
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось загрузить историю публикаций');
      setPricePublicationHistory(Array.isArray(data.history) ? data.history : []);
      setPricePublicationHistoryPage(Number(data.page) || 1);
      setPricePublicationHistoryPages(Number(data.pages) || 1);
    } catch (err) {
      setPricePublicationError(err instanceof Error ? err.message : 'Не удалось загрузить историю публикаций');
    } finally {
      setPricePublicationHistoryLoading(false);
    }
  };

  const preparePricePublication = async (supplierOfferId: number) => {
    setPricePublicationLoading(true);
    setPricePublicationError('');
    setPricePublicationSuccess('');
    setPricePublicationPreview(null);
    setPricePublicationComment('');
    try {
      const response = await fetch(
        `${MANAGER_API}?action=supplier_offer_price_publish_preview&supplier_offer_id=${supplierOfferId}`,
        { method: 'GET', credentials: 'include', headers: { Accept: 'application/json' } }
      );
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success || !data.preview) {
        throw new Error(data.message || 'Не удалось подготовить изменение цены');
      }
      setPricePublicationPreview(data.preview as PricePublicationPreview);
    } catch (err) {
      setPricePublicationError(err instanceof Error ? err.message : 'Не удалось подготовить изменение цены');
    } finally {
      setPricePublicationLoading(false);
    }
  };

  const publishCandidatePrice = async () => {
    if (!pricePublicationPreview || !pricePublicationPreview.can_publish || pricePublicationPendingRef.current) return;
    if (!window.confirm('Цена будет изменена на сайте. Опубликовать рассчитанный сервером Candidate?')) return;
    pricePublicationPendingRef.current = true;
    setPricePublicationLoading(true);
    setPricePublicationError('');
    setPricePublicationSuccess('');
    try {
      const response = await fetch(MANAGER_API, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken || '' },
        body: JSON.stringify({
          action: 'supplier_offer_price_publish',
          supplier_offer_id: pricePublicationPreview.offer.id,
          snapshot_token: pricePublicationPreview.snapshot_token,
          confirm: true,
          comment: pricePublicationComment.trim() || null,
        }),
      });
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) throw new Error(data.message || 'Не удалось опубликовать цену');
      setPricePublicationSuccess(data.message || 'Цена опубликована');
      await preparePricePublication(pricePublicationPreview.offer.id);
      setPricePublicationSuccess(data.message || 'Цена опубликована');
      await loadPricePublicationHistory(1);
    } catch (err) {
      setPricePublicationError(err instanceof Error ? err.message : 'Не удалось опубликовать цену');
    } finally {
      pricePublicationPendingRef.current = false;
      setPricePublicationLoading(false);
    }
  };

  const openSupplierImportJob = async (job: SupplierImportJob) => {
    setOfferPublishSummary(null);
    setOfferPricingRows([]);
    setPricePublicationPreview(null);
    setPricePublicationError('');
    setPricePublicationSuccess('');
    await loadSupplierImportJobRows(job, 1, 'all');
    await Promise.all([loadSupplierOfferSummary(job), loadSupplierOfferPricing(job, 1), loadPricePublicationHistory(1)]);
  };

  const publishSupplierOffers = async () => {
    if (!selectedImportJob || !offerPublishSummary || offerPublishPendingRef.current) return;
    if (!window.confirm('Будут обновлены предложения поставщика. Цены товаров на сайте не изменятся. Продолжить?')) return;
    offerPublishPendingRef.current = true;
    setOfferPublishLoading(true);
    setSupplierImportProfilesError('');
    try {
      const response = await fetch(MANAGER_API, {
        method: 'POST', credentials: 'include',
        headers: {
          'Content-Type': 'application/json', Accept: 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify({ action: 'supplier_import_job_publish_offers', job_id: selectedImportJob.id }),
      });
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Не удалось обновить предложения поставщика');
      }
      setSupplierImportProfilesSuccess(data.message || 'Предложения поставщика обновлены');
      setOfferPublishSummary(data.summary as SupplierOfferPublishSummary);
      await loadSupplierOfferPricing(selectedImportJob, 1);
    } catch (err) {
      setSupplierImportProfilesError(
        err instanceof Error ? err.message : 'Не удалось обновить предложения поставщика'
      );
    } finally {
      offerPublishPendingRef.current = false;
      setOfferPublishLoading(false);
    }
  };

  const createSupplierStagingImport = async () => {
    if (
      !profileSupplier || !previewProfile || !previewFile || !supplierImportPreview ||
      supplierImportStagePendingRef.current
    ) {
      return;
    }
    const formData = new FormData();
    formData.append('supplier_id', String(profileSupplier.id));
    formData.append('profile_id', String(previewProfile.id));
    formData.append('file', previewFile);
    supplierImportStagePendingRef.current = true;
    setSupplierImportStageLoading(true);
    setSupplierImportPreviewError('');
    try {
      const response = await fetch(`${MANAGER_API}?action=supplier_import_stage`, {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken || '' },
        body: formData,
      });
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success || !data.job_id) {
        if (response.status === 401 || response.status === 403) {
          setAuthenticated(false);
          setCsrfToken(null);
        }
        if (response.status === 413) {
          throw new Error('Файл слишком большой. Максимальный размер — 2 МБ.');
        }
        throw new Error(data.message || 'Не удалось создать staging-импорт');
      }
      setSupplierImportProfilesSuccess(data.message || 'Импорт создан для проверки');
      await loadSupplierImportJobs(profileSupplier);
      const createdJob: SupplierImportJob = {
        id: Number(data.job_id), supplier_id: profileSupplier.id,
        import_profile_id: previewProfile.id,
        original_filename: previewFile.name, profile_name: previewProfile.name,
        status: 'ready_for_review', rows_total: Number(data.counters?.total || 0),
        rows_matched: Number(data.counters?.matched || 0),
        rows_unmatched: Number(data.counters?.unmatched || 0),
        rows_errors: Number(data.counters?.errors || 0),
        created_at: new Date().toISOString(), finished_at: new Date().toISOString(),
      };
      await openSupplierImportJob(createdJob);
    } catch (err) {
      setSupplierImportPreviewError(
        err instanceof Error ? err.message : 'Не удалось создать staging-импорт'
      );
    } finally {
      supplierImportStagePendingRef.current = false;
      setSupplierImportStageLoading(false);
    }
  };

  const searchProductsForImportRow = async (event: React.FormEvent) => {
    event.preventDefault();
    const query = manualProductQuery.trim();
    if (query.length < 2) {
      setSupplierImportProfilesError('Введите минимум 2 символа для поиска товара');
      return;
    }
    setManualMatchLoading(true);
    setSupplierImportProfilesError('');
    try {
      const response = await fetch(
        `${MANAGER_API}?action=supplier_import_product_search&q=${encodeURIComponent(query)}`,
        { method: 'GET', credentials: 'include', headers: { Accept: 'application/json' } }
      );
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Не удалось найти товары');
      }
      setManualProductResults(Array.isArray(data.results) ? data.results : []);
    } catch (err) {
      setSupplierImportProfilesError(err instanceof Error ? err.message : 'Не удалось найти товары');
    } finally {
      setManualMatchLoading(false);
    }
  };

  const setManualImportMatch = async (productId: number, variantId: number | null) => {
    if (!manualMatchRow || !selectedImportJob || manualMatchLoading) return;
    setManualMatchLoading(true);
    setSupplierImportProfilesError('');
    try {
      const response = await fetch(MANAGER_API, {
        method: 'POST', credentials: 'include',
        headers: {
          'Content-Type': 'application/json', Accept: 'application/json',
          'X-CSRF-Token': csrfToken || '',
        },
        body: JSON.stringify({
          action: 'supplier_import_row_set_match', row_id: manualMatchRow.id,
          product_id: productId, product_variant_id: variantId,
        }),
      });
      const data = await parseSupplierResponse(response);
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Не удалось сохранить сопоставление');
      }
      setManualMatchRow(null);
      setManualProductResults([]);
      setManualProductQuery('');
      setSupplierImportProfilesSuccess(data.message || 'Сопоставление сохранено');
      await loadSupplierImportJobRows(selectedImportJob, stagedRowsPage, stagedRowsFilter);
      await loadSupplierOfferSummary(selectedImportJob);
      if (profileSupplier) await loadSupplierImportJobs(profileSupplier);
    } catch (err) {
      setSupplierImportProfilesError(
        err instanceof Error ? err.message : 'Не удалось сохранить сопоставление'
      );
    } finally {
      setManualMatchLoading(false);
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
                                onClick={() => openSupplierImportProfiles(supplier)}
                                className="px-3 py-2 rounded-lg border border-accent-200 text-accent-600 hover:bg-accent-50 transition text-sm"
                              >
                                Профили импорта
                              </button>
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

            <section className="mt-6 bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
              <div className="flex flex-col md:flex-row md:items-start justify-between gap-4">
                <div>
                  <h3 className="text-lg font-semibold text-graphite-900">Правила ценообразования</h3>
                  <p className="text-sm text-gray-500 mt-1">
                    Правила используются только для расчёта рекомендуемой цены. Цена товара на сайте автоматически не изменяется.
                  </p>
                  <p className="text-sm text-amber-700 mt-1">Расчёт на текущем этапе поддерживается только для предложений в RUB.</p>
                </div>
                <button type="button" onClick={openAddPricingRule} className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-accent-500 text-white font-semibold hover:bg-accent-600 transition">
                  <Plus className="w-4 h-4" /> Добавить правило
                </button>
              </div>

              <div className="mt-3 text-xs text-gray-500">
                Меньшее число означает более высокий приоритет. Пересекающиеся активные правила с одинаковым приоритетом и scope могут сделать расчёт неоднозначным — Stage 6 в таком случае не выдаёт Candidate.
              </div>
              {pricingRulesError && <div className="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">{pricingRulesError}</div>}
              {pricingRulesSuccess && <div className="mt-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{pricingRulesSuccess}</div>}

              {showPricingRuleForm && (
                <form onSubmit={savePricingRule} className="mt-5 rounded-xl border border-gray-200 bg-gray-50 p-5">
                  <div className="flex items-center justify-between gap-4 mb-4">
                    <h4 className="font-semibold text-graphite-900">{editingPricingRule ? 'Редактирование правила' : 'Новое правило'}</h4>
                    <button type="button" onClick={closePricingRuleForm} className="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Закрыть форму"><X className="w-5 h-5" /></button>
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <label className="text-sm text-gray-600">Название
                      <input className="admin-input mt-2" required maxLength={255} value={pricingRuleForm.name} onChange={(e) => setPricingRuleForm((v) => ({ ...v, name: e.target.value }))} />
                    </label>
                    <label className="text-sm text-gray-600">Приоритет
                      <input className="admin-input mt-2" required type="number" min="0" max="100000" step="1" value={pricingRuleForm.priority} onChange={(e) => setPricingRuleForm((v) => ({ ...v, priority: e.target.value }))} />
                    </label>
                    <label className="text-sm text-gray-600">Категория (пусто — все)
                      <input className="admin-input mt-2" list="pricing-rule-categories" maxLength={100} value={pricingRuleForm.category_scope} onChange={(e) => setPricingRuleForm((v) => ({ ...v, category_scope: e.target.value }))} />
                      <datalist id="pricing-rule-categories">{pricingCategories.map((category) => <option key={category} value={category} />)}</datalist>
                    </label>
                    <label className="text-sm text-gray-600">Мин. закупочная цена, ₽
                      <input className="admin-input mt-2" inputMode="decimal" placeholder="Без нижней границы" value={pricingRuleForm.purchase_price_min} onChange={(e) => setPricingRuleForm((v) => ({ ...v, purchase_price_min: e.target.value }))} />
                    </label>
                    <label className="text-sm text-gray-600">Макс. закупочная цена, ₽
                      <input className="admin-input mt-2" inputMode="decimal" placeholder="Без верхней границы" value={pricingRuleForm.purchase_price_max} onChange={(e) => setPricingRuleForm((v) => ({ ...v, purchase_price_max: e.target.value }))} />
                    </label>
                    <label className="text-sm text-gray-600">Наценка, %
                      <input className="admin-input mt-2" inputMode="decimal" placeholder="0" value={pricingRuleForm.markup_percent} onChange={(e) => setPricingRuleForm((v) => ({ ...v, markup_percent: e.target.value }))} />
                    </label>
                    <label className="text-sm text-gray-600">Минимальная маржа, ₽
                      <input className="admin-input mt-2" inputMode="decimal" placeholder="0" value={pricingRuleForm.minimum_margin} onChange={(e) => setPricingRuleForm((v) => ({ ...v, minimum_margin: e.target.value }))} />
                    </label>
                    <label className="text-sm text-gray-600">Действует с
                      <input className="admin-input mt-2" type="datetime-local" value={pricingRuleForm.valid_from} onChange={(e) => setPricingRuleForm((v) => ({ ...v, valid_from: e.target.value }))} />
                    </label>
                    <label className="text-sm text-gray-600">Действует до
                      <input className="admin-input mt-2" type="datetime-local" value={pricingRuleForm.valid_until} onChange={(e) => setPricingRuleForm((v) => ({ ...v, valid_until: e.target.value }))} />
                    </label>
                  </div>
                  <div className="mt-4 rounded-lg bg-white border border-gray-200 px-4 py-3 text-sm text-gray-600">Округление: <span className="font-medium">Без дополнительного округления</span>. Дополнительный scope недоступен.</div>
                  <label className="inline-flex items-center gap-3 mt-4 text-sm text-gray-700"><input type="checkbox" checked={pricingRuleForm.is_active} onChange={(e) => setPricingRuleForm((v) => ({ ...v, is_active: e.target.checked }))} /> Активно</label>
                  <div className="flex justify-end gap-3 mt-5">
                    <button type="button" onClick={closePricingRuleForm} className="px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700">Отмена</button>
                    <button type="submit" disabled={savingPricingRule} className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-accent-500 text-white font-semibold disabled:opacity-50"><Save className="w-4 h-4" />{savingPricingRule ? 'Сохранение...' : 'Сохранить'}</button>
                  </div>
                </form>
              )}

              {pricingRulesLoading ? <div className="py-8 text-center text-gray-500">Загрузка правил...</div> : pricingRules.length === 0 ? (
                <div className="mt-5 rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">Правил пока нет. Они не создаются автоматически.</div>
              ) : (
                <div className="mt-5 overflow-x-auto">
                  <table className="w-full min-w-[1050px] text-sm">
                    <thead><tr className="border-b border-gray-200 bg-gray-50">
                      <th className="px-3 py-3 text-left">Название / статус</th><th className="px-3 py-3 text-left">Приоритет</th><th className="px-3 py-3 text-left">Категория</th><th className="px-3 py-3 text-left">Диапазон закупки</th><th className="px-3 py-3 text-left">Наценка</th><th className="px-3 py-3 text-left">Мин. маржа</th><th className="px-3 py-3 text-left">Срок</th><th className="px-3 py-3 text-right">Действия</th>
                    </tr></thead>
                    <tbody>{pricingRules.map((rule) => (
                      <tr key={rule.id} className="border-b border-gray-100 align-top">
                        <td className="px-3 py-3"><div className="font-medium text-graphite-900">{rule.name}</div><div className={rule.is_active ? 'text-green-700' : 'text-gray-500'}>{rule.is_active ? 'Активно' : 'Неактивно'}</div>{rule.warning && <div className="mt-1 text-xs text-amber-700">{rule.warning}</div>}</td>
                        <td className="px-3 py-3 font-mono">{rule.priority}</td>
                        <td className="px-3 py-3">{rule.category_scope || 'Все категории'}</td>
                        <td className="px-3 py-3">{rule.purchase_price_min || '—'} — {rule.purchase_price_max || '—'} ₽</td>
                        <td className="px-3 py-3">{rule.markup_percent ?? '0.0000'}%</td>
                        <td className="px-3 py-3">{rule.minimum_margin ?? '0.00'} ₽</td>
                        <td className="px-3 py-3 text-xs"><div>с {rule.valid_from ? formatDate(rule.valid_from) : 'без ограничения'}</div><div>до {rule.valid_until ? formatDate(rule.valid_until) : 'без ограничения'}</div></td>
                        <td className="px-3 py-3"><div className="flex justify-end gap-2"><button type="button" disabled={!rule.supported_by_stage6} onClick={() => openEditPricingRule(rule)} className="px-3 py-2 rounded-lg border border-gray-200 disabled:opacity-40">Изменить</button><button type="button" disabled={togglingPricingRuleId === rule.id || (!rule.supported_by_stage6 && !rule.is_active)} onClick={() => setPricingRuleActive(rule)} className="px-3 py-2 rounded-lg border border-gray-200 disabled:opacity-40">{rule.is_active ? 'Отключить' : 'Включить'}</button></div></td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              )}
              <div className="mt-4 text-sm font-medium text-green-700">Цена на сайте не изменена.</div>
            </section>

            {profileSupplier && (
              <section className="mt-6 bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                  <div>
                    <div className="text-xs font-semibold uppercase tracking-wide text-accent-600">
                      Профили импорта
                    </div>
                    <h3 className="text-lg font-semibold text-graphite-900 mt-1">
                      {profileSupplier.name}
                    </h3>
                    <p className="text-sm text-gray-500 mt-1">
                      Настройка структуры будущего файла без загрузки и обработки данных.
                    </p>
                  </div>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={openAddSupplierImportProfile}
                      disabled={savingSupplierImportProfile}
                      className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-accent-500 text-white font-semibold hover:bg-accent-600 transition disabled:opacity-50"
                    >
                      <Plus className="w-4 h-4" />
                      Добавить профиль
                    </button>
                    <button
                      type="button"
                      onClick={closeSupplierImportProfiles}
                      disabled={savingSupplierImportProfile}
                      className="p-2.5 rounded-xl border border-gray-200 text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                      aria-label="Закрыть профили импорта"
                    >
                      <X className="w-5 h-5" />
                    </button>
                  </div>
                </div>

                {supplierImportProfilesError && (
                  <div className="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                    {supplierImportProfilesError}
                  </div>
                )}
                {supplierImportProfilesSuccess && (
                  <div className="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                    {supplierImportProfilesSuccess}
                  </div>
                )}

                {previewProfile && (
                  <div className="mb-6 rounded-2xl border border-accent-200 bg-accent-50/40 p-5">
                    <div className="flex items-start justify-between gap-4">
                      <div>
                        <h4 className="font-semibold text-graphite-900">Предварительный просмотр прайс-листа</h4>
                        <p className="mt-1 text-sm text-gray-600">
                          Профиль: {previewProfile.name}. Данные не будут сохранены и не изменят товары, цены или остатки.
                        </p>
                      </div>
                      <button
                        type="button"
                        onClick={closeSupplierImportPreview}
                        disabled={supplierImportPreviewLoading || supplierImportStageLoading}
                        className="p-2 rounded-lg text-gray-500 hover:bg-white disabled:opacity-50"
                        aria-label="Закрыть предварительный просмотр"
                      >
                        <X className="w-5 h-5" />
                      </button>
                    </div>

                    <form onSubmit={requestSupplierImportPreview} className="mt-5 flex flex-col md:flex-row md:items-end gap-4">
                      <div className="flex-1">
                        <label className="block text-sm text-gray-600 mb-2">Прайс-лист CSV, XLS или XLSX — до 2 МБ</label>
                        <input
                          key={previewFileInputKey}
                          type="file"
                          accept=".csv,.xls,.xlsx"
                          disabled={supplierImportPreviewLoading || supplierImportStageLoading}
                          onChange={(event) => {
                            setPreviewFile(event.target.files?.[0] || null);
                            setSupplierImportPreview(null);
                            setSupplierImportPreviewError('');
                          }}
                          className="block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 file:mr-4 file:rounded-lg file:border-0 file:bg-accent-100 file:px-3 file:py-2 file:text-accent-700"
                        />
                        {previewFile && (
                          <div className="mt-2 text-xs text-gray-500">Выбран файл: {previewFile.name}</div>
                        )}
                      </div>
                      <button
                        type="submit"
                        disabled={!previewFile || supplierImportPreviewLoading || supplierImportStageLoading}
                        className="px-5 py-2.5 rounded-xl bg-accent-500 text-white font-semibold hover:bg-accent-600 disabled:opacity-50"
                      >
                        {supplierImportPreviewLoading ? 'Проверка...' : 'Показать предварительный просмотр'}
                      </button>
                    </form>

                    {supplierImportPreviewError && (
                      <div className="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
                        {supplierImportPreviewError}
                      </div>
                    )}

                    {supplierImportPreview && (
                      <div className="mt-6">
                        <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                          <div className="rounded-xl bg-white border border-gray-200 p-3">
                            <div className="text-xs text-gray-500">Формат</div>
                            <div className="mt-1 font-semibold uppercase">{supplierImportPreview.format}</div>
                          </div>
                          <div className="rounded-xl bg-white border border-gray-200 p-3">
                            <div className="text-xs text-gray-500">Лист</div>
                            <div className="mt-1 font-semibold break-all">{supplierImportPreview.sheet_name}</div>
                          </div>
                          <div className="rounded-xl bg-white border border-gray-200 p-3">
                            <div className="text-xs text-gray-500">Просканировано</div>
                            <div className="mt-1 font-semibold">{supplierImportPreview.rows_scanned}</div>
                          </div>
                          <div className="rounded-xl bg-white border border-gray-200 p-3">
                            <div className="text-xs text-gray-500">Пропущено</div>
                            <div className="mt-1 font-semibold">{supplierImportPreview.rows_skipped}</div>
                          </div>
                          <div className="rounded-xl bg-white border border-gray-200 p-3">
                            <div className="text-xs text-gray-500">С ошибками</div>
                            <div className="mt-1 font-semibold text-red-600">{supplierImportPreview.rows_with_errors}</div>
                          </div>
                        </div>
                        <div className="mt-3 text-xs text-gray-500">
                          Файл: {supplierImportPreview.original_filename}
                          {supplierImportPreview.preview_truncated && ' · Показаны первые 100 строк'}
                        </div>
                        <div className="mt-4 flex flex-col md:flex-row md:items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                          <p className="text-sm text-amber-900">
                            Будет создан импорт для проверки и сопоставления. Товары, цены и остатки на сайте не изменятся.
                          </p>
                          <button
                            type="button"
                            onClick={createSupplierStagingImport}
                            disabled={supplierImportStageLoading}
                            className="shrink-0 px-5 py-2.5 rounded-xl bg-amber-600 text-white font-semibold hover:bg-amber-700 disabled:opacity-50"
                          >
                            {supplierImportStageLoading ? 'Создание импорта...' : 'Сохранить в staging'}
                          </button>
                        </div>

                        <div className="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white">
                          <table className="min-w-full text-sm">
                            <thead className="bg-gray-50 border-b border-gray-200">
                              <tr>
                                <th className="px-3 py-3 text-left text-xs font-semibold text-gray-500">Строка</th>
                                {supplierImportMappingFields
                                  .filter(({ key }) => Boolean(supplierImportPreview.mapping[key]))
                                  .map(({ key, label }) => (
                                    <th key={key} className="px-3 py-3 text-left text-xs font-semibold text-gray-500">
                                      <div>{label}</div>
                                      <div className="mt-1 font-normal text-gray-400">
                                        {supplierImportPreview.mapping[key]}
                                        {supplierImportPreview.detected_headers[key]
                                          ? ` · ${supplierImportPreview.detected_headers[key]}`
                                          : ''}
                                      </div>
                                    </th>
                                  ))}
                                <th className="px-3 py-3 text-left text-xs font-semibold text-gray-500">Проверка</th>
                              </tr>
                            </thead>
                            <tbody>
                              {supplierImportPreview.rows.map((row) => (
                                <tr key={row.source_row_number} className="border-b border-gray-100 last:border-0 align-top">
                                  <td className="px-3 py-3 font-mono text-gray-500">{row.source_row_number}</td>
                                  {supplierImportMappingFields
                                    .filter(({ key }) => Boolean(supplierImportPreview.mapping[key]))
                                    .map(({ key }) => {
                                      const rawValue = row.values[key] ?? '';
                                      const normalizedValue = row.normalized[key];
                                      return (
                                        <td key={key} className="px-3 py-3 max-w-[260px] break-words text-gray-700">
                                          <div>{rawValue || '—'}</div>
                                          {normalizedValue !== undefined &&
                                            normalizedValue !== null &&
                                            normalizedValue !== rawValue && (
                                              <div className="mt-1 text-xs text-emerald-700">→ {normalizedValue}</div>
                                            )}
                                        </td>
                                      );
                                    })}
                                  <td className="px-3 py-3 min-w-[240px]">
                                    {row.errors.map((message) => (
                                      <div key={`error-${message}`} className="text-xs text-red-600">Ошибка: {message}</div>
                                    ))}
                                    {row.warnings.map((message) => (
                                      <div key={`warning-${message}`} className="text-xs text-amber-700">Предупреждение: {message}</div>
                                    ))}
                                    {row.errors.length === 0 && row.warnings.length === 0 && (
                                      <span className="text-xs text-emerald-700">Без замечаний</span>
                                    )}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {showSupplierImportProfileForm && (
                  <form
                    onSubmit={saveSupplierImportProfile}
                    className="mb-6 rounded-2xl border border-gray-200 bg-gray-50 p-5"
                  >
                    <div className="flex items-center justify-between gap-4 mb-5">
                      <div>
                        <h4 className="font-semibold text-graphite-900">
                          {editingSupplierImportProfileId
                            ? 'Редактирование профиля'
                            : 'Новый профиль импорта'}
                        </h4>
                        <p className="text-sm text-gray-500 mt-1">
                          Укажите названия или буквенные обозначения колонок будущего листа.
                        </p>
                      </div>
                      <button
                        type="button"
                        onClick={closeSupplierImportProfileForm}
                        disabled={savingSupplierImportProfile}
                        className="p-2 rounded-lg text-gray-500 hover:bg-white disabled:opacity-50"
                        aria-label="Закрыть форму профиля"
                      >
                        <X className="w-5 h-5" />
                      </button>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                      <div>
                        <label className="block text-sm text-gray-600 mb-2">Название профиля</label>
                        <input
                          type="text"
                          value={supplierImportProfileForm.name}
                          onChange={(event) =>
                            setSupplierImportProfileForm((current) => ({
                              ...current,
                              name: event.target.value,
                            }))
                          }
                          maxLength={255}
                          required
                          className="admin-input"
                          placeholder="Основной прайс-лист"
                        />
                      </div>
                      <div>
                        <label className="block text-sm text-gray-600 mb-2">Название листа</label>
                        <input
                          type="text"
                          value={supplierImportProfileForm.sheet_name}
                          onChange={(event) =>
                            setSupplierImportProfileForm((current) => ({
                              ...current,
                              sheet_name: event.target.value,
                            }))
                          }
                          maxLength={255}
                          className="admin-input"
                          placeholder="Пусто — первый лист"
                        />
                      </div>
                      <div>
                        <label className="block text-sm text-gray-600 mb-2">Строка заголовков</label>
                        <input
                          type="number"
                          min="0"
                          max="1048576"
                          step="1"
                          value={supplierImportProfileForm.header_row_number}
                          onChange={(event) =>
                            setSupplierImportProfileForm((current) => ({
                              ...current,
                              header_row_number: event.target.value,
                            }))
                          }
                          required
                          className="admin-input"
                        />
                        <div className="text-xs text-gray-500 mt-1">0 — заголовков нет</div>
                      </div>
                    </div>

                    <div className="mt-6">
                      <h5 className="text-sm font-semibold text-graphite-900 mb-3">Карта колонок</h5>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {supplierImportMappingFields.map(({ key, label }) => (
                          <div key={key}>
                            <label className="block text-sm text-gray-600 mb-2">{label}</label>
                            <input
                              type="text"
                              value={supplierImportProfileForm.column_mapping[key] || ''}
                              onChange={(event) =>
                                setSupplierImportProfileForm((current) => ({
                                  ...current,
                                  column_mapping: {
                                    ...current.column_mapping,
                                    [key]: event.target.value,
                                  },
                                }))
                              }
                              maxLength={50}
                              className="admin-input"
                              placeholder="Например: A или Артикул"
                            />
                          </div>
                        ))}
                      </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                      <div>
                        <label className="block text-sm text-gray-600 mb-2">Разделитель дробной части</label>
                        <select
                          value={supplierImportProfileForm.decimal_separator}
                          onChange={(event) =>
                            setSupplierImportProfileForm((current) => ({
                              ...current,
                              decimal_separator: event.target.value as '.' | ',',
                            }))
                          }
                          className="admin-input"
                        >
                          <option value=".">Точка</option>
                          <option value=",">Запятая</option>
                        </select>
                      </div>
                      <div>
                        <label className="block text-sm text-gray-600 mb-2">Валюта по умолчанию</label>
                        <input
                          type="text"
                          value={supplierImportProfileForm.default_currency_code}
                          onChange={(event) =>
                            setSupplierImportProfileForm((current) => ({
                              ...current,
                              default_currency_code: event.target.value.toUpperCase(),
                            }))
                          }
                          minLength={3}
                          maxLength={3}
                          pattern="[A-Za-z]{3}"
                          className="admin-input"
                          placeholder="RUB"
                        />
                      </div>
                      <div>
                        <label className="block text-sm text-gray-600 mb-2">Формат даты поступления</label>
                        <select
                          value={supplierImportProfileForm.arrival_date_format}
                          onChange={(event) => setSupplierImportProfileForm((current) => ({
                            ...current,
                            arrival_date_format: event.target.value as typeof current.arrival_date_format,
                          }))}
                          className="admin-input"
                        >
                          <option value="">Не разбирать дату</option>
                          <option value="dmy_dot">ДД.ММ.ГГГГ</option>
                          <option value="ymd_dash">ГГГГ-ММ-ДД</option>
                          <option value="dmy_slash">ДД/ММ/ГГГГ</option>
                        </select>
                      </div>
                    </div>

                    <div className="flex flex-wrap gap-5 mt-5 text-sm text-gray-700">
                      <label className="inline-flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={supplierImportProfileForm.trim_values}
                          onChange={(event) =>
                            setSupplierImportProfileForm((current) => ({
                              ...current,
                              trim_values: event.target.checked,
                            }))
                          }
                        />
                        Убирать пробелы по краям значений
                      </label>
                      <label className="inline-flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={supplierImportProfileForm.skip_empty_rows}
                          onChange={(event) =>
                            setSupplierImportProfileForm((current) => ({
                              ...current,
                              skip_empty_rows: event.target.checked,
                            }))
                          }
                        />
                        Пропускать пустые строки
                      </label>
                      <label className="inline-flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={supplierImportProfileForm.is_active}
                          onChange={(event) =>
                            setSupplierImportProfileForm((current) => ({
                              ...current,
                              is_active: event.target.checked,
                            }))
                          }
                        />
                        Профиль активен
                      </label>
                    </div>

                    <div className="flex justify-end gap-3 mt-6">
                      <button
                        type="button"
                        onClick={closeSupplierImportProfileForm}
                        disabled={savingSupplierImportProfile}
                        className="px-5 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                      >
                        Отмена
                      </button>
                      <button
                        type="submit"
                        disabled={savingSupplierImportProfile}
                        className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-accent-500 text-white font-semibold hover:bg-accent-600 disabled:opacity-50"
                      >
                        <Save className="w-4 h-4" />
                        {savingSupplierImportProfile ? 'Сохранение...' : 'Сохранить профиль'}
                      </button>
                    </div>
                  </form>
                )}

                {supplierImportProfilesLoading ? (
                  <div className="py-10 text-center text-gray-500">Загрузка профилей...</div>
                ) : supplierImportProfiles.length === 0 ? (
                  <div className="rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-500">
                    У поставщика пока нет профилей импорта.
                  </div>
                ) : (
                  <div className="overflow-x-auto rounded-xl border border-gray-200">
                    <table className="w-full min-w-[900px]">
                      <thead className="bg-gray-50 border-b border-gray-200">
                        <tr>
                          <th className="text-left px-4 py-3 text-xs font-semibold uppercase text-gray-500">Профиль</th>
                          <th className="text-left px-4 py-3 text-xs font-semibold uppercase text-gray-500">Лист / заголовки</th>
                          <th className="text-left px-4 py-3 text-xs font-semibold uppercase text-gray-500">Mapping</th>
                          <th className="text-left px-4 py-3 text-xs font-semibold uppercase text-gray-500">Статус</th>
                          <th className="text-left px-4 py-3 text-xs font-semibold uppercase text-gray-500">Изменён</th>
                          <th className="text-right px-4 py-3 text-xs font-semibold uppercase text-gray-500">Действия</th>
                        </tr>
                      </thead>
                      <tbody>
                        {supplierImportProfiles.map((profile) => {
                          const mappedFields = supplierImportMappingFields.filter(
                            ({ key }) => Boolean(profile.column_mapping[key])
                          );

                          return (
                            <tr key={profile.id} className="border-b border-gray-100 last:border-b-0">
                              <td className="px-4 py-4">
                                <div className="font-semibold text-graphite-900">{profile.name}</div>
                                <div className="text-xs text-gray-500 mt-1">Создан: {formatDate(profile.created_at)}</div>
                              </td>
                              <td className="px-4 py-4 text-sm text-gray-600">
                                <div>{profile.sheet_name || 'Первый лист'}</div>
                                <div className="text-xs text-gray-500 mt-1">Строка: {profile.header_row_number}</div>
                              </td>
                              <td className="px-4 py-4 text-sm text-gray-600">
                                {mappedFields.length > 0
                                  ? `${mappedFields.length} полей: ${mappedFields
                                      .slice(0, 3)
                                      .map(({ label }) => label)
                                      .join(', ')}${mappedFields.length > 3 ? '…' : ''}`
                                  : 'Колонки не заданы'}
                              </td>
                              <td className="px-4 py-4">
                                <span className={`inline-flex px-2.5 py-1 rounded-full border text-xs ${
                                  profile.is_active
                                    ? 'bg-green-50 text-green-700 border-green-200'
                                    : 'bg-gray-50 text-gray-600 border-gray-200'
                                }`}>
                                  {profile.is_active ? 'Активен' : 'Неактивен'}
                                </span>
                              </td>
                              <td className="px-4 py-4 text-sm text-gray-500">{formatDate(profile.updated_at)}</td>
                              <td className="px-4 py-4">
                                <div className="flex justify-end gap-2">
                                  <button
                                    type="button"
                                    onClick={() => { resetAvailabilityMappingForm(); loadAvailabilityMappings(profile); }}
                                    className="px-3 py-2 rounded-lg border border-blue-200 text-blue-700 text-sm"
                                  >
                                    Наличие
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => openSupplierImportPreview(profile)}
                                    disabled={!profile.is_active || supplierImportPreviewLoading}
                                    className="px-3 py-2 rounded-lg border border-accent-200 text-accent-600 hover:bg-accent-50 disabled:opacity-50 text-sm"
                                    title={profile.is_active ? undefined : 'Для проверки активируйте профиль'}
                                  >
                                    Проверить прайс
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => openEditSupplierImportProfile(profile)}
                                    disabled={savingSupplierImportProfile}
                                    className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                                  >
                                    <Pencil className="w-4 h-4" />
                                    Изменить
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => setSupplierImportProfileActive(profile)}
                                    disabled={togglingSupplierImportProfileId === profile.id}
                                    className={`px-3 py-2 rounded-lg border text-sm disabled:opacity-50 ${
                                      profile.is_active
                                        ? 'border-red-200 text-red-600 hover:bg-red-50'
                                        : 'border-green-200 text-green-700 hover:bg-green-50'
                                    }`}
                                  >
                                    {profile.is_active ? 'Отключить' : 'Включить'}
                                  </button>
                                </div>
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}

                {availabilityProfile && (
                  <div className="mt-6 rounded-2xl border border-blue-200 bg-blue-50/30 p-5">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <h4 className="font-semibold text-graphite-900">Правила наличия · {availabilityProfile.name}</h4>
                        <p className="text-sm text-gray-500 mt-1">Точное profile-specific сопоставление. Неизвестные значения остаются unknown.</p>
                      </div>
                      <button type="button" onClick={() => setAvailabilityProfile(null)} aria-label="Закрыть"><X className="w-5 h-5" /></button>
                    </div>
                    {availabilityMappingError && <div className="mt-3 text-sm text-red-700">{availabilityMappingError}</div>}
                    <form onSubmit={saveAvailabilityMapping} className="mt-4 grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                      <label className="text-sm text-gray-600 md:col-span-2">Точное значение поставщика
                        <input required maxLength={191} value={availabilityMappingForm.raw_value} onChange={(event) => setAvailabilityMappingForm((current) => ({ ...current, raw_value: event.target.value }))} className="admin-input mt-1" />
                      </label>
                      <label className="text-sm text-gray-600">Canonical status
                        <select value={availabilityMappingForm.normalized_status} onChange={(event) => setAvailabilityMappingForm((current) => ({ ...current, normalized_status: event.target.value as Exclude<SupplierAvailabilityStatus, 'unknown'> }))} className="admin-input mt-1">
                          <option value="in_stock">В наличии</option><option value="out_of_stock">Нет в наличии</option><option value="expected">Ожидается</option>
                        </select>
                      </label>
                      <button className="px-4 py-2.5 rounded-xl bg-blue-600 text-white font-semibold disabled:opacity-50" disabled={availabilityMappingsLoading}>{availabilityMappingForm.id ? 'Сохранить' : 'Добавить mapping'}</button>
                    </form>
                    {availabilityMappingForm.id && <button type="button" onClick={resetAvailabilityMappingForm} className="mt-2 text-xs text-gray-600">Отменить редактирование</button>}
                    {availabilityMappingsLoading ? <div className="py-5 text-center text-gray-500">Загрузка...</div> : (
                      <div className="mt-4 space-y-2">{availabilityMappings.map((mapping) => (
                        <div key={mapping.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-white p-3 text-sm">
                          <div><span className="font-medium">{mapping.raw_value}</span> → {mapping.normalized_status} · {mapping.is_active ? 'активно' : 'отключено'}</div>
                          <div className="flex gap-2"><button type="button" onClick={() => setAvailabilityMappingForm({ id: mapping.id, updated_at: mapping.updated_at, raw_value: mapping.raw_value, normalized_status: mapping.normalized_status, is_active: mapping.is_active })} className="px-3 py-1.5 border rounded-lg">Изменить</button><button type="button" onClick={() => toggleAvailabilityMapping(mapping)} className="px-3 py-1.5 border rounded-lg">{mapping.is_active ? 'Отключить' : 'Включить'}</button></div>
                        </div>
                      ))}{availabilityMappings.length === 0 && <div className="text-sm text-gray-500">Правила пока не созданы.</div>}</div>
                    )}
                  </div>
                )}

                <div className="mt-8 border-t border-gray-200 pt-6">
                  <div className="flex items-center justify-between gap-3 mb-4">
                    <div>
                      <h4 className="font-semibold text-graphite-900">Импорты</h4>
                      <p className="text-sm text-gray-500 mt-1">Staging-история без применения цен и остатков к сайту.</p>
                    </div>
                    <button
                      type="button"
                      onClick={() => profileSupplier && loadSupplierImportJobs(profileSupplier)}
                      disabled={supplierImportJobsLoading}
                      className="px-3 py-2 rounded-lg border border-gray-200 text-sm text-gray-600 disabled:opacity-50"
                    >
                      Обновить
                    </button>
                  </div>
                  {supplierImportJobsLoading ? (
                    <div className="py-6 text-center text-gray-500">Загрузка импортов...</div>
                  ) : supplierImportJobs.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-gray-300 p-6 text-center text-gray-500">Импортов пока нет.</div>
                  ) : (
                    <div className="overflow-x-auto rounded-xl border border-gray-200">
                      <table className="w-full min-w-[850px] text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200"><tr>
                          <th className="px-3 py-3 text-left">ID / файл</th><th className="px-3 py-3 text-left">Профиль / дата</th>
                          <th className="px-3 py-3 text-left">Статус</th><th className="px-3 py-3 text-left">Всего</th>
                          <th className="px-3 py-3 text-left">Сопоставлено</th><th className="px-3 py-3 text-left">Без связи</th>
                          <th className="px-3 py-3 text-left">Ошибки</th>
                        </tr></thead>
                        <tbody>{supplierImportJobs.map((job) => (
                          <tr key={job.id} className="border-b border-gray-100 last:border-0 hover:bg-gray-50 cursor-pointer" onClick={() => openSupplierImportJob(job)}>
                            <td className="px-3 py-3"><div className="font-semibold">#{job.id}</div><div className="text-xs text-gray-500 break-all">{job.original_filename}</div></td>
                            <td className="px-3 py-3"><div>{job.profile_name || 'Профиль удалён'}</div><div className="text-xs text-gray-500">{formatDate(job.created_at)}</div></td>
                            <td className="px-3 py-3">{job.status}</td><td className="px-3 py-3">{job.rows_total}</td>
                            <td className="px-3 py-3 text-emerald-700">{job.rows_matched}</td><td className="px-3 py-3 text-amber-700">{job.rows_unmatched}</td>
                            <td className="px-3 py-3 text-red-600">{job.rows_errors}</td>
                          </tr>
                        ))}</tbody>
                      </table>
                    </div>
                  )}
                </div>

                {selectedImportJob && (
                  <div className="mt-6 rounded-2xl border border-gray-200 p-5">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                      <div><h4 className="font-semibold text-graphite-900">Проверка импорта #{selectedImportJob.id}</h4><p className="text-xs text-gray-500 mt-1">{selectedImportJob.original_filename}</p></div>
                      <div className="flex flex-wrap gap-2">
                        {[
                          ['all', 'Все'], ['matched', 'Сопоставленные'], ['unmatched', 'Без связи'], ['review', 'Проверка / ошибки'],
                        ].map(([value, label]) => (
                          <button key={value} type="button" onClick={() => loadSupplierImportJobRows(selectedImportJob, 1, value)} className={`px-3 py-2 rounded-lg border text-xs ${stagedRowsFilter === value ? 'border-accent-300 bg-accent-50 text-accent-700' : 'border-gray-200 text-gray-600'}`}>{label}</button>
                        ))}
                      </div>
                    </div>
                    {offerPublishSummary && (
                      <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <div className="grid grid-cols-2 md:grid-cols-6 gap-3 text-sm">
                          <div><div className="text-xs text-gray-500">Готово</div><div className="font-semibold text-emerald-700">{offerPublishSummary.eligible_rows}</div></div>
                          <div><div className="text-xs text-gray-500">Будет создано</div><div className="font-semibold">{offerPublishSummary.offers_to_create}</div></div>
                          <div><div className="text-xs text-gray-500">Будет обновлено</div><div className="font-semibold">{offerPublishSummary.offers_to_update}</div></div>
                          <div><div className="text-xs text-gray-500">Ошибки</div><div className="font-semibold text-red-600">{offerPublishSummary.skipped_errors + offerPublishSummary.skipped_invalid_price + offerPublishSummary.skipped_invalid_currency}</div></div>
                          <div><div className="text-xs text-gray-500">Без match</div><div className="font-semibold text-amber-700">{offerPublishSummary.skipped_unmatched}</div></div>
                          <div><div className="text-xs text-gray-500">Без variant</div><div className="font-semibold text-amber-700">{offerPublishSummary.skipped_no_variant}</div></div>
                        </div>
                        {(offerPublishSummary.skipped_missing_sku > 0 || offerPublishSummary.skipped_duplicate_sku > 0 || offerPublishSummary.skipped_missing_name > 0 || offerPublishSummary.skipped_offer_conflict > 0 || offerPublishSummary.skipped_stale_source > 0 || offerPublishSummary.skipped_unknown_source > 0) && (
                          <p className="mt-3 text-xs text-amber-800">
                            Дополнительно пропущено: уже есть более новое предложение поставщика — {offerPublishSummary.skipped_stale_source}, источник существующего предложения неизвестен — {offerPublishSummary.skipped_unknown_source}, конфликт варианта — {offerPublishSummary.skipped_offer_conflict}, без SKU — {offerPublishSummary.skipped_missing_sku}, повторяющийся SKU — {offerPublishSummary.skipped_duplicate_sku}, без названия — {offerPublishSummary.skipped_missing_name}.
                          </p>
                        )}
                        <div className="mt-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
                          <p className="text-sm font-medium text-amber-900">Будут обновлены предложения поставщика. Цены товаров на сайте не изменятся.</p>
                          <button type="button" onClick={publishSupplierOffers} disabled={offerPublishLoading || offerPublishSummary.eligible_rows === 0} className="shrink-0 px-4 py-2.5 rounded-xl bg-amber-600 text-white font-semibold disabled:opacity-50">
                            {offerPublishLoading ? 'Обновление...' : 'Обновить предложения поставщика'}
                          </button>
                        </div>
                      </div>
                    )}
                    {stagedRowsLoading ? <div className="py-8 text-center text-gray-500">Загрузка строк...</div> : (
                      <div className="mt-4 overflow-x-auto rounded-xl border border-gray-200">
                        <table className="w-full min-w-[1200px] text-sm"><thead className="bg-gray-50"><tr>
                          <th className="px-3 py-3 text-left">Строка</th><th className="px-3 py-3 text-left">SKU</th><th className="px-3 py-3 text-left">Товар / модель</th>
                          <th className="px-3 py-3 text-left">Цена</th><th className="px-3 py-3 text-left">Наличие / поступление</th><th className="px-3 py-3 text-left">Статус / замечания</th><th className="px-3 py-3 text-left">Связь</th>
                        </tr></thead><tbody>{stagedRows.map((row) => (
                          <tr key={row.id} className="border-t border-gray-100 align-top">
                            <td className="px-3 py-3 font-mono">{row.source_row_number}</td><td className="px-3 py-3">{row.supplier_sku || '—'}</td>
                            <td className="px-3 py-3"><div>{row.raw_product_name || '—'}</div><div className="text-xs text-gray-500">{row.normalized_model || '—'}</div></td>
                            <td className="px-3 py-3">{row.purchase_price ?? '—'} {row.currency_code || ''}</td>
                            <td className="px-3 py-3">
                              <div>Raw: {row.raw_availability || '—'}</div>
                              <div className="font-medium">Canonical: {supplierAvailabilityLabels[row.availability_normalization.status]}</div>
                              <div className="text-xs text-gray-500">Поступление raw: {row.raw_arrival_info || '—'} · parsed: {row.availability_normalization.expected_arrival_at?.slice(0, 10) || '—'}</div>
                              <div className="text-xs text-gray-500">Количество: {row.availability_normalization.stock_quantity ?? 'неизвестно'}</div>
                              {row.availability_normalization.warnings.map((warning) => <div key={warning.code} className="text-xs text-amber-700">{warning.message}</div>)}
                            </td>
                            <td className="px-3 py-3"><div className="font-medium">{row.status}</div>{row.errors.map((message) => <div key={message} className="text-xs text-red-600">{message}</div>)}{row.warnings.map((message) => <div key={message} className="text-xs text-amber-700">{message}</div>)}</td>
                            <td className="px-3 py-3"><div>{row.matched_product_name || 'Не сопоставлено'}</div><div className="text-xs text-gray-500">{row.matched_variant_name || row.matched_variant_key || ''}</div>{row.status !== 'validation_error' && <button type="button" onClick={() => { setManualMatchRow(row); setManualProductQuery(row.normalized_model || row.raw_product_name || ''); setManualProductResults([]); }} className="mt-2 px-3 py-1.5 rounded-lg border border-accent-200 text-accent-700 text-xs">Сопоставить</button>}</td>
                          </tr>
                        ))}</tbody></table>
                      </div>
                    )}
                    <div className="mt-4 flex items-center justify-center gap-3"><button type="button" disabled={stagedRowsPage <= 1 || stagedRowsLoading} onClick={() => loadSupplierImportJobRows(selectedImportJob, stagedRowsPage - 1, stagedRowsFilter)} className="px-3 py-2 border rounded-lg disabled:opacity-40">Назад</button><span className="text-sm text-gray-500">{stagedRowsPage} / {stagedRowsPages}</span><button type="button" disabled={stagedRowsPage >= stagedRowsPages || stagedRowsLoading} onClick={() => loadSupplierImportJobRows(selectedImportJob, stagedRowsPage + 1, stagedRowsFilter)} className="px-3 py-2 border rounded-lg disabled:opacity-40">Далее</button></div>

                    <div className="mt-8 border-t border-gray-200 pt-5">
                      <div><h5 className="font-semibold text-graphite-900">Расчёт цен</h5><p className="text-sm text-gray-500 mt-1">Только серверный preview. Цена на сайте не изменена.</p></div>
                      {offerPricingLoading ? <div className="py-6 text-center text-gray-500">Расчёт цен...</div> : offerPricingRows.length === 0 ? <div className="mt-4 rounded-xl border border-dashed border-gray-300 p-5 text-center text-gray-500">Предложения из этого import job ещё не опубликованы.</div> : (
                        <div className="mt-4 overflow-x-auto rounded-xl border border-gray-200"><table className="w-full min-w-[1250px] text-sm"><thead className="bg-gray-50"><tr>
                          <th className="px-3 py-3 text-left">Поставщик / вариант</th><th className="px-3 py-3 text-left">Закупка</th><th className="px-3 py-3 text-left">Наличие</th><th className="px-3 py-3 text-left">Правило</th><th className="px-3 py-3 text-left">До округления</th><th className="px-3 py-3 text-left">Candidate</th><th className="px-3 py-3 text-left">Маржа</th><th className="px-3 py-3 text-left">Предупреждения</th><th className="px-3 py-3 text-right">Публикация</th>
                        </tr></thead><tbody>{offerPricingRows.map((offer) => <tr key={offer.id} className="border-t border-gray-100 align-top">
                          <td className="px-3 py-3"><div>{offer.supplier_name}</div><div className="font-medium">{offer.product_name}</div><div className="text-xs text-gray-500">{offer.variant_name || offer.variant_key} · row #{offer.source_import_row_id}</div></td>
                          <td className="px-3 py-3">{offer.purchase_price} {offer.currency_code}</td><td className="px-3 py-3"><div>{supplierAvailabilityLabels[(offer.availability_status in supplierAvailabilityLabels ? offer.availability_status : 'unknown') as SupplierAvailabilityStatus]}</div><div className="text-xs text-gray-500">Количество: {offer.stock_quantity ?? 'неизвестно'} · ETA: {offer.expected_arrival_at?.slice(0, 10) || '—'}</div><div className="text-xs text-gray-500">Raw: {offer.raw_availability || '—'} · {offer.raw_arrival_info || '—'}</div><div className="text-xs text-gray-500">{offer.delivery_info || '—'}</div></td>
                          <td className="px-3 py-3"><div>{offer.pricing.rule?.name || '—'}</div>{offer.pricing.rule?.markup_percent !== null && offer.pricing.rule?.markup_percent !== undefined && <div className="text-xs text-gray-500">Наценка: {offer.pricing.rule.markup_percent}%</div>}</td><td className="px-3 py-3">{offer.pricing.price_before_rounding || '—'}</td><td className="px-3 py-3 font-semibold">{offer.pricing.candidate_retail_price || '—'}</td>
                          <td className="px-3 py-3">{offer.pricing.expected_margin ? `${offer.pricing.expected_margin} ₽ · ${offer.pricing.expected_margin_percent}%` : '—'}</td><td className="px-3 py-3">{offer.pricing.warnings.map((warning) => <div key={warning} className="text-xs text-amber-700">{warning}</div>)}</td>
                          <td className="px-3 py-3 text-right"><button type="button" disabled={!offer.pricing.calculable || pricePublicationLoading} onClick={() => preparePricePublication(offer.id)} className="px-3 py-2 rounded-lg border border-red-200 text-red-700 disabled:opacity-40">Подготовить изменение цены</button></td>
                        </tr>)}</tbody></table></div>
                      )}
                      {offerPricingRows.length > 0 && <div className="mt-4 flex items-center justify-center gap-3"><button type="button" disabled={offerPricingPage <= 1 || offerPricingLoading} onClick={() => loadSupplierOfferPricing(selectedImportJob, offerPricingPage - 1)} className="px-3 py-2 border rounded-lg disabled:opacity-40">Назад</button><span className="text-sm text-gray-500">{offerPricingPage} / {offerPricingPages}</span><button type="button" disabled={offerPricingPage >= offerPricingPages || offerPricingLoading} onClick={() => loadSupplierOfferPricing(selectedImportJob, offerPricingPage + 1)} className="px-3 py-2 border rounded-lg disabled:opacity-40">Далее</button></div>}

                      {(pricePublicationLoading || pricePublicationError || pricePublicationPreview) && (
                        <div className="mt-6 rounded-2xl border-2 border-red-200 bg-red-50/40 p-5">
                          <div className="flex items-start justify-between gap-4"><div><h5 className="text-lg font-semibold text-red-900">Подтверждение публикации цены</h5><p className="mt-1 text-sm font-medium text-red-700">Цена будет изменена на сайте.</p></div>{pricePublicationPreview && <button type="button" onClick={() => setPricePublicationPreview(null)} aria-label="Закрыть"><X className="w-5 h-5" /></button>}</div>
                          {pricePublicationLoading && !pricePublicationPreview && <div className="py-6 text-center text-gray-500">Повторная серверная проверка...</div>}
                          {pricePublicationError && <div className="mt-4 rounded-xl border border-red-200 bg-white px-4 py-3 text-sm text-red-700">{pricePublicationError}</div>}
                          {pricePublicationSuccess && <div className="mt-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{pricePublicationSuccess}</div>}
                          {pricePublicationPreview && (
                            <div className="mt-5">
                              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                                <div className="rounded-xl bg-white border p-3"><div className="text-xs text-gray-500">Товар / вариант</div><div className="font-semibold">{pricePublicationPreview.product.name}</div><div>{pricePublicationPreview.variant.display_name || pricePublicationPreview.variant.assembly_country || pricePublicationPreview.variant.variant_key}</div></div>
                                <div className="rounded-xl bg-white border p-3"><div className="text-xs text-gray-500">Поставщик / SKU</div><div className="font-semibold">{pricePublicationPreview.offer.supplier_name}</div><div>{pricePublicationPreview.offer.supplier_sku || '—'}</div></div>
                                <div className="rounded-xl bg-white border-2 border-gray-300 p-3"><div className="text-xs font-semibold text-gray-500">ТЕКУЩАЯ ЦЕНА</div><div className="text-xl font-bold">{pricePublicationPreview.variant.current_live_price || '—'} ₽</div></div>
                                <div className="rounded-xl bg-red-100 border-2 border-red-300 p-3"><div className="text-xs font-semibold text-red-700">НОВАЯ ЦЕНА</div><div className="text-xl font-bold text-red-900">{pricePublicationPreview.pricing.candidate_retail_price || '—'} ₽</div></div>
                                <div className="rounded-xl bg-white border p-3"><div className="text-xs text-gray-500">Закупка</div><div className="font-semibold">{pricePublicationPreview.offer.purchase_price} {pricePublicationPreview.offer.currency_code}</div></div>
                                <div className="rounded-xl bg-white border p-3"><div className="text-xs text-gray-500">Pricing rule</div><div className="font-semibold">{pricePublicationPreview.pricing.rule?.name || '—'}</div></div>
                                <div className="rounded-xl bg-white border p-3"><div className="text-xs text-gray-500">Изменение</div><div className="font-semibold">{pricePublicationPreview.delta_amount || '—'} ₽ · {pricePublicationPreview.delta_percent || '—'}%</div></div>
                                <div className="rounded-xl bg-white border p-3"><div className="text-xs text-gray-500">Расчётная маржа</div><div className="font-semibold">{pricePublicationPreview.pricing.expected_margin || '—'} ₽ · {pricePublicationPreview.pricing.expected_margin_percent || '—'}%</div></div>
                                <div className="rounded-xl bg-white border p-3 md:col-span-2"><div className="text-xs text-gray-500">Источник</div><div>import job #{pricePublicationPreview.offer.source_import_job_id ?? '—'} · row #{pricePublicationPreview.offer.source_import_row_id ?? '—'}</div><div>Offer импортирован: {formatDate(pricePublicationPreview.offer.imported_at)}</div></div>
                                <div className="rounded-xl bg-white border p-3 md:col-span-2"><div className="text-xs text-gray-500">Base product fields (не изменяются)</div><div>products.price: {pricePublicationPreview.product.base_price} ₽ · products.old_price: {pricePublicationPreview.product.base_old_price ?? 'NULL'}</div></div>
                              </div>
                              {pricePublicationPreview.warnings.map((warning) => <div key={warning} className="mt-3 text-sm text-amber-800">⚠ {warning}</div>)}
                              {pricePublicationPreview.blocking_reasons.map((reason) => <div key={reason} className="mt-2 text-sm font-medium text-red-700">Блокировка: {reason}</div>)}
                              <label className="block mt-4 text-sm text-gray-600">Комментарий к изменению (необязательно)<textarea maxLength={500} value={pricePublicationComment} onChange={(event) => setPricePublicationComment(event.target.value)} className="admin-input mt-2 min-h-20" /></label>
                              <div className="mt-5 flex justify-end"><button type="button" onClick={publishCandidatePrice} disabled={!pricePublicationPreview.can_publish || pricePublicationLoading} className="px-5 py-3 rounded-xl bg-red-600 text-white font-semibold disabled:opacity-40">{pricePublicationLoading ? 'Повторная проверка...' : 'Опубликовать цену'}</button></div>
                            </div>
                          )}
                        </div>
                      )}

                      <div className="mt-8 border-t border-gray-200 pt-5">
                        <h5 className="font-semibold text-graphite-900">История публикаций цен</h5>
                        <p className="text-xs text-gray-500 mt-1">Только чтение; автоматический откат не реализован.</p>
                        {pricePublicationHistoryLoading ? <div className="py-5 text-center text-gray-500">Загрузка истории...</div> : pricePublicationHistory.length === 0 ? <div className="mt-3 text-sm text-gray-500">История пока пуста.</div> : <div className="mt-3 overflow-x-auto"><table className="w-full min-w-[900px] text-sm"><thead><tr className="bg-gray-50"><th className="p-3 text-left">Дата</th><th className="p-3 text-left">Товар / вариант</th><th className="p-3 text-left">Цена</th><th className="p-3 text-left">Поставщик</th><th className="p-3 text-left">Закупка / правило</th><th className="p-3 text-left">Комментарий</th></tr></thead><tbody>{pricePublicationHistory.map((row) => <tr key={row.id} className="border-t"><td className="p-3">{formatDate(row.created_at)}</td><td className="p-3"><div>{row.product_name}</div><div className="text-xs text-gray-500">{row.assembly_country}</div></td><td className="p-3">{row.old_live_price} → <span className="font-semibold">{row.new_live_price} ₽</span></td><td className="p-3">{row.supplier_name}<div className="text-xs">{row.supplier_sku || '—'}</div></td><td className="p-3">{row.purchase_price} {row.currency_code}<div className="text-xs">{row.pricing_rule_name}</div></td><td className="p-3">{row.admin_comment || '—'}</td></tr>)}</tbody></table></div>}
                        {pricePublicationHistory.length > 0 && <div className="mt-3 flex justify-center gap-3"><button type="button" disabled={pricePublicationHistoryPage <= 1 || pricePublicationHistoryLoading} onClick={() => loadPricePublicationHistory(pricePublicationHistoryPage - 1)} className="px-3 py-2 border rounded-lg disabled:opacity-40">Назад</button><span className="py-2 text-sm">{pricePublicationHistoryPage} / {pricePublicationHistoryPages}</span><button type="button" disabled={pricePublicationHistoryPage >= pricePublicationHistoryPages || pricePublicationHistoryLoading} onClick={() => loadPricePublicationHistory(pricePublicationHistoryPage + 1)} className="px-3 py-2 border rounded-lg disabled:opacity-40">Далее</button></div>}
                      </div>
                    </div>
                  </div>
                )}

                {manualMatchRow && (
                  <div className="mt-6 rounded-2xl border border-accent-200 bg-accent-50/40 p-5">
                    <div className="flex justify-between gap-3"><div><h4 className="font-semibold">Ручное сопоставление строки {manualMatchRow.source_row_number}</h4><p className="text-xs text-gray-500 mt-1">Выбор создаёт долговременную связь только при наличии SKU поставщика.</p></div><button type="button" onClick={() => setManualMatchRow(null)}><X className="w-5 h-5" /></button></div>
                    <form onSubmit={searchProductsForImportRow} className="mt-4 flex gap-3"><input value={manualProductQuery} onChange={(event) => setManualProductQuery(event.target.value)} minLength={2} maxLength={100} className="admin-input" placeholder="Название или серия"/><button disabled={manualMatchLoading} className="px-4 py-2 rounded-xl bg-accent-500 text-white disabled:opacity-50">Найти</button></form>
                    <div className="mt-4 space-y-3">{manualProductResults.map((product) => (
                      <div key={product.id} className="rounded-xl border border-gray-200 bg-white p-4"><div className="flex justify-between gap-3"><div><div className="font-semibold">{product.name}</div><div className="text-xs text-gray-500">{product.series}</div></div><button type="button" disabled={manualMatchLoading} onClick={() => setManualImportMatch(product.id, null)} className="px-3 py-1.5 rounded-lg border text-xs">Выбрать товар без варианта</button></div><div className="mt-3 flex flex-wrap gap-2">{product.variants.map((variant) => <button key={variant.id} type="button" disabled={manualMatchLoading} onClick={() => setManualImportMatch(product.id, variant.id)} className="px-3 py-2 rounded-lg border border-emerald-200 text-emerald-700 text-xs">{variant.display_name || variant.variant_key}{variant.assembly_country ? ` · ${variant.assembly_country}` : ''}</button>)}</div></div>
                    ))}</div>
                  </div>
                )}
              </section>
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
