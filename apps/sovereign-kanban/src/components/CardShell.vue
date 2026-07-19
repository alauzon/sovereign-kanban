<!--
  Wrapper for the card editor (Alain, 2026-07-19): above a certain width the card
  docks to the right of the board (a plain passthrough div, positioned by the
  parent's split layout); below it, it stays a centered NcModal overlay. Keeping
  the switch here lets CardDetail hold its content once, unduplicated.
-->
<template>
	<div v-if="docked" class="sk-card-shell-docked">
		<slot />
	</div>
	<NcModal
		v-else
		:size="expanded ? 'full' : 'large'"
		:can-close="false"
		@close="$emit('close')">
		<slot />
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'

export default {
	name: 'CardShell',

	components: { NcModal },

	props: {
		docked: { type: Boolean, default: false },
		expanded: { type: Boolean, default: false },
	},

	emits: ['close'],
}
</script>

<style scoped>
.sk-card-shell-docked {
	height: 100%;
	min-height: 0;
	display: flex;
	flex-direction: column;
}
</style>
