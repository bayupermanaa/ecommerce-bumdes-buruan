import { defineConfig, devices } from '@playwright/test';

const lightweightRun = process.env.E2E_LIGHT === '1';

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './test-results/playwright-artifacts',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: 0,
    workers: 1,
    timeout: 30_000,
    expect: {
        timeout: 10_000,
    },
    reporter: lightweightRun
        ? [['list']]
        : [['list'], ['html', { outputFolder: './test-results/playwright-html', open: 'never' }]],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:4173',
        screenshot: lightweightRun ? 'off' : 'only-on-failure',
        trace: lightweightRun ? 'off' : 'retain-on-failure',
        video: 'off',
    },
    projects: [
        {
            name: 'chromium-desktop',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1366, height: 768 },
            },
        },
        {
            name: 'chromium-tablet',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 768, height: 1024 },
            },
        },
        {
            name: 'chromium-mobile',
            use: {
                ...devices['Desktop Chrome'],
                browserName: 'chromium',
                viewport: { width: 390, height: 844 },
            },
        },
    ],
});
