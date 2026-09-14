export const publicRoutes = [
  '/', '/catalog', '/catalog/oled', '/catalog/qled', '/catalog/led', '/catalog/8k',
  '/delivery', '/services', '/warranty', '/returns', '/support', '/contacts',
  '/requisites', '/offer', '/privacy', '/personal-data-consent', '/cookies',
];
export const clientRoutes = ['/checkout', '/admin', '/soundbars', '/accessories'];
export const isClientRoute = path => clientRoutes.includes(path) || /^\/order-success\/[^/]+$/.test(path);
