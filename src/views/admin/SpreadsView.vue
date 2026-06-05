<script setup lang="ts">
import { ref, onMounted, useTemplateRef } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { useConfirm } from '@/composables/useConfirm'
import { useDataTable } from '@/composables/useDataTable'
import BaseModal from '@/components/BaseModal.vue'
import SortableTh from '@/components/admin/SortableTh.vue'
import type { PendingSpread, Spread, SpreadPosition } from '@/types'
import SpreadEditor from '@/components/admin/SpreadEditor.vue'
import { formatDateTime } from '@/utils/datetime'

const api = useAdminApi()
const { confirm } = useConfirm()

const spreads = ref<Spread[]>([])
const pending = ref<PendingSpread[]>([])
const mode = ref<'list' | 'edit'>('list')
const editing = ref<Spread | null>(null)
const previewing = ref<PendingSpread | null>(null)
const busyPendingId = ref<number | null>(null)
const editorRef = useTemplateRef<InstanceType<typeof SpreadEditor>>('editorRef')

const { search, sortKey, sortDir, rows: visibleSpreads, toggleSort } = useDataTable(spreads, {
    searchText: s => `${s.name} ${s.spread_id}`,
    sortAccessors: {
        spread_id: s => s.spread_id,
        name: s => s.name,
        card_count: s => s.card_count,
    },
    initialSort: 'spread_id',
})

async function fetchSpreads() {
    const data = await api.get<Spread[]>('/spreads')
    if (data) spreads.value = data
}

async function fetchPending() {
    const data = await api.get<PendingSpread[]>('/pending-spreads')
    if (data) pending.value = data
}

function sortedPositions(p: PendingSpread): SpreadPosition[] {
    return p.positions.slice().sort((a, b) => a.order - b.order)
}

async function approvePending(p: PendingSpread) {
    busyPendingId.value = p.pending_id
    const result = await api.post<Spread>('/pending-spreads/' + p.pending_id + '/approve', {}, 'Spread approved and published.')
    busyPendingId.value = null
    if (result) {
        if (previewing.value?.pending_id === p.pending_id) previewing.value = null
        await Promise.all([fetchSpreads(), fetchPending()])
    }
}

async function rejectPending(p: PendingSpread) {
    const ok = await confirm({
        title: 'Reject submission',
        message: `Reject and permanently delete the submission "${p.name}"?`,
        confirmLabel: 'Reject',
        danger: true,
    })
    if (!ok) return
    busyPendingId.value = p.pending_id
    const result = await api.del('/pending-spreads/' + p.pending_id, 'Submission rejected.')
    busyPendingId.value = null
    if (!result) return
    if (previewing.value?.pending_id === p.pending_id) previewing.value = null
    await fetchPending()
}

function openAdd() {
    editing.value = null
    mode.value = 'edit'
}

function openEdit(spread: Spread) {
    editing.value = spread
    mode.value = 'edit'
}

function cancelEdit() {
    mode.value = 'list'
    editing.value = null
}

async function saveSpread(payload: { name: string; description: string; card_count: number; positions: SpreadPosition[] }) {
    let result: Spread | null
    if (editing.value) {
        result = await api.put<Spread>('/spreads/' + editing.value.spread_id, payload, 'Spread updated.')
    } else {
        result = await api.post<Spread>('/spreads', payload, 'Spread created.')
    }

    if (result) {
        await fetchSpreads()
        mode.value = 'list'
        editing.value = null
    } else {
        editorRef.value?.setError('Failed to save the spread. Please try again.')
    }
}

async function deleteSpread(spread: Spread) {
    const ok = await confirm({
        title: 'Delete spread',
        message: `Delete "${spread.name}"? Existing readings that used it keep their own saved copy and are unaffected.`,
        confirmLabel: 'Delete',
        danger: true,
    })
    if (!ok) return
    const result = await api.del('/spreads/' + spread.spread_id, 'Spread deleted.')
    if (result) await fetchSpreads()
}

onMounted(() => {
    fetchSpreads()
    fetchPending()
})
</script>

