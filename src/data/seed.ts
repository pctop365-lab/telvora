import type { Product, ProductCategory } from '@/types';

const seedProducts: Product[] = [
  {
    id: '1',
    slug: 'telvora-vision-oled-65',
    name: 'TELVORA Vision OLED',
    series: 'V-Series',
    category: 'OLED',
    screenSize: '65"',
    resolution: '4K Ultra HD',
    price: 189990,
    oldPrice: 249990,
    image: 'https://images.pexels.com/photos/5202925/pexels-photo-5202925.jpeg?auto=compress&cs=tinysrgb&w=1200',
    badge: 'Хит продаж',
    rating: 4.9,
    reviews: 1284,
    description:
      'Флагманский OLED-телевизор с идеальным чёрным цветом, бесконечным контрастом и поддержкой Dolby Vision. Каждый пиксель светится самостоятельно — глубина изображения превосходит всё, что вы видели.',
    specs: [
      { label: 'Экран', value: '65" OLED' },
      { label: 'Разрешение', value: '3840 × 2160 (4K)' },
      { label: 'HDR', value: 'Dolby Vision IQ, HDR10+, HLG' },
      { label: 'Частота', value: '144 Гц' },
      { label: 'Процессор', value: 'Telvora Neural X1' },
      { label: 'Звук', value: 'Dolby Atmos, 4.2' },
      { label: 'Smart TV', value: 'Telvora OS 4.0' },
      { label: 'Подключения', value: '4× HDMI 2.1, 3× USB, Wi-Fi 6' },
    ],
    highlights: [
      'Самоэмиссионные OLED-пиксели',
      '144 Гц для плавного гейминга',
      'Dolby Vision IQ + Atmos',
      'Безрамочный дизайн 0.3 мм',
    ],
  },
  {
    id: '2',
    slug: 'telvora-aurora-qled-75',
    name: 'TELVORA Aurora QLED',
    series: 'A-Series',
    category: 'QLED',
    screenSize: '75"',
    resolution: '4K Ultra HD',
    price: 159990,
    oldPrice: 199990,
    image: 'https://images.pexels.com/photos/35618217/pexels-photo-35618217.jpeg?auto=compress&cs=tinysrgb&w=1200',
    badge: '−20%',
    rating: 4.8,
    reviews: 892,
    description:
      'QLED-панель с квантовыми точками обеспечивает 100% цветового объёма DCI-P3. Яркость до 2000 нит делает изображение невероятно живым даже в залитой солнцем комнате.',
    specs: [
      { label: 'Экран', value: '75" QLED' },
      { label: 'Разрешение', value: '3840 × 2160 (4K)' },
      { label: 'HDR', value: 'HDR10+, HLG, Dolby Vision' },
      { label: 'Частота', value: '120 Гц' },
      { label: 'Яркость', value: 'до 2000 нит' },
      { label: 'Звук', value: 'Dolby Atmos, 2.2.2' },
      { label: 'Smart TV', value: 'Telvora OS 4.0' },
      { label: 'Подключения', value: '4× HDMI 2.1, 2× USB, Wi-Fi 6' },
    ],
    highlights: [
      'Квантовые точки DCI-P3 100%',
      'Яркость до 2000 нит',
      'Anti-glare покрытие',
      'Лучшая яркость в классе',
    ],
  },
  {
    id: '3',
    slug: 'telvora-prism-8k-85',
    name: 'TELVORA Prism 8K',
    series: 'P-Series',
    category: '8K',
    screenSize: '85"',
    resolution: '8K Ultra HD',
    price: 449990,
    image: 'https://images.pexels.com/photos/13348768/pexels-photo-13348768.jpeg?auto=compress&cs=tinysrgb&w=1200',
    badge: 'Новинка',
    rating: 5.0,
    reviews: 147,
    description:
      'Первый 8K-телевизор TELVORA с нейросетевым апскейлингом. 33 миллиона пикселей создают изображение, неотличимое от реальности. Идеальный выбор для домашних кинотеатров.',
    specs: [
      { label: 'Экран', value: '85" Neo QLED 8K' },
      { label: 'Разрешение', value: '7680 × 4320 (8K)' },
      { label: 'HDR', value: 'Dolby Vision, HDR10+' },
      { label: 'Частота', value: '120 Гц' },
      { label: 'Процессор', value: 'Telvora Quantum AI 8K' },
      { label: 'Звук', value: 'Dolby Atmos, 6.2.2' },
      { label: 'Smart TV', value: 'Telvora OS 4.0 Pro' },
      { label: 'Подключения', value: '4× HDMI 2.1, 3× USB, Wi-Fi 6E' },
    ],
    highlights: [
      '8K разрешение — 33 Мп',
      'AI апскейлинг до 8K',
      'Объёмный звук 6.2.2',
      'Сверхтонкий профиль 8.9 мм',
    ],
  },
  {
    id: '4',
    slug: 'telvora-edge-oled-55',
    name: 'TELVORA Edge OLED',
    series: 'E-Series',
    category: 'OLED',
    screenSize: '55"',
    resolution: '4K Ultra HD',
    price: 99990,
    oldPrice: 129990,
    image: 'https://images.pexels.com/photos/6020432/pexels-photo-6020432.jpeg?auto=compress&cs=tinysrgb&w=1200',
    rating: 4.7,
    reviews: 2103,
    description:
      'Идеальный баланс цены и качества. OLED-матрица, 120 Гц, все современные HDR-форматы. Телевизор, который преобразит вашу гостиную без раздувания бюджета.',
    specs: [
      { label: 'Экран', value: '55" OLED' },
      { label: 'Разрешение', value: '3840 × 2160 (4K)' },
      { label: 'HDR', value: 'Dolby Vision, HDR10+, HLG' },
      { label: 'Частота', value: '120 Гц' },
      { label: 'Процессор', value: 'Telvora Neural X1' },
      { label: 'Звук', value: 'Dolby Atmos, 2.2' },
      { label: 'Smart TV', value: 'Telvora OS 4.0' },
      { label: 'Подключения', value: '4× HDMI 2.1, 2× USB, Wi-Fi 6' },
    ],
    highlights: [
      'OLED по доступной цене',
      '120 Гц для кино и игр',
      'Безрамочный дизайн',
      'VRR + ALLM для гейминга',
    ],
  },
  {
    id: '5',
    slug: 'telvora-flux-led-50',
    name: 'TELVORA Flux LED',
    series: 'F-Series',
    category: 'LED',
    screenSize: '50"',
    resolution: '4K Ultra HD',
    price: 49990,
    oldPrice: 69990,
    image: 'https://images.pexels.com/photos/7546717/pexels-photo-7546717.jpeg?auto=compress&cs=tinysrgb&w=1200',
    badge: '−29%',
    rating: 4.6,
    reviews: 3421,
    description:
      'Доступный 4K LED-телевизор с превосходным качеством изображения. Идеален для спальни, кухни или детской — умный, быстрый и невероятно простой в использовании.',
    specs: [
      { label: 'Экран', value: '50" LED Direct' },
      { label: 'Разрешение', value: '3840 × 2160 (4K)' },
      { label: 'HDR', value: 'HDR10, HLG' },
      { label: 'Частота', value: '60 Гц' },
      { label: 'Процессор', value: 'Telvora Core' },
      { label: 'Звук', value: 'Dolby Audio, 2.0' },
      { label: 'Smart TV', value: 'Telvora OS 4.0' },
      { label: 'Подключения', value: '3× HDMI, 2× USB, Wi-Fi 5' },
    ],
    highlights: [
      'Лучший в бюджете',
      '4K по цене Full HD',
      'Компактный и лёгкий',
      'Мгновенный запуск',
    ],
  },
  {
    id: '6',
    slug: 'telvora-cinema-oled-77',
    name: 'TELVORA Cinema OLED',
    series: 'C-Series',
    category: 'OLED',
    screenSize: '77"',
    resolution: '4K Ultra HD',
    price: 299990,
    oldPrice: 379990,
    image: 'https://images.pexels.com/photos/19966811/pexels-photo-19966811.jpeg?auto=compress&cs=tinysrgb&w=1200',
    badge: 'Премиум',
    rating: 4.9,
    reviews: 568,
    description:
      'Домашний кинотеатр в одном устройстве. 77 дюймов OLED, 144 Гц, звуковая система 5.1.2 с встроенным сабвуфером. Ощутите кино так, как задумал режиссёр.',
    specs: [
      { label: 'Экран', value: '77" OLED evo' },
      { label: 'Разрешение', value: '3840 × 2160 (4K)' },
      { label: 'HDR', value: 'Dolby Vision IQ, HDR10+, HLG' },
      { label: 'Частота', value: '144 Гц' },
      { label: 'Процессор', value: 'Telvora Neural X1 Pro' },
      { label: 'Звук', value: 'Dolby Atmos, 5.1.2 + сабвуфер' },
      { label: 'Smart TV', value: 'Telvora OS 4.0 Pro' },
      { label: 'Подключения', value: '4× HDMI 2.1, 3× USB, Wi-Fi 6E' },
    ],
    highlights: [
      '77" OLED evo — на 20% ярче',
      'Встроенный 5.1.2 Dolby Atmos',
      '144 Гц gaming-порт',
      'Дизайн One Slate Design',
    ],
  },
];

export function getSeedProducts(): Product[] {
  return seedProducts;
}

export function getSeedCategories(): { slug: string; label: string; description: string }[] {
  return [
    { slug: 'oled', label: 'OLED', description: 'Идеальный чёрный, бесконечный контраст. Каждый пиксель светится самостоятельно.' },
    { slug: 'qled', label: 'QLED', description: 'Квантовые точки для 100% цветового объёма. Яркий и живой цвет.' },
    { slug: '8k', label: '8K', description: '33 миллиона пикселей. Разрешение, опережающее время.' },
    { slug: 'led', label: 'LED', description: 'Доступные 4K-модели с отличным качеством для любого помещения.' },
  ];
}

export function getCategorySlug(category: ProductCategory): string {
  const map: Record<ProductCategory, string> = {
    OLED: 'oled',
    QLED: 'qled',
    '8K': '8k',
    LED: 'led',
  };
  return map[category];
}

export function parseCategorySlug(slug: string): ProductCategory | null {
  const map: Record<string, ProductCategory> = {
    oled: 'OLED',
    qled: 'QLED',
    '8k': '8K',
    led: 'LED',
  };
  return map[slug] ?? null;
}
