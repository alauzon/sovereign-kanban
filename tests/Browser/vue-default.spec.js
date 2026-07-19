// The Vue shell is the default now (Alain, 2026-07-19: no more ?vue=1). ?vue=0
// still serves the vanilla app for a side-by-side compare.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

test.describe('Vue par défaut', () => {
	test('l\'URL nue charge la Vue (mont #sk-vue), pas l\'ancienne', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/')
		await dismissWizard(page)
		await expect(page.locator('#sk-vue')).toHaveCount(1)
		await expect(page.locator('#sk-app')).toHaveCount(0)
	})

	test('?vue=0 sert encore l\'ancienne interface', async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/?vue=0')
		await dismissWizard(page)
		await expect(page.locator('#sk-app')).toHaveCount(1)
		await expect(page.locator('#sk-vue')).toHaveCount(0)
	})
})
