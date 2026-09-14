import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: './tests/seo-browser', outputDir: 'seo-artifacts/test-results', timeout: 30000, workers: 1,
  use: {
    baseURL: 'http://127.0.0.1:4179', trace: 'retain-on-failure',
    launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' },
  },
  webServer: { command: 'node scripts/serve-seo.mjs', url: 'http://127.0.0.1:4179', reuseExistingServer: false },
});
