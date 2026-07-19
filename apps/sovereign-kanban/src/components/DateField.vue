<!--
  - @copyright 2026 Alain Lauzon
  - @license AGPL-3.0-or-later
  -
  - A single optional date field à la Deck (Alain, 2026-07-18): when empty, a
  - "＋ <label>" button adds it; when set, a datetime-local input plus a menu with
  - quick suggestions (tomorrow, next week) and "Retirer". v-model is a
  - datetime-local string ('YYYY-MM-DDTHH:MM') or '' for no date.
-->
<template>
	<div class="sk-datefield">
		<span class="sk-datefield-label">{{ label }}</span>
		<template v-if="modelValue">
			<div class="sk-datefield-input">
				<input
					type="datetime-local"
					:value="modelValue"
					:disabled="disabled"
					@input="$emit('update:modelValue', $event.target.value)">
				<NcButton
					v-if="!disabled"
					type="tertiary"
					:aria-label="t('Retirer la date')"
					:title="t('Retirer la date')"
					@click="$emit('update:modelValue', '')">
					✕
				</NcButton>
			</div>
			<div v-if="!disabled" class="sk-datefield-suggest">
				<button type="button" class="sk-datefield-chip" @click="$emit('update:modelValue', suggestTomorrow())">
					{{ t('Demain') }}
				</button>
				<button type="button" class="sk-datefield-chip" @click="$emit('update:modelValue', suggestNextWeek())">
					{{ t('Semaine prochaine') }}
				</button>
			</div>
		</template>
		<NcButton
			v-else-if="!disabled"
			type="tertiary"
			class="sk-datefield-add"
			@click="$emit('update:modelValue', suggestTomorrow())">
			＋ {{ label }}
		</NcButton>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

const pad = (n) => String(n).padStart(2, '0')

export default {
	name: 'DateField',

	components: { NcButton },

	props: {
		modelValue: { type: String, default: '' },
		label: { type: String, required: true },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	methods: {
		t(s) {
			return s
		},

		// Date → 'YYYY-MM-DDTHH:MM' in local time (what datetime-local wants).
		toInput(d) {
			return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
		},

		suggestTomorrow() {
			const d = new Date()
			d.setDate(d.getDate() + 1)
			d.setHours(8, 0, 0, 0)
			return this.toInput(d)
		},

		suggestNextWeek() {
			const d = new Date()
			const daysUntilNextMonday = ((8 - d.getDay()) % 7) || 7
			d.setDate(d.getDate() + daysUntilNextMonday)
			d.setHours(8, 0, 0, 0)
			return this.toInput(d)
		},
	},
}
</script>

<style scoped>
.sk-datefield {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.sk-datefield-label {
	color: var(--color-text-maxcontrast);
	font-size: 90%;
}

.sk-datefield-input {
	display: flex;
	align-items: center;
	gap: 4px;
}

.sk-datefield-input > input {
	flex: 1 1 auto;
	min-width: 0;
	box-sizing: border-box;
}

.sk-datefield-suggest {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 4px;
}

.sk-datefield-chip {
	font-size: 85%;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 1px 10px;
	cursor: pointer;
}

.sk-datefield-chip:hover {
	background: var(--color-background-hover);
}

.sk-datefield-add {
	align-self: flex-start;
}
</style>
