<script setup lang="ts">
/** The search box above an admin table, optionally labelled when it shares a row with filters. */
import { useId } from 'vue'
import { byPrefixAndName } from '@/fontawesome'

defineProps<{ placeholder: string; label?: string }>()
const search = defineModel<string>({ required: true })
const inputId = useId()
</script>

<template>
  <div class="field">
    <label v-if="label" :for="inputId" class="label">{{ label }}</label>
    <div class="control has-icons-left">
      <input
        :id="inputId"
        v-model="search"
        class="input"
        type="text"
        :placeholder="placeholder"
        :aria-label="label ? undefined : placeholder"
      />
      <span class="icon is-small is-left"
        ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']"
      /></span>
    </div>
  </div>
</template>
