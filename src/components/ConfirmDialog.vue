<script setup lang="ts">
import BaseModal from './BaseModal.vue'
import { useConfirm } from '@/composables/useConfirm'

// Single shared instance, mounted once in App.vue. Reads the queued request
// from useConfirm() and resolves the awaiting caller on the user's choice.
const { state, settle } = useConfirm()
</script>

<template>
    <BaseModal :active="state.active" :title="state.title" @close="settle(false)">
        <p>{{ state.message }}</p>

        <template #footer>
            <button class="button" type="button" @click="settle(false)">
                {{ state.cancelLabel }}
            </button>
            <button
                class="button"
                type="button"
                :class="state.danger ? 'is-danger' : 'is-primary'"
                @click="settle(true)"
            >
                {{ state.confirmLabel }}
            </button>
        </template>
    </BaseModal>
</template>
