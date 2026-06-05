<script setup lang="ts">
import { ref, computed, onMounted, useTemplateRef } from 'vue'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { useToasts } from '@/composables/useToasts'
import { useConfirm } from '@/composables/useConfirm'
import { formatDateTime } from '@/utils/datetime'
import type { UserSpread, Spread, SpreadPosition } from '@/types'
import BaseModal from '@/components/BaseModal.vue'
import SpreadEditor from '@/components/admin/SpreadEditor.vue'

const { userSpreads, fetchUserSpreads, updateUserSpread, deleteUserSpread, submitAsPublic } = useUserSpreads()
const toasts = useToasts()
const { confirm } = useConfirm()

const loading = ref(true)
const showEditor = ref(false)
const editingSpread = ref<UserSpread | null>(null)
const editorRef = useTemplateRef<InstanceType<typeof SpreadEditor>>('editorRef')

onMounted(async () => {
    await fetchUserSpreads()
    loading.value = false
})

function openEdit(spread: UserSpread) {
    editingSpread.value = spread
    showEditor.value = true
}

/** Map UserSpread → Spread-compatible object for the SpreadEditor. */
const editorSpread = computed<Spread | null>(() => {
    if (!editingSpread.value) return null
    return {
        spread_id: 0,
        name: editingSpread.value.name,
        description: editingSpread.value.description,
        card_count: editingSpread.value.card_count,
        positions: editingSpread.value.positions,
    }
})

function closeEditor() {
    showEditor.value = false
    editingSpread.value = null
}

async function saveEdit(payload: { name: string; description: string; card_count: number; positions: SpreadPosition[] }) {
    if (!editingSpread.value) return
    const updated = await updateUserSpread(editingSpread.value.user_spread_id, payload)
    if (updated) {
        toasts.success('Spread updated successfully.')
        closeEditor()
    } else {
        editorRef.value?.setError('Failed to save changes. Please try again.')
    }
}

async function handleDelete(spread: UserSpread) {
    const ok = await confirm({
        title: 'Delete Spread',
        message: `Are you sure you want to delete "${spread.name}"? This cannot be undone.`,
        confirmLabel: 'Delete',
        danger: true,
    })
    if (!ok) return
    const success = await deleteUserSpread(spread.user_spread_id)
    if (success) {
        toasts.success('Spread deleted.')
    } else {
        toasts.error('Failed to delete the spread.')
    }
}

async function handleSubmitPublic(spread: UserSpread) {
    const ok = await confirm({
        title: 'Submit as Public Spread',
        message: `Submit "${spread.name}" for admin review? If approved, it will become available to all users. Your personal copy will remain unchanged.`,
        confirmLabel: 'Submit for Review',
    })
    if (!ok) return
    const success = await submitAsPublic(spread.user_spread_id)
    if (success) {
        toasts.success('Spread submitted for review!')
    } else {
        toasts.error('Failed to submit the spread. Please try again.')
    }
}
</script>

<template>
    <section class="section">
        <div class="container">
            <div class="columns is-centered">
                <div class="column is-10-desktop">
                    <router-link :to="{ name: 'dashboard' }" class="button is-small is-ghost mb-4">
                        <span class="icon"><i class="fa-solid fa-arrow-left"></i></span>
                        <span>Back to Dashboard</span>
                    </router-link>

                    <div class="level">
                        <div class="level-left">
                            <div>
                                <h1 class="title is-3 is-size-4-mobile">My Spreads</h1>
                                <p class="subtitle is-5 is-size-6-mobile">Manage your personal tarot spreads.</p>
                            </div>
                        </div>
                        <div class="level-right">
                            <router-link :to="{ name: 'submit-spread' }" class="button is-primary">
                                <span class="icon"><i class="fa-solid fa-plus"></i></span>
                                <span>Create Spread</span>
                            </router-link>
                        </div>
                    </div>

                    <div v-if="loading" class="has-text-centered py-6">
                        <span class="icon is-large"><i class="fa-solid fa-spinner fa-spin fa-2x"></i></span>
                    </div>

                    <div v-else-if="userSpreads.length === 0" class="notification is-info is-light">
                        <p>You haven't created any personal spreads yet.</p>
                        <p class="mt-2">
                            <router-link :to="{ name: 'submit-spread' }">Create your first spread</router-link>
                            and choose "Save to my personal spreads" to keep it private.
                        </p>
                    </div>

                    <div v-else class="table-container">
                        <table class="table is-fullwidth is-hoverable">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Cards</th>
                                    <th>Created</th>
                                    <th>Updated</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="spread in userSpreads" :key="spread.user_spread_id">
                                    <td>
                                        <strong>{{ spread.name }}</strong>
                                        <p v-if="spread.description" class="is-size-7 has-text-grey mt-1" style="max-width: 20rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            {{ spread.description }}
                                        </p>
                                    </td>
                                    <td>{{ spread.card_count }}</td>
                                    <td class="is-size-7">{{ formatDateTime(spread.created_at) }}</td>
                                    <td class="is-size-7">{{ formatDateTime(spread.updated_at) }}</td>
                                    <td class="has-text-right">
                                        <div class="buttons are-small is-justify-content-flex-end">
                                            <button class="button is-info is-outlined" @click="openEdit(spread)" title="Edit">
                                                <span class="icon"><i class="fa-solid fa-pen"></i></span>
                                            </button>
                                            <button class="button is-link is-outlined" @click="handleSubmitPublic(spread)" title="Submit as public spread">
                                                <span class="icon"><i class="fa-solid fa-paper-plane"></i></span>
                                            </button>
                                            <button class="button is-danger is-outlined" @click="handleDelete(spread)" title="Delete">
                                                <span class="icon"><i class="fa-solid fa-trash"></i></span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Edit spread modal -->
    <BaseModal
        :active="showEditor"
        title="Edit Spread"
        max-width="60rem"
        @close="closeEditor"
    >
        <SpreadEditor
            v-if="editorSpread"
            ref="editorRef"
            :spread="editorSpread"
            save-label="Save Changes"
            @save="saveEdit"
            @cancel="closeEditor"
        />
    </BaseModal>
</template>





