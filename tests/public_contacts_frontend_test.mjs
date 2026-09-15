import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const contacts = JSON.parse(readFileSync(new URL('../public_contacts.json', import.meta.url), 'utf8'));
const form = readFileSync(new URL('../src/components/CallbackRequestForm.tsx', import.meta.url), 'utf8');
const footer = readFileSync(new URL('../src/components/Footer.tsx', import.meta.url), 'utf8');
const support = readFileSync(new URL('../src/pages/SupportPage.tsx', import.meta.url), 'utf8');
const invoice = readFileSync(new URL('../generate_invoice_pdf.php', import.meta.url), 'utf8');

assert.equal(contacts.sellerFullName, 'Индивидуальный предприниматель Помякшев Иван Владимирович');
assert.equal(contacts.inn, '500907422390'); assert.equal(contacts.ogrnip, '326508100533649');
assert.equal(contacts.ordersEmail, 'telvora24@gmail.com'); assert.equal(contacts.supportEmail, 'telvorasupport24@gmail.com');
assert.equal(contacts.phoneDisplay, '+7 (926) 202-01-19'); assert.equal(contacts.phoneHref, '+79262020119');
assert.deepEqual(contacts.phones, [{ display: '+7 (926) 202-01-19', href: '+79262020119' }, { display: '+7 (903) 189-43-42', href: '+79031894342' }]);
assert.match(form, /useState\(false\)/, 'consent must not be preselected');
assert.match(form, /\/personal-data-consent/);
assert.match(form, /\/privacy/);
assert.match(form, /response\.ok/);
assert.match(form, /data\?\.success/);
assert.match(footer, /publicContacts\.phones\.map/);
assert.match(support, /tel:\$\{phone\.href\}/);
assert.match(footer, /publicContacts\.ordersMailto/);
assert.match(footer, /publicContacts\.supportMailto/);
assert.match(support, /contacts#callback/);
assert.match(invoice, /public_contacts\.json/);
assert.match(invoice, /<td class="label">Почта<\/td>/);
assert.match(invoice, /h\(\$sellerOrdersEmail\)/); assert.match(invoice, /h\(\$sellerSupportEmail\)/); assert.match(invoice, /\$sellerPhoneLine/);
assert.doesNotMatch(invoice, /Почта поддержки/);
assert.doesNotMatch(invoice, /Багратионовский|sellerAddress/);
for (const key of ['sellerShortName', 'inn', 'ogrnip', 'registrationDate', 'bankName', 'bankAccount', 'bik', 'correspondentAccount', 'vatNotice']) assert.match(invoice, new RegExp(`publicContacts\\['${key}'\\]`));
assert.match(invoice, /\$order\['customer_name'\]/, 'legacy customer data binding must remain');
assert.match(invoice, /\$order\['phone'\]/, 'legacy order phone binding must remain');

console.log('public_contacts_frontend_test: PASS');
