import contacts from '../../public_contacts.json';

export const publicContacts = {
  ...contacts,
  ordersMailto: `mailto:${contacts.ordersEmail}`,
  supportMailto: `mailto:${contacts.supportEmail}`,
  phoneLink: `tel:${contacts.phoneHref}`,
} as const;

export const businessDetails = publicContacts;
