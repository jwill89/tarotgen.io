import { defineConfig, devices } from '@playwright/test'
import { BASE_URL, DATA_DIR, PORT } from './e2e/env'

// The smoke run (AUDIT §8.6): the built SPA and the real API behind PHP's
// built-in server, routed as production's .htaccess routes them
// (e2e/server/router.php), on a seeded throwaway database. `npm run build` first.
// Locally on Windows it drives the installed Edge, so nothing is downloaded; CI
// installs Playwright's Chromium.
const channel = process.platform === 'win32' && !process.env.CI ? 'msedge' : undefined

export default defineConfig({
  testDir: 'e2e',
  // One server, one database: the steps share state, so they run in order.
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  outputDir: 'test-results',
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], channel } }],
  webServer: {
    command: `php e2e/server/prepare.php "${DATA_DIR}" ${BASE_URL} && php -S 127.0.0.1:${String(PORT)} -t dist e2e/server/router.php`,
    url: `${BASE_URL}/api/config`,
    env: { TAROT_DATA_DIR: DATA_DIR },
    reuseExistingServer: false,
    // PHP's built-in server logs every request to stderr. A failure keeps a trace
    // (test-results/); DEBUG=pw:webserver shows the server's output.
    stdout: 'ignore',
    stderr: 'ignore',
  },
})
