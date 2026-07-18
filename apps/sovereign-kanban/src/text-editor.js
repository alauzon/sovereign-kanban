/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Dynamically load Nextcloud's Text editor (the same rich Markdown editor Deck
 * uses) at runtime. Returns its createEditor factory, or null if the Text module
 * isn't available — callers fall back to a plain textarea. Ported verbatim from
 * the vanilla app so both frontends share one loading path.
 */

let textEditorPromise = null

/**
 * @return {Promise<Function|null>} createEditor({el, content, useSession,
 *   autofocus, onUpdate}) → editor instance, or null when Text isn't loadable.
 */
export function loadTextEditor() {
	if (textEditorPromise) {
		return textEditorPromise
	}
	const base = (window.OC && window.OC.getRootPath) ? window.OC.getRootPath() : ''
	// ?skv busts a stale browser cache: the .mjs was cached for months with a
	// wrong MIME (octet-stream) before the nginx fix.
	const url = base + '/apps/text/js/text-editor.mjs?skv=2'
	textEditorPromise = import(/* @vite-ignore */ url)
		.then((mod) => {
			if (mod && typeof mod.createEditor === 'function') {
				return mod.createEditor
			}
			if (window.OCA && window.OCA.Text && window.OCA.Text.createEditor) {
				return window.OCA.Text.createEditor
			}
			return null
		})
		.catch(() => null)
	return textEditorPromise
}
