import contacts from '../../public_contacts.json';

export const publicContacts = {
  ...contacts,
  phones: contacts.phones,
  phoneLinks: contacts.phones.map((phone) => `tel:${phone.href}`),
  phoneDisplays: contacts.phones.map((phone) => phone.display).join(' / '),
  ordersMailto: `mailto:${contacts.ordersEmail}`,
  supportMailto: `mailto:${contacts.supportEmail}`,
  phoneLink: `tel:${contacts.phoneHref}`,
} as const;

export const businessDetails = publicContacts;
