<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - The board filter row (Alain, 2026-07-18). Opens under the title and stays open
  - until its ✕. One chip group per dimension (labels, assignees, phase, priority):
  - clicking a chip toggles it. Logic: OR within a dimension, AND between
  - dimensions — the mental model everyone already has, covering the "et/ou" cases
  - without a condition tree. « Remise à 0 » clears all. Selections persist across
  - boards (App stores them in localStorage).
-->
<template>
	<div class="sk-filterbar">
		<div
			v-for="dim in dimensions"
			:key="dim.key"
			class="sk-filter-group">
			<span class="sk-filter-label">{{ dim.label }}</span>
			<button
				v-for="opt in dim.options"
				:key="opt.value"
				type="button"
				class="sk-filter-chip"
				:class="{ 'sk-filter-chip--on': isSelected(dim.key, opt.value) }"
				:style="isSelected(dim.key, opt.value) ? opt.style : null"
				@click="$emit('toggle', dim.key, opt.value)">
				{{ opt.label }}
			</button>
			<span v-if="!dim.options.length" class="sk-filter-empty">—</span>
		</div>

		<div class="sk-filter-actions">
			<NcButton type="tertiary" :disabled="!hasActive" @click="$emit('reset')">
				{{ t('Remise à 0') }}
			</NcButton>
			<NcButton type="tertiary" :aria-label="t('Fermer les filtres')" :title="t('Fermer les filtres')" @click="$emit('close')">
				✕
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

export default {
	name: 'FilterBar',

	components: { NcButton },

	props: {
		// [{ key, label, options: [{ value, label, style? }] }]
		dimensions: { type: Array, default: () => [] },
		// { tags: [], assignees: [], phases: [], priorities: [] }
		selected: { type: Object, required: true },
	},

	emits: ['toggle', 'reset', 'close'],

	computed: {
		hasActive() {
			return Object.values(this.selected).some((arr) => arr && arr.length)
		},
	},

	methods: {
		t(s) {
			return s
		},

		isSelected(key, value) {
			return (this.selected[key] || []).includes(value)
		},
	},
}
</script>

<style scoped>
.sk-filterbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px 16px;
	padding: 8px 24px 8px 52px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.sk-filter-group {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
}

.sk-filter-label {
	color: var(--color-text-maxcontrast);
	font-size: 85%;
	margin-right: 2px;
}

.sk-filter-chip {
	font-size: 85%;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 1px 10px;
	cursor: pointer;
}

.sk-filter-chip--on {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.sk-filter-empty {
	color: var(--color-text-maxcontrast);
}

.sk-filter-actions {
	display: flex;
	gap: 4px;
	margin-left: auto;
}
</style>
