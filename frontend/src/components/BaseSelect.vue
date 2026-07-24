<script setup lang="ts">
/**
 * Single-select dropdown built on Reka UI's Select, styled to match the app's
 * comboboxes. Drop-in replacement for a Bulma `<div class="select"><select>`:
 * pass `options` ([{ value, label, disabled? }]) and use v-model. The listbox is
 * portaled to <body>, so its styling lives in the global .myst-select* rules.
 *
 *   <BaseSelect v-model="systemId" :options="systems" placeholder="Choose a system" />
 */
import { byPrefixAndName } from '@/fontawesome'
import {
  SelectRoot,
  SelectTrigger,
  SelectValue,
  SelectIcon,
  SelectPortal,
  SelectContent,
  SelectViewport,
  SelectItem,
  SelectItemIndicator,
  SelectItemText,
} from 'reka-ui'

type SelectValueType = string | number

interface SelectOption {
  value: SelectValueType
  label: string
  disabled?: boolean
}

defineProps<{
  options: SelectOption[]
  placeholder?: string
  disabled?: boolean
  ariaLabel?: string
}>()

const model = defineModel<SelectValueType | null>()
</script>

<template>
  <SelectRoot v-model="model" :disabled="disabled">
    <SelectTrigger class="myst-select" :aria-label="ariaLabel">
      <SelectValue :placeholder="placeholder ?? 'Select…'" />
      <SelectIcon class="myst-select__icon">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['chevron-down']" />
      </SelectIcon>
    </SelectTrigger>
    <SelectPortal>
      <SelectContent class="myst-select__content" position="popper" :side-offset="4">
        <SelectViewport class="myst-select__viewport">
          <SelectItem
            v-for="opt in options"
            :key="String(opt.value)"
            :value="opt.value"
            :disabled="opt.disabled"
            class="myst-select__item"
          >
            <SelectItemText>{{ opt.label }}</SelectItemText>
            <SelectItemIndicator class="myst-select__check">
              <FontAwesomeIcon :icon="byPrefixAndName.fas['check']" />
            </SelectItemIndicator>
          </SelectItem>
        </SelectViewport>
      </SelectContent>
    </SelectPortal>
  </SelectRoot>
</template>
