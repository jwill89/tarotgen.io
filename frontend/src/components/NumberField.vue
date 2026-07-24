<script setup lang="ts">
/**
 * Numeric input with −/+ steppers, built on Reka UI's NumberField. Drop-in for
 * the plain `<input type="number">` fields, with clamping to min/max, keyboard
 * increment, and the mystical styling. Usage:
 *   <NumberField v-model="count" :min="1" :max="78" />
 */
import {
  NumberFieldRoot,
  NumberFieldDecrement,
  NumberFieldInput,
  NumberFieldIncrement,
} from 'reka-ui'

const model = defineModel<number>()

withDefaults(
  defineProps<{
    min?: number
    max?: number
    step?: number
    disabled?: boolean
  }>(),
  // min/max are intentionally undefined by default — Reka treats an absent
  // bound as unbounded, so there is no sensible numeric default to supply.
  { step: 1, min: undefined, max: undefined },
)
</script>

<template>
  <NumberFieldRoot
    v-model="model"
    :min="min"
    :max="max"
    :step="step"
    :disabled="disabled"
    class="myst-number"
  >
    <NumberFieldDecrement class="myst-number__btn" aria-label="Decrease">−</NumberFieldDecrement>
    <NumberFieldInput class="myst-number__input" />
    <NumberFieldIncrement class="myst-number__btn" aria-label="Increase">+</NumberFieldIncrement>
  </NumberFieldRoot>
</template>

<style scoped>
.myst-number {
  display: inline-flex;
  align-items: stretch;
  height: 2.5em;
  max-width: 100%;
  background: var(--myst-surface-2);
  border: 1px solid var(--myst-border-strong);
  border-radius: var(--bulma-radius);
  overflow: hidden;
  transition:
    border-color 0.2s ease,
    box-shadow 0.2s ease;
}
.myst-number:focus-within {
  border-color: var(--myst-hair-gold);
  box-shadow: 0 0 0 3px rgba(201, 162, 75, 0.12);
}
.myst-number[data-disabled] {
  opacity: 0.55;
}

.myst-number__input {
  width: 3.5em;
  min-width: 0;
  border: 0;
  background: transparent;
  color: var(--myst-text);
  text-align: center;
  font-family: inherit;
  font-size: 1rem;
  font-variant-numeric: tabular-nums;
  -moz-appearance: textfield;
  appearance: textfield;
}
.myst-number__input:focus {
  outline: none;
}
.myst-number__input::-webkit-inner-spin-button,
.myst-number__input::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

.myst-number__btn {
  flex: none;
  width: 2.4em;
  border: 0;
  background: var(--myst-surface-3);
  color: var(--myst-text-muted);
  cursor: pointer;
  font-size: 1.2rem;
  line-height: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  user-select: none;
  transition:
    background 0.15s ease,
    color 0.15s ease;
}
.myst-number__btn:first-child {
  border-right: 1px solid var(--myst-border);
}
.myst-number__btn:last-child {
  border-left: 1px solid var(--myst-border);
}
.myst-number__btn:hover:not([data-disabled]) {
  background: var(--myst-surface);
  color: var(--myst-gold-bright);
}
.myst-number__btn:focus-visible {
  outline: none;
  color: var(--myst-gold-bright);
  box-shadow: inset 0 0 0 2px rgba(201, 162, 75, 0.4);
}
.myst-number__btn[data-disabled] {
  opacity: 0.4;
  cursor: not-allowed;
}
</style>
