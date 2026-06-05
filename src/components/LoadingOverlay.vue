<script setup lang="ts">
defineProps<{ message?: string }>()
</script>

<template>
    <div class="loading-overlay">
        <div class="has-text-centered">
            <span class="icon is-large has-text-white">
                <i class="fa-solid fa-spinner fa-spin fa-3x"></i>
            </span>
            <p class="has-text-white mt-3 is-size-5">{{ message ?? 'Loading...' }}</p>
        </div>
    </div>
</template>
