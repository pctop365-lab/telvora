import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../src/pages/AdminPage.tsx', import.meta.url), 'utf8');
const toolbarStart = source.indexOf('data-testid="price-preview-toolbar"');
const buttonStart = source.indexOf('data-testid="price-preview-bulk-confirm"', toolbarStart);
const tableOverflow = source.indexOf('overflow-x-auto rounded-xl border border-gray-200"><table', buttonStart);

assert(toolbarStart >= 0, 'price preview toolbar must be rendered');
assert(buttonStart > toolbarStart, 'bulk button must be inside the toolbar');
assert(tableOverflow > buttonStart, 'bulk button must appear before the table overflow container');
assert(source.includes('onClick={confirmAllPrices}'), 'bulk button must use the existing bulk handler');
assert(source.includes('normalizedOfferPricingConfirmableTotal = Number.isFinite'), 'confirmable count must be normalized');
assert(source.includes('disabled={bulkPriceLoading || pricePublicationLoading || offerPricingLoading || offerPublishLoading || normalizedOfferPricingConfirmableTotal === 0}'), 'button must remain in DOM and disable at zero');
assert(source.includes('title={normalizedOfferPricingConfirmableTotal === 0 ?'), 'zero confirmable state must explain why the button is disabled');

console.log('PASS import price preview UI contract');
