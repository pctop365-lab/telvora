import assert from 'node:assert/strict';
import fs from 'node:fs';

const admin = fs.readFileSync('src/pages/AdminPage.tsx', 'utf8');
const endpoint = fs.readFileSync('products.php', 'utf8');
const service = fs.readFileSync('product_delete_service.php', 'utf8');

assert.match(admin, /const deleteProduct = async \(product: AdminProduct\)/);
assert.match(admin, /window\.confirm\(/);
assert.match(admin, /action: 'delete'/);
assert.match(admin, /id: product\.id/);
assert.match(admin, /method: 'POST'/);
assert.match(admin, /X-CSRF-Token/);
assert.match(admin, /!response\.ok \|\| !data\.success/);
assert.match(admin, /setProducts\(\(current\) =>\s*current\.filter/);
assert.match(admin, /setProductsNotice/);
assert.match(admin, /setProductsError/);
assert.match(admin, /await loadProducts\(\)/);

assert.match(endpoint, /require_once __DIR__ \. '\/product_delete_service\.php';/);
assert.match(endpoint, /if \(\$action === 'delete'\)/);
assert.match(endpoint, /productDelete\(\$pdo, \$id\)/);
assert.doesNotMatch(endpoint, /DELETE FROM products/);
assert.match(endpoint, /ProductDeleteBlockedException/);
assert.match(endpoint, /http_response_code\(409\)/);

assert.match(service, /\$pdo->beginTransaction\(\)/);
assert.match(service, /SELECT id, is_active, publication_status FROM products WHERE id = :id FOR UPDATE/);
assert.match(service, /order_items/);
assert.match(service, /supplier_import_rows/);
assert.match(service, /product_price_publication_audit/);
assert.match(service, /supplier_offers/);
assert.match(service, /supplier_product_matches/);
assert.match(service, /product_variant_price_overrides/);
assert.match(service, /publication_status/);
assert.match(service, /status IN \('queued', 'running'\)/);
assert.match(service, /DELETE FROM seo_publication_jobs WHERE product_id = :product_id/);
assert.match(service, /\$pdo->commit\(\)/);
assert.match(service, /\$pdo->rollBack\(\)/);
assert.match(service, /ProductDeleteBlockedException/);

console.log('PASS product delete contract');
