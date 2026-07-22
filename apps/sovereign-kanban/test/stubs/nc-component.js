// Generic stub for every @nextcloud/vue component in unit tests: renders its
// default slot and swallows props/events. We test OUR logic (wiring, emits),
// not @nextcloud/vue's rendering — that's Playwright's job.
export default {
	name: 'NcStub',
	render() {
		return this.$slots.default ? this.$slots.default() : null
	},
}
