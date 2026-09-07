import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';

const helperSource = fs.readFileSync(new URL('../src/pages/adminVariantManagement.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(helperSource, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 } }).outputText;
const module = { exports: {} };
vm.runInNewContext(compiled, { module, exports: module.exports }, { filename: 'adminVariantManagement.ts' });
const { variantMutationErrorMessage, isVariantDraft, shouldConfirmVariantDisable, canStartVariantMutation, isCurrentVariantMutation } = module.exports;

assert.equal(isVariantDraft(true, false), true, 'zero-price canonical draft is identified without identity error');
assert.equal(isVariantDraft(false, false), false, 'identity mismatch is not called a draft');
assert.equal(shouldConfirmVariantDisable(true, false), true, 'disable requires confirmation');
assert.equal(shouldConfirmVariantDisable(false, true), false, 'enable does not require confirmation');
assert.equal(canStartVariantMutation(false, 5, 5), true, 'current product can mutate');
assert.equal(canStartVariantMutation(true, 5, 5), false, 'double submit is blocked synchronously');
assert.equal(canStartVariantMutation(false, 6, 5), false, 'late action for another product is blocked');
assert.equal(isCurrentVariantMutation(8, 8, false), true, 'latest request may commit state');
assert.equal(isCurrentVariantMutation(7, 8, false), false, 'late response after product switch is stale');
assert.equal(isCurrentVariantMutation(8, 8, true), false, 'close or unmount cancellation is stale');
for (const status of [400, 401, 403, 404, 409, 500]) {
  assert.ok(variantMutationErrorMessage(status, 'add').length > 0, `safe add message for ${status}`);
  assert.ok(variantMutationErrorMessage(status, 'set_active').length > 0, `safe status message for ${status}`);
  assert.ok(variantMutationErrorMessage(status, 'price_manual').length > 0, `safe manual price message for ${status}`);
  assert.ok(variantMutationErrorMessage(status, 'price_automatic').length > 0, `safe automatic price message for ${status}`);
}
assert.match(variantMutationErrorMessage(409, 'set_active'), /последний готовый вариант/);

const page = fs.readFileSync(new URL('../src/pages/AdminPage.tsx', import.meta.url), 'utf8');
assert.match(page, /variant_add/);
assert.match(page, /variant_set_active/);
assert.match(page, /variant_price_set_manual/);
assert.match(page, /variant_price_set_automatic/);
assert.match(page, /credentials: 'include'/);
assert.match(page, /'X-CSRF-Token': csrfToken \|\| ''/);
assert.match(page, /window\.confirm/);
assert.match(page, /variantMutationPendingRef\.current/);
assert.match(page, /variantMutationRequestSequenceRef\.current/);
assert.match(page, /controller\.signal\.aborted/);
assert.match(page, /loadAdminVariants\(product, 'refresh'\)/);
assert.doesNotMatch(page, /action: 'variant_(?:delete|update_price|update_country|update_identity)'/);
assert.doesNotMatch(page, /Редактировать (?:страну|identity|variant_key)/i);
assert.match(page, /Импорт продолжает обновлять закупочные данные, а публикация прайса — автоматическую цену/);
assert.match(page, /Результат запроса неизвестен из-за ошибки сети/);
assert.match(page, /Изменение принято сервером, но подтвердить новое состояние не удалось/);
assert.match(page, /canStartVariantMutation\(variantMutationPendingRef\.current/);

console.log('PASS admin variant management UI fixtures');
