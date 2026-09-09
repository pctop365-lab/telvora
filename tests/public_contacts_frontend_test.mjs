import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const contacts = JSON.parse(readFileSync(new URL('../public_contacts.json', import.meta.url), 'utf8'));
const form = readFileSync(new URL('../src/components/CallbackRequestForm.tsx', import.meta.url), 'utf8');
const footer = readFileSync(new URL('../src/components/Footer.tsx', import.meta.url), 'utf8');
const support = readFileSync(new URL('../src/pages/SupportPage.tsx', import.meta.url), 'utf8');
const invoice = readFileSync(new URL('../generate_invoice_pdf.php', import.meta.url), 'utf8');

assert.deepEqual(contacts, { ordersEmail: 'telvora24@gmail.com', supportEmail: 'telvorasupport24@gmail.com', phoneDisplay: '+7 (926) 202-01-19', phoneHref: '+79262020119' });
assert.match(form, /useState\(false\)/, 'consent must not be preselected');
assert.match(form, /\/personal-data-consent/);
assert.match(form, /\/privacy/);
assert.match(form, /response\.ok/);
assert.match(form, /data\?\.success/);
assert.match(footer, /publicContacts\.phoneLink/);
assert.match(footer, /publicContacts\.ordersMailto/);
assert.match(footer, /publicContacts\.supportMailto/);
assert.match(support, /contacts#callback/);
assert.match(invoice, /public_contacts\.json/);
assert.match(invoice, /Почта для заказов/);
assert.match(invoice, /Почта поддержки/);
assert.match(invoice, /\$order\['customer_name'\]/, 'legacy customer data binding must remain');
assert.match(invoice, /\$order\['phone'\]/, 'legacy order phone binding must remain');

console.log('public_contacts_frontend_test: PASS');
