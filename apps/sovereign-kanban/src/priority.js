/**
 * @copyright 2026 Alain Lauzon
 * @license AGPL-3.0-or-later
 *
 * Priority signs (Alain, 2026-07-18), following the Obsidian Tasks convention
 * since the vault lives in Obsidian: 1 = most urgent … 5 = lowest. The number is
 * kept next to the sign so the meaning is never ambiguous.
 */

export const PRIORITY_SIGNS = {
	1: '🔺',
	2: '⏫',
	3: '🔼',
	4: '🔽',
	5: '⏬',
}

/**
 * Label for a priority value: "🔺 1", "🔼 3"… Falls back to the bare value for
 * anything outside 1–5.
 *
 * @param {string|number} p priority value
 * @return {string}
 */
export function prioLabel(p) {
	if (p === null || p === undefined || p === '') {
		return ''
	}
	const sign = PRIORITY_SIGNS[p] || PRIORITY_SIGNS[String(p)]
	return sign ? sign + ' ' + p : String(p)
}
