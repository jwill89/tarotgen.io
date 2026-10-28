<script setup lang="ts">
/** The search box above an admin table, optionally labelled when it shares a row with filters. */
import { byPrefixAndName } from '@/fontawesome'

defineProps<{ placeholder: string; label?: string }>()
const search = defineModel<string>({ required: true })
</script>

<template>
  <div class="field">
    <label v-if="label" class="label">{{ label }}</label>
    <div class="control has-icons-left">
      <input v-model="search" class="input" type="text" :placeholder="placeholder" />
      <span class="icon is-small is-left"
        ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']"
      /></span>
    </div>
  </div>
</template>
