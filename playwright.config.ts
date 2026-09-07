import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/browser',
  timeout: 30_000,
  workers: 1,
  use: {
    baseURL: 'http://127.0.0.1:4178',
    headless: true,
    trace: 'retain-on-failure',
    launchOptions: { executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' },
  },
  webServer: {
    command: 'npm.cmd run dev -- --host 127.0.0.1 --port 4178',
    url: 'http://127.0.0.1:4178/admin',
    reuseExistingServer: false,
    timeout: 30_000,
  },
});
