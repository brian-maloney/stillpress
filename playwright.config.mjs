/**
 * Visual diff: the static output must look like the live site.
 *
 * The suite uses the wp-env *tests* site (http://localhost:8889), which it erases, and serves each build on
 * http://localhost:8890. Run with: npm run test:e2e   (THEMES=block,classic,pagebuilder to choose themes)
 */
import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: './tests/e2e',
	timeout: 10 * 60 * 1000,
	expect: { timeout: 15000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ], [ 'html', { open: 'never', outputFolder: 'playwright-report' } ] ],
	outputDir: 'test-results',
	use: {
		viewport: { width: 1280, height: 900 },
		reducedMotion: 'reduce',
		colorScheme: 'light',
		locale: 'en-US',
		timezoneId: 'UTC',
	},
	webServer: {
		command: 'node tests/e2e/static-server.mjs tests/e2e/.cache/site 8890',
		port: 8890,
		reuseExistingServer: ! process.env.CI,
	},
} );
