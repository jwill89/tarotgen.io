<script setup lang="ts">
import { ref, computed, nextTick, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { useConfirm } from '@/composables/useConfirm'
import { useDataTable } from '@/composables/useDataTable'
import { useToasts } from '@/composables/useToasts'
import SortableTh from '@/components/admin/SortableTh.vue'
import type { DeckSystem, DeckSystemWithCards, DeckSystemCard } from '@/types'

const api = useAdminApi()
const { confirm } = useConfirm()
const toasts = useToasts()

const systems = ref<DeckSystem[]>([])
const pendingSystems = ref<DeckSystem[]>([])
const editingSystem = ref<Partial<DeckSystemWithCards> | null>(null)
const isNew = ref(false)
const saving = ref(false)

// Card editor state
const expandedCardIndex = ref(0)
const cardTitleRefs = ref<(HTMLInputElement | null)[]>([])

const { search, sortKey, sortDir, rows: visibleSystems, toggleSort } = useDataTable(systems, {
    searchText: s => `${s.name} ${s.short_name} ${s.deck_system_id}`,
    sortAccessors: {
        deck_system_id: s => s.deck_system_id,
        name: s => s.name,
        short_name: s => s.short_name,
        total_cards: s => s.total_cards,
    },
    initialSort: 'name',
})

const allCardTitlesValid = computed(() => {
    if (!editingSystem.value?.cards) return false
    return editingSystem.value.cards.every(c => c.name?.trim())
})

function emptyCard(cardId: number): DeckSystemCard {
    return {
        deck_system_id: 0,
        card_id: cardId,
        name: '',
        keywords: null,
        meaning: null,
        advice: null,
        reversed_keywords: null,
        reversed_meaning: null,
        reversed_advice: null,
    }
}

async function fetchSystems() {
    const data = await api.get<DeckSystem[]>('/deck-systems')
    if (data) systems.value = data
}

async function fetchPendingSystems() {
    const data = await api.get<DeckSystem[]>('/deck-systems/pending')
    if (data) pendingSystems.value = data
}

function openAdd() {
    isNew.value = true
    expandedCardIndex.value = 0
    editingSystem.value = {
        name: '',
        short_name: '',
        total_cards: 78,
        cards: Array.from({ length: 78 }, (_, i) => emptyCard(i + 1)),
    }
}

async function openEdit(system: DeckSystem) {
    isNew.value = false
    expandedCardIndex.value = -1
    const full = await api.get<DeckSystemWithCards>('/deck-systems/' + system.deck_system_id)
    if (full) {
        editingSystem.value = { ...full }
    }
}

function closeEdit() {
    editingSystem.value = null
}

function updateCardCount() {
    if (!editingSystem.value) return
    const total = editingSystem.value.total_cards ?? 78
    const cards = editingSystem.value.cards ?? []

    if (cards.length < total) {
        for (let i = cards.length; i < total; i++) {
            cards.push(emptyCard(i + 1))
        }
    } else if (cards.length > total) {
        editingSystem.value.cards = cards.slice(0, total)
    }
}

function toggleCard(index: number) {
    expandedCardIndex.value = expandedCardIndex.value === index ? -1 : index
}

function markCardDone(index: number) {
    expandedCardIndex.value = -1
    const nextIndex = index + 1
    if (nextIndex < (editingSystem.value?.cards?.length ?? 0)) {
        nextTick(() => {
            expandedCardIndex.value = nextIndex
            nextTick(() => {
                cardTitleRefs.value[nextIndex]?.focus()
            })
        })
    }
}

function expandAllCards() {
    expandedCardIndex.value = -2
}

function collapseAllCards() {
    expandedCardIndex.value = -1
}

function isCardExpanded(index: number): boolean {
    return expandedCardIndex.value === -2 || expandedCardIndex.value === index
}

async function saveSystem() {
    if (!editingSystem.value) return

    // Validate card names
    const missingNames = (editingSystem.value.cards ?? []).filter(c => !c.name?.trim())
    if (missingNames.length > 0) {
        toasts.error(`${missingNames.length} card(s) are missing names.`)
        const firstMissing = (editingSystem.value.cards ?? []).findIndex(c => !c.name?.trim())
        if (firstMissing >= 0) {
            expandedCardIndex.value = firstMissing
            nextTick(() => {
                cardTitleRefs.value[firstMissing]?.focus()
            })
        }
        return
    }

    saving.value = true
    try {
        let result
        if (isNew.value) {
            const res = await fetch('/api/deck-system/submit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(editingSystem.value),
            })
            if (res.ok) {
                result = await res.json()
                toasts.success('Deck system created.')
            } else {
                const err = await res.json().catch(() => ({})) as { error?: string }
                toasts.error(err.error || 'Failed to create deck system.')
                return
            }
        } else {
            result = await api.put('/deck-systems/' + editingSystem.value.deck_system_id, editingSystem.value, 'Deck system updated.')
        }
        if (!result) return
        await fetchSystems()
        await fetchPendingSystems()
        closeEdit()
    } finally {
        saving.value = false
    }
}

