<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - Phase 1 placeholder. Its only job is to prove the toolchain compiles an SFC
  - and resolves real @nextcloud/vue components. The actual shell —
  - NcAppNavigation listing the boards, and the death of the home-made
  - « 🗂️ Sovereign Kanban » header — is phase 2, and belongs to the circle's
  - leader, not to this file.
  -->
<template>
	<NcAppContent>
		<NcEmptyContent name="Sovereign Kanban — shell Vue">
			<template #description>
				Phase 1 : la chaîne de build fonctionne. Le shell arrive en phase 2.
			</template>
		</NcEmptyContent>
	</NcAppContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

export default {
	name: 'App',
	components: {
		NcAppContent,
		NcEmptyContent,
	},
}
</script>
