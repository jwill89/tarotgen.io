<script setup lang="ts">
/**
 * Reusable on/off toggle built on Reka UI's Switch. Replaces the hand-rolled
 * `<label class="toggle-switch">` + hidden-checkbox markup that was duplicated
 * across many views. The label text goes in the default slot and stays clickable
 * (associated to the switch via a generated id).
 *
 *   <ToggleSwitch v-model="flag">Hide my display name</ToggleSwitch>
 */
import { useId } from 'vue'
import { SwitchRoot, SwitchThumb } from 'reka-ui'

const model = defineModel<boolean>({ default: false })
defineProps<{ compact?: boolean }>()
const id = useId()
</script>

<template>
  <div class="toggle-switch" :class="{ 'is-compact': compact }">
    <SwitchRoot :id="id" v-model="model" class="toggle-track">
      <SwitchThumb class="toggle-thumb" />
    </SwitchRoot>
    <label v-if="$slots.default" :for="id" class="toggle-state" :class="{ 'is-on': model }">
      <slot />
    </label>
  </div>
</template>