async function approveSystem(system: DeckSystem) {
    const result = await api.post('/deck-systems/' + system.deck_system_id + '/approve', {}, 'Deck system approved.')
    if (result) {
        await fetchSystems()
        await fetchPendingSystems()
    }
}

async function deleteSystem(system: DeckSystem) {
    const ok = await confirm({
        title: 'Delete Deck System',
        message: `Delete "${system.name}"? This will also delete all card data for this system. Decks using this system will need to be reassigned.`,
        confirmLabel: 'Delete',
        danger: true,
    })
    if (!ok) return
    const result = await api.del('/deck-systems/' + system.deck_system_id, 'Deck system deleted.')
    if (result) {
        await fetchSystems()
        await fetchPendingSystems()
    }
}

onMounted(() => {
    fetchSystems()
    fetchPendingSystems()
})
</script>

<template>
    <section class="section">
        <div class="container">
            <router-link :to="{ name: 'admin-dashboard' }" class="button is-small is-ghost mb-4">
                <span class="icon"><i class="fa-solid fa-arrow-left"></i></span>
                <span>Back to Dashboard</span>
            </router-link>

            <div class="level">
                <div class="level-left">
                    <div>
                        <h1 class="title is-3">Deck Systems</h1>
                        <p class="subtitle is-5">Manage card naming systems (e.g. Rider-Waite-Smith, Thoth).</p>
                    </div>
                </div>
                <div class="level-right">
                    <button class="button is-primary" @click="openAdd" :disabled="editingSystem !== null">
                        <span class="icon"><i class="fa-solid fa-plus"></i></span>
                        <span>Add Deck System</span>
                    </button>
                </div>
            </div>

            <!-- Inline Add/Edit Form -->
            <div v-if="editingSystem" class="box mb-6 deck-system-editor">
                <div class="is-flex is-align-items-center is-justify-content-space-between mb-4">
                    <h3 class="title is-4 mb-0">{{ isNew ? 'Add Deck System' : 'Edit Deck System #' + editingSystem.deck_system_id }}</h3>
                    <button class="delete is-medium" aria-label="close" @click="closeEdit"></button>
                </div>

                <div class="columns">
                    <div class="column is-4">
                        <div class="field">
                            <label class="label">Name <span class="has-text-danger">*</span></label>
                            <input class="input" v-model="editingSystem.name" placeholder="e.g. Rider-Waite-Smith" />
                        </div>
                    </div>
                    <div class="column is-4">
                        <div class="field">
                            <label class="label">Short Name <span class="has-text-danger">*</span></label>
                            <input class="input" v-model="editingSystem.short_name" placeholder="e.g. RWS" />
                        </div>
                    </div>
                    <div class="column is-4">
                        <div class="field">
                            <label class="label">Total Cards <span class="has-text-danger">*</span></label>
                            <input class="input" type="number" v-model.number="editingSystem.total_cards" min="1" @change="updateCardCount" />
                        </div>
                    </div>
                </div>

                <div class="is-flex is-align-items-center is-justify-content-space-between mt-4 mb-3">
                    <h5 class="title is-5 mb-0">Card Definitions</h5>
                    <div class="buttons are-small mb-0">
                        <button type="button" class="button is-small is-ghost" @click="expandAllCards">
                            <span class="icon"><i class="fa-solid fa-angles-down"></i></span>
                            <span>Expand All</span>
                        </button>
                        <button type="button" class="button is-small is-ghost" @click="collapseAllCards">
                            <span class="icon"><i class="fa-solid fa-angles-up"></i></span>
                            <span>Collapse All</span>
                        </button>
                    </div>
                </div>
                <p class="mb-4 has-text-grey is-size-7">Each card needs a name at minimum. Other fields are optional.</p>

                <div class="deck-system-cards">
                    <div
                        v-for="(card, index) in editingSystem.cards"
                        :key="card.card_id"
                        class="deck-card-entry"
                        :class="{ 'is-expanded': isCardExpanded(index), 'is-missing-name': !card.name?.trim() }"
                    >
                        <div class="deck-card-header" @click="toggleCard(index)">
                            <span class="deck-card-number">{{ card.card_id }}</span>
                            <span class="deck-card-title">{{ card.name?.trim() || 'Untitled Card' }}</span>
                            <span v-if="!card.name?.trim()" class="tag is-danger is-light ml-2">Name required</span>
                            <span class="icon deck-card-chevron">
                                <i class="fa-solid" :class="isCardExpanded(index) ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                            </span>
                        </div>

                        <div v-show="isCardExpanded(index)" class="deck-card-body">
                            <div class="field">
                                <label class="label is-small">Card Title <span class="has-text-danger">*</span></label>
                                <div class="control">
                                    <input
                                        :ref="(el) => { cardTitleRefs[index] = el as HTMLInputElement | null }"
                                        class="input"
                                        type="text"
                                        v-model="card.name"
                                        placeholder="e.g. The Fool"
                                    />
                                </div>
                            </div>

                            <div class="columns is-multiline">
                                <div class="column is-6">
                                    <div class="field">
                                        <label class="label is-small">Keywords</label>
                                        <div class="control">
                                            <input class="input" type="text" v-model="card.keywords" placeholder="Keywords..." />
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-6">
                                    <div class="field">
                                        <label class="label is-small">Reversed Keywords</label>
                                        <div class="control">
                                            <input class="input" type="text" v-model="card.reversed_keywords" placeholder="Reversed keywords..." />
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-6">
                                    <div class="field">
                                        <label class="label is-small">Meaning</label>
                                        <div class="control">
                                            <textarea class="textarea is-small" v-model="card.meaning" placeholder="Meaning..." rows="3"></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-6">
                                    <div class="field">
                                        <label class="label is-small">Reversed Meaning</label>
                                        <div class="control">
                                            <textarea class="textarea is-small" v-model="card.reversed_meaning" placeholder="Reversed meaning..." rows="3"></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-6">
                                    <div class="field">
                                        <label class="label is-small">Advice</label>
                                        <div class="control">
                                            <textarea class="textarea is-small" v-model="card.advice" placeholder="Advice..." rows="3"></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-6">
                                    <div class="field">
                                        <label class="label is-small">Reversed Advice</label>
                                        <div class="control">
                                            <textarea class="textarea is-small" v-model="card.reversed_advice" placeholder="Reversed advice..." rows="3"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="has-text-right">
                                <button type="button" class="button is-small is-success" @click="markCardDone(index)">
                                    <span class="icon"><i class="fa-solid fa-check"></i></span>
                                    <span>Done</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="is-flex is-justify-content-space-between is-align-items-center mt-5">
                    <div>
                        <p v-if="!allCardTitlesValid" class="help is-danger">
                            All cards must have a title before saving.
                        </p>
                    </div>
                    <div class="buttons">
                        <button class="button" @click="closeEdit">Cancel</button>
                        <button
                            class="button is-success"
                            :class="{ 'is-loading': saving }"
                            :disabled="saving || !allCardTitlesValid"
                            @click="saveSystem"
                        >
                            <span class="icon"><i class="fa-solid fa-floppy-disk"></i></span>
                            <span>{{ isNew ? 'Create' : 'Save' }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Pending Submissions -->
            <div v-if="pendingSystems.length > 0" class="mb-6">
                <h2 class="title is-4">Pending Submissions <span class="tag is-warning ml-2">{{ pendingSystems.length }}</span></h2>
                <div class="table-container">
                    <table class="table is-fullwidth is-hoverable is-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Short Name</th>
                                <th>Cards</th>
                                <th>Submitted By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="sys in pendingSystems" :key="sys.deck_system_id">
                                <td>{{ sys.deck_system_id }}</td>
                                <td>{{ sys.name }}</td>
                                <td>{{ sys.short_name }}</td>
                                <td>{{ sys.total_cards }}</td>
                                <td>{{ sys.submitted_by ?? '—' }}</td>
                                <td>
                                    <div class="buttons are-small">
                                        <button class="button is-success" @click="approveSystem(sys)">
                                            <span class="icon"><i class="fa-solid fa-check"></i></span>
                                            <span>Approve</span>
                                        </button>
                                        <button class="button is-info" @click="openEdit(sys)">
                                            <span class="icon"><i class="fa-solid fa-pen-to-square"></i></span>
                                            <span>Edit</span>
                                        </button>
                                        <button class="button is-danger" @click="deleteSystem(sys)">
                                            <span class="icon"><i class="fa-solid fa-trash"></i></span>
                                            <span>Delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Approved Systems -->
            <h2 class="title is-4">Approved Systems</h2>

            <div class="field">
                <div class="control has-icons-left">
                    <input class="input" type="text" v-model="search" placeholder="Search by name or short name..." />
                    <span class="icon is-small is-left"><i class="fa-solid fa-magnifying-glass"></i></span>
                </div>
            </div>

            <div class="table-container">
                <table class="table is-fullwidth is-hoverable is-striped">
                    <thead>
                        <tr>
                            <SortableTh label="ID" sort-key="deck_system_id" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Name" sort-key="name" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Short Name" sort-key="short_name" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Total Cards" sort-key="total_cards" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="sys in visibleSystems" :key="sys.deck_system_id">
                            <td>{{ sys.deck_system_id }}</td>
                            <td>{{ sys.name }}</td>
                            <td><span class="tag is-info is-light">{{ sys.short_name }}</span></td>
                            <td>{{ sys.total_cards }}</td>
                            <td>
                                <div class="buttons are-small">
                                    <button class="button is-info" @click="openEdit(sys)">
                                        <span class="icon"><i class="fa-solid fa-pen-to-square"></i></span>
                                        <span>Edit</span>
                                    </button>
                                    <button class="button is-danger" @click="deleteSystem(sys)">
                                        <span class="icon"><i class="fa-solid fa-trash"></i></span>
                                        <span>Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="has-text-grey" v-if="visibleSystems.length === 0">No deck systems match your search.</p>
        </div>
    </section>
</template>

<style scoped>
.deck-system-editor {
    border: 2px solid var(--myst-border-strong, rgba(255, 255, 255, 0.25));
}

.deck-system-cards {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-height: 600px;
    overflow-y: auto;
    padding-right: 0.25rem;
}

.deck-card-entry {
    /* The container is a flex column with a capped height; without this the
       entries would shrink (flex-shrink defaults to 1) to fit, and overflow:hidden
       would clip each one down to a sliver. Keep their natural height and let the
       container scroll instead. */
    flex-shrink: 0;
    border: 1px solid var(--myst-border, rgba(255, 255, 255, 0.12));
    border-radius: 8px;
    overflow: hidden;
    transition: border-color 0.15s ease;
}

.deck-card-entry.is-expanded {
    border-color: var(--myst-border-strong, rgba(255, 255, 255, 0.25));
}

.deck-card-entry.is-missing-name:not(.is-expanded) {
    border-color: hsl(348, 86%, 61%);
}

.deck-card-header {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.65rem 0.9rem;
    cursor: pointer;
    background: var(--myst-surface-3, rgba(255, 255, 255, 0.04));
    user-select: none;
    transition: background-color 0.12s ease;
}

.deck-card-header:hover {
    background: var(--myst-surface-3, rgba(255, 255, 255, 0.07));
}

.deck-card-number {
    font-size: 0.8rem;
    font-weight: 700;
    opacity: 0.5;
    min-width: 2ch;
    text-align: right;
}

.deck-card-title {
    font-weight: 600;
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.deck-card-chevron {
    margin-left: auto;
    opacity: 0.5;
    transition: transform 0.15s ease;
}

.deck-card-body {
    padding: 1rem;
    border-top: 1px solid var(--myst-border, rgba(255, 255, 255, 0.08));
}
</style>
