import { fileURLToPath } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

// Separate from vite.config.js (the build): here every @nextcloud/vue component
// resolves to a slot-only stub, so a .vue file mounts without pulling the real
// library (which does not load under jsdom). Network/router are mocked per-test.
const stub = fileURLToPath(new URL('./test/stubs/nc-component.js', import.meta.url))

export default defineConfig({
	plugins: [vue()],
	test: {
		environment: 'jsdom',
		include: ['test/**/*.spec.js'],
	},
	resolve: {
		alias: [
			{ find: /^@nextcloud\/vue\/components\/.*/, replacement: stub },
		],
	},
})
