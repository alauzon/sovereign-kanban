import { test, expect } from '@playwright/test'

// Carte 78fc32, option B (Alain, 2026-07-22). The NC rich Text editor can't feed
// custom @ suggestions in our mode (useSession:false — verified upstream), and its
// programmatic insert API is a no-op there too, so a « Mentionner un membre »
// picker inserts « @Display Name » via execCommand into the ProseMirror. This
// validates the browser-only piece jsdom can't see: picking a member puts the
// mention text into the real editor.
//
// Needs a SHARED board (with other members) named in e2e/.env (NC_TEST_BOARD).
// No comment is posted — the editor is cancelled — so it runs repeatedly without
// polluting the board or notifying anyone.
test('picking a board member inserts « @Name » into the comment editor', async ({ page }) => {
	const board = process.env.NC_TEST_BOARD || 'bienvenue'
	await page.goto('/apps/sovereign-kanban/#' + board)

	// Open the first card, then its Commentaires tab.
	await page.locator('.sk-vue-card').first().click()
	await page.locator('.sk-tab', { hasText: /Commentaires/i }).click()

	// Start a comment → the rich editor mounts.
	await page.locator('.sk-comment-addph').click()
	const pm = page.locator('.sk-comment-editor .ProseMirror')
	await expect(pm).toBeVisible()

	// The picker appears only when the board has OTHER members.
	const picker = page.locator('.sk-comment-mention')
	await expect(picker, 'le sélecteur « Mentionner » exige un tableau partagé (NC_TEST_BOARD)').toBeVisible()

	// Place a cursor in the editor, then pick the first offered member.
	await pm.click()
	await picker.click()
	const firstOption = page.locator('.sk-mention-opt').first()
	await expect(firstOption).toBeVisible()
	const name = ((await firstOption.locator('.sk-mention-name').textContent()) || '').trim()
	expect(name.length).toBeGreaterThan(0)
	await firstOption.click()

	// The editor now carries « @Name » (plain text the server matches by display name).
	await expect(pm).toContainText('@' + name)

	// Leave no trace: cancel without posting (no comment, no notification).
	await page.locator('.sk-comment-editwrap').getByText(/Annuler/i).click()
})
