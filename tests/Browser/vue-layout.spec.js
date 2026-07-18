// Layout regressions Alain found on Tshinanu, 2026-07-18. Both are tested
// GEOMETRICALLY, not by pixel: a robust assertion reddens only when the real
// defect is back, not when a font or an NC button size shifts.
//
//   1. Columns must stay on ONE row and scroll — not wrap onto a second line.
//   2. The board title must not be OVERLAPPED by the navigation toggle button.
//      The "norm" we set: nothing in the content overlaps a control. Two boxes,
//      no intersection. No exact pixels — so no brittleness.
const { test, expect } = require('@playwright/test')
const { dismissWizard } = require('./support')

const BOARD = 'zzz-e2e-layout'


test.describe('layout du board Vue', () => {
	test.beforeEach(async ({ page }) => {
		page.on('dialog', (d) => d.accept())
		await page.goto('/apps/sovereign-kanban/')
		await dismissWizard(page)
		await cleanup(page)
		await page.getByRole('button', { name: '+ Nouveau tableau' }).click()
		await page.getByPlaceholder('Nom du tableau').fill(BOARD)
		await page.getByRole('button', { name: 'Créer', exact: true }).click()
		await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(1)
	})

	test.afterEach(async ({ page }) => {
		await page.goto('/apps/sovereign-kanban/')
		await dismissWizard(page)
		await cleanup(page)
	})

	async function cleanup(page) {
		if (await page.locator('.sk-board-tab', { hasText: BOARD }).count()) {
			await page.locator('.sk-board-tab', { hasText: BOARD }).first().click()
			await page.getByRole('button', { name: 'Éditer' }).click()
			await page.getByRole('button', { name: 'Supprimer le tableau' }).click()
			await expect(page.locator('.sk-board-tab', { hasText: BOARD })).toHaveCount(0)
		}
	}

	async function openBoard(page) {
		await page.goto('/apps/sovereign-kanban/?vue=1')
		await dismissWizard(page)
		await page.getByRole('link', { name: BOARD }).click()
		await expect(page.locator('.sk-vue-columns')).toBeVisible()
	}

	// Bug 2 (Alain): overflow columns wrapped instead of scrolling.
	test('les colonnes restent sur une ligne et défilent, sans s\'empiler', async ({ page }) => {
		// 1100 keeps the sidebar open (it collapses below ~1024) while the content
		// pane (~800px) is too narrow for 4×280px columns — so they must overflow.
		await page.setViewportSize({ width: 1100, height: 700 })
		await openBoard(page)

		const columns = page.locator('.sk-vue-column')
		await expect(columns).toHaveCount(4)

		const tops = await columns.evaluateAll((els) => els.map((e) => e.getBoundingClientRect().top))
		for (const top of tops) {
			expect(Math.abs(top - tops[0])).toBeLessThan(2) // one row ⇒ no wrap
		}
		const scrolls = await page.locator('.sk-vue-columns').evaluate((el) => el.scrollWidth > el.clientWidth + 1)
		expect(scrolls).toBe(true)
	})

	// Bug 1 (Alain): the nav toggle covered the first letter of the title.
	//
	// The subtlety the falsification forced out: the <h2> BOX is wide and always
	// spans the corner where the toggle sits, so comparing boxes is blind to the
	// bug (the first version stayed green when mutated). What actually overlaps is
	// the toggle and the TEXT — and the text starts at h2.left + padding-left. So
	// the real assertion is: the title's text must begin at or after the toggle's
	// right edge. padding 24 → text at ~333, toggle ends ~360 → overlap; padding
	// 52 → text at ~361 → clear.
	//
	// Multiple viewports (Alain, 2026-07-18: "le D de Démo est toujours caché, ton
	// test ne le capture pas"). The single 1280 check missed his 2560 screen — the
	// toggle overlays the content corner at EVERY width, so the norm must hold at
	// every width. One check per viewport, each named, so a failure says which.
	for (const width of [1280, 1920, 2560]) {
		test(`le texte du titre ne commence pas sous le bouton de navigation (${width}px)`, async ({ page }) => {
			await page.setViewportSize({ width, height: 800 })
			await openBoard(page)

			const toggle = page.locator('.app-navigation-toggle-wrapper')
			await expect(toggle).toBeVisible()
			const box = await toggle.boundingBox()
			const toggleRight = box.x + box.width

			const textLeft = await page.locator('.sk-vue-board-title').evaluate((el) => {
				const r = el.getBoundingClientRect()
				return r.left + parseFloat(getComputedStyle(el).paddingLeft)
			})

			expect(textLeft).toBeGreaterThanOrEqual(toggleRight)
		})
	}
})
