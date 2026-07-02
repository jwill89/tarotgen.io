<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
defineProps<{ message?: string }>()
</script>

<template>
  <div class="loading-overlay">
    <div class="has-text-centered">
      <span class="icon is-large has-text-white">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin size="3x" />
      </span>
      <p class="has-text-white mt-3 is-size-5">{{ message ?? 'Loading...' }}</p>
    </div>
  </div>
</template>
