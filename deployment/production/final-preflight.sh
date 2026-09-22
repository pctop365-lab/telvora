#!/usr/bin/env bash
set -euo pipefail
php_files=(products.php runtime_config.php product_activation_service.php seo_publication_service.php seo_publication_job_service.php seo_publication_batch_service.php seo_publication_snapshot_service.php seo_publication_worker.php deployment/production/backup-database.php deployment/production/apply-seo-publication-migration.php)
for file in "${php_files[@]}"; do php -l "$file" >/dev/null; done
bash -n deployment/backend/deploy-backend.sh
command -v mysqldump >/dev/null
npm ci
npm run test:backend:deployment
npm run test:seo:publication-phase2a
npm run test:seo:publication-phase2c
npm run test:admin:publication
npm run typecheck
npm run build
git diff --check
echo 'FINAL_PREFLIGHT_PASS'
