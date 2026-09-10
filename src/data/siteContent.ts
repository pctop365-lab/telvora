/**
 * Static site content — images and marketing copy.
 * When migrating to a backend, these can be moved to a CMS or settings table.
 * Components consume them through this module, not directly.
 */

export const siteContent = {
  hero: {
    image: 'https://images.pexels.com/photos/28549934/pexels-photo-28549934.jpeg?auto=compress&cs=tinysrgb&w=1920',
    badge: 'Телевизоры TELVORA',
    title: 'Телевизор, который подходит именно вам',
    subtitle:
      'Подберём диагональ, технологию и модель под вашу комнату и бюджет. Доставка и профессиональная установка.',
  },
  tech: {
    cinemaImage: 'https://images.pexels.com/photos/7991486/pexels-photo-7991486.jpeg?auto=compress&cs=tinysrgb&w=1600',
    lifestyleImage: 'https://images.pexels.com/photos/7031762/pexels-photo-7031762.jpeg?auto=compress&cs=tinysrgb&w=1600',
  },
  freeDeliveryThreshold: 50000,
  deliveryFee: 1990,
} as const;
