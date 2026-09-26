import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../src/pages/AdminPage.tsx', import.meta.url), 'utf8');
const toolbarStart = source.indexOf('data-testid="price-preview-toolbar"');
const buttonStart = source.indexOf('data-testid="price-preview-bulk-confirm"', toolbarStart);
const tableOverflow = source.indexOf('overflow-x-auto rounded-xl border border-gray-200"><table', buttonStart);
const individualAction = source.indexOf('Подготовить изменение цены', tableOverflow);
const mappedRowsBranch = source.indexOf('offerPricingRows.length === 0 ?');

assert(toolbarStart >= 0, 'price preview toolbar must be rendered');
assert(buttonStart > toolbarStart, 'bulk button must be inside the toolbar');
assert(tableOverflow > buttonStart, 'bulk button must appear before the table overflow container');
assert(mappedRowsBranch > 0 && toolbarStart > mappedRowsBranch, 'toolbar must be inside the non-empty mapped rows render branch');
assert(individualAction > tableOverflow, 'the existing individual price action must remain in the same table');
assert(individualAction > buttonStart, 'bulk button and individual action must share the active preview render path');
assert(source.includes('onClick={confirmAllPrices}'), 'bulk button must use the existing bulk handler');
assert(source.includes('normalizedOfferPricingConfirmableTotal = Number.isFinite'), 'confirmable count must be normalized');
assert(source.includes('disabled={bulkPriceLoading || pricePublicationLoading || offerPricingLoading || offerPublishLoading || normalizedOfferPricingConfirmableTotal === 0}'), 'button must remain in DOM and disable at zero');
assert(source.includes('title={normalizedOfferPricingConfirmableTotal === 0 ?'), 'zero confirmable state must explain why the button is disabled');
assert(source.includes('className="relative z-10 mb-4 grid w-full min-w-0'), 'toolbar must own a full-width, min-width-safe layout');
assert(source.includes('overflow-visible md:grid-cols-[minmax(0,1fr)_auto]'), 'toolbar must keep the action outside table overflow');
assert(source.includes('className="!visible !inline-flex shrink-0 whitespace-nowrap'), 'bulk button must remain visibly rendered');
assert(source.includes("style={{ display: 'inline-flex', visibility: 'visible' }}"), 'bulk button must force visible inline layout');

console.log('PASS import price preview UI contract');