<template>
    <section class="section">
        <div class="container">
            <router-link :to="{ name: 'admin-dashboard' }" class="button is-small is-ghost mb-4">
                <span class="icon"><i class="fa-solid fa-arrow-left"></i></span>
                <span>Back to Dashboard</span>
            </router-link>

            <!-- List mode -->
            <template v-if="mode === 'list'">
                <div class="level">
                    <div class="level-left">
                        <div>
                            <h1 class="title is-3">Manage Spreads</h1>
                            <p class="subtitle is-5">Create and arrange tarot spreads.</p>
                        </div>
                    </div>
                    <div class="level-right">
                        <button class="button is-primary" @click="openAdd">
                            <span class="icon"><i class="fa-solid fa-plus"></i></span>
                            <span>Add Spread</span>
                        </button>
                    </div>
                </div>

                <div class="field">
                    <div class="control has-icons-left">
                        <input class="input" type="text" v-model="search" placeholder="Search spreads by name or ID..." />
                        <span class="icon is-small is-left"><i class="fa-solid fa-magnifying-glass"></i></span>
                    </div>
                </div>

                <div class="table-container">
                    <table class="table is-fullwidth is-hoverable is-striped">
                        <thead>
                            <tr>
                                <SortableTh label="ID" sort-key="spread_id" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                                <SortableTh label="Name" sort-key="name" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                                <SortableTh label="Cards" sort-key="card_count" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="spread in visibleSpreads" :key="spread.spread_id">
                                <td>{{ spread.spread_id }}</td>
                                <td>{{ spread.name }}</td>
                                <td>{{ spread.card_count }}</td>
                                <td>
                                    <div class="buttons are-small">
                                        <button class="button is-info" @click="openEdit(spread)">
                                            <span class="icon"><i class="fa-solid fa-pen-to-square"></i></span>
                                            <span>Edit</span>
                                        </button>
                                        <button class="button is-danger" @click="deleteSpread(spread)">
                                            <span class="icon"><i class="fa-solid fa-trash"></i></span>
                                            <span>Delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="has-text-grey" v-if="spreads.length === 0">No spreads yet. Click "Add Spread" to create one.</p>

                <!-- Approval queue -->
                <div class="mt-6">
                    <h2 class="title is-4">
                        Submission Queue
                        <span v-if="pending.length" class="tag is-warning is-medium ml-2">{{ pending.length }}</span>
                    </h2>
                    <p class="subtitle is-6">Review user-submitted spreads. Approving copies the spread into the list above.</p>

                    <p class="has-text-grey" v-if="pending.length === 0">No pending submissions right now.</p>

                    <div class="table-container" v-else>
                        <table class="table is-fullwidth is-hoverable is-striped">
                            <thead>
                                <tr>
                                    <th>Submitted</th>
                                    <th>Name</th>
                                    <th>By</th>
                                    <th>Cards</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in pending" :key="p.pending_id">
                                    <td>{{ formatDateTime(p.submitted_at) }}</td>
                                    <td>{{ p.name }}</td>
                                    <td>{{ p.submitter || '—' }}</td>
                                    <td>{{ p.card_count }}</td>
                                    <td>
                                        <div class="buttons are-small">
                                            <button class="button" @click="previewing = p">
                                                <span class="icon"><i class="fa-solid fa-eye"></i></span>
                                                <span>Preview</span>
                                            </button>
                                            <button
                                                class="button is-success"
                                                :class="{ 'is-loading': busyPendingId === p.pending_id }"
                                                @click="approvePending(p)"
                                            >
                                                <span class="icon"><i class="fa-solid fa-check"></i></span>
                                                <span>Approve</span>
                                            </button>
                                            <button class="button is-danger" @click="rejectPending(p)">
                                                <span class="icon"><i class="fa-solid fa-xmark"></i></span>
                                                <span>Reject</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <!-- Edit mode -->
            <template v-else>
                <h1 class="title is-3">{{ editing ? 'Edit Spread' : 'Add Spread' }}</h1>
                <SpreadEditor
                    ref="editorRef"
                    :spread="editing"
                    @save="saveSpread"
                    @cancel="cancelEdit"
                />
            </template>
        </div>
    </section>

    <!-- Pending Spread Preview -->
    <BaseModal
        :active="previewing !== null"
        :title="previewing?.name ?? ''"
        max-width="640px"
        @close="previewing = null"
    >
        <template v-if="previewing">
            <p class="has-text-grey mb-3" v-if="previewing.submitter">Submitted by {{ previewing.submitter }}</p>
            <div class="spread-canvas spread-canvas--preview has-grid">
                <div
                    v-for="pos in sortedPositions(previewing)"
                    :key="'preview-' + pos.order"
                    class="spread-card spread-card--editor"
                    :style="{ left: pos.x + '%', top: pos.y + '%', '--rotation': pos.rotation + 'deg' }"
                >
                    {{ pos.order }}
                </div>
            </div>
            <div class="mt-4">
                <p class="label mb-2">Card Positions</p>
                <ol class="pending-position-list">
                    <li v-for="pos in sortedPositions(previewing)" :key="'title-' + pos.order">
                        {{ pos.title || '(untitled)' }}
                    </li>
                </ol>
            </div>

            <div class="content mt-4" v-if="previewing.description" style="white-space: pre-wrap;">{{ previewing.description }}</div>
        </template>

        <template #footer v-if="previewing">
            <button
                class="button is-success"
                :class="{ 'is-loading': busyPendingId === previewing.pending_id }"
                @click="approvePending(previewing)"
            >
                <span class="icon"><i class="fa-solid fa-check"></i></span>
                <span>Approve</span>
            </button>
            <button class="button is-danger" @click="rejectPending(previewing)">
                <span class="icon"><i class="fa-solid fa-xmark"></i></span>
                <span>Reject</span>
            </button>
            <button class="button" @click="previewing = null">Close</button>
        </template>
    </BaseModal>
</template>

<style scoped>
.pending-position-list {
    list-style: decimal;
    margin-left: 1.5rem;
}

.pending-position-list li {
    margin-bottom: 0.25rem;
}
</style>
