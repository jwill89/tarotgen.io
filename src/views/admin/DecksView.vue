<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { useConfirm } from '@/composables/useConfirm'
import { useDataTable } from '@/composables/useDataTable'
import { useToasts } from '@/composables/useToasts'
import BaseModal from '@/components/BaseModal.vue'
import SortableTh from '@/components/admin/SortableTh.vue'
import type { Deck, DeckSystem } from '@/types'

const api = useAdminApi()
const { confirm } = useConfirm()
const toasts = useToasts()

const decks = ref<Deck[]>([])
const pendingDecks = ref<Deck[]>([])
const deckSystems = ref<DeckSystem[]>([])
const editingDeck = ref<Partial<Deck> | null>(null)
const isNew = ref(false)
const saving = ref(false)
const generatingAll = ref(false)
const thumbBusyId = ref<number | null>(null)

const { search, sortKey, sortDir, rows: visibleDecks, toggleSort } = useDataTable(decks, {
    searchText: d => `${d.name} ${d.artist} ${d.deck_id}`,
    sortAccessors: {
        deck_id: d => d.deck_id,
        name: d => d.name,
        artist: d => d.artist,
        total_cards: d => d.system_total_cards + d.additional_cards,
    },
    initialSort: 'deck_id',
})

function emptyDeck(): Partial<Deck> {
    return {
        name: '',
        artist: '',
        purchase_url: '',
        deck_system_id: (deckSystems.value.find(s => s.short_name === 'RWS') ?? deckSystems.value[0])?.deck_system_id ?? 1,
        additional_cards: 0,
        card_aspect_w: 5,
        card_aspect_h: 8.6,
    }
}

async function fetchDecks() {
    const data = await api.get<Deck[]>('/decks')
    if (data) decks.value = data
}

async function fetchPendingDecks() {
    const data = await api.get<Deck[]>('/decks/pending')
    if (data) pendingDecks.value = data
}

async function fetchDeckSystems() {
    const data = await api.get<DeckSystem[]>('/deck-systems')
    if (data) deckSystems.value = data
}

function openAdd() {
    isNew.value = true
    editingDeck.value = emptyDeck()
}

function openEdit(deck: Deck) {
    isNew.value = false
    editingDeck.value = { ...deck }
}

function closeEdit() {
    editingDeck.value = null
}

async function saveDeck() {
    if (!editingDeck.value) return
    saving.value = true
    try {
        const result = isNew.value
            ? await api.post('/decks', editingDeck.value, 'Deck created.')
            : await api.put('/decks/' + editingDeck.value.deck_id, editingDeck.value, 'Deck updated.')
        if (!result) return
        await fetchDecks()
        await fetchPendingDecks()
        closeEdit()
    } finally {
        saving.value = false
    }
}

async function deleteDeck(deck: Deck) {
    const ok = await confirm({
        title: 'Delete deck',
        message: `Delete "${deck.name}"? This also deletes every special card for this deck and cannot be undone.`,
        confirmLabel: 'Delete',
        danger: true,
    })
    if (!ok) return
    const result = await api.del('/decks/' + deck.deck_id, 'Deck deleted.')
    if (result) {
        await fetchDecks()
        await fetchPendingDecks()
    }
}

async function approveDeck(deck: Deck) {
    const result = await api.post('/decks/' + deck.deck_id + '/approve', {}, 'Deck approved.')
    if (result) {
        await fetchDecks()
        await fetchPendingDecks()
    }
}

async function toggleUsable(deck: Deck) {
    const newUsable = !deck.usable
    const msg = newUsable ? 'Deck marked usable.' : 'Deck marked unusable.'
    const result = await api.post('/decks/' + deck.deck_id + '/usable', { usable: newUsable }, msg)
    if (result) {
        await fetchDecks()
    }
}

interface ThumbResult { deck_id?: number; generated: number; skipped: number; decks?: number }

async function generateThumbnails(deck: Deck) {
    thumbBusyId.value = deck.deck_id
    const res = await api.post<ThumbResult>('/decks/' + deck.deck_id + '/thumbnails', {})
    thumbBusyId.value = null
    if (res) {
        toasts.success(`"${deck.name}": ${res.generated} thumbnail(s) generated, ${res.skipped} already existed.`)
    }
}

async function generateAllThumbnails() {
    generatingAll.value = true
    const res = await api.post<ThumbResult>('/decks/thumbnails', {})
    generatingAll.value = false
    if (res) {
        toasts.success(`Generated ${res.generated} thumbnail(s) across ${res.decks} deck(s); ${res.skipped} already existed.`)
    }
}

function systemName(id: number): string {
    const sys = deckSystems.value.find(s => s.deck_system_id === id)
    return sys ? sys.short_name : '—'
}

onMounted(() => {
    fetchDeckSystems()
    fetchDecks()
    fetchPendingDecks()
})
</script>

<template>
    <section class="section">
        <div class="container">
            <router-link :to="{ name: 'admin-dashboard' }" class="button is-small is-ghost mb-4">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-left']" /></span>
                <span>Back to Dashboard</span>
            </router-link>

            <div class="level">
                <div class="level-left">
                    <div>
                        <h1 class="title is-3">Manage Decks</h1>
                        <p class="subtitle is-5">Add, edit, or remove tarot decks.</p>
                    </div>
                </div>
                <div class="level-right">
                    <div class="buttons">
                        <button class="button is-link" :class="{ 'is-loading': generatingAll }" @click="generateAllThumbnails">
                            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['images']" /></span>
                            <span>Generate All Thumbnails</span>
                        </button>
                        <button class="button is-primary" @click="openAdd">
                            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
                            <span>Add Deck</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Submitted (Pending) Decks -->
            <div v-if="pendingDecks.length > 0" class="mb-6">
                <h2 class="title is-4">Submitted Decks <span class="tag is-warning ml-2">{{ pendingDecks.length }}</span></h2>
                <p class="subtitle is-6">Decks submitted by users, awaiting approval.</p>
                <div class="table-container">
                    <table class="table is-fullwidth is-hoverable is-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Artist</th>
                                <th>System</th>
                                <th>Submitted By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="deck in pendingDecks" :key="deck.deck_id">
                                <td>{{ deck.deck_id }}</td>
                                <td>{{ deck.name }}</td>
                                <td>{{ deck.artist }}</td>
                                <td>{{ deck.system_short_name || systemName(deck.deck_system_id) }}</td>
                                <td>{{ deck.submitted_by ?? '—' }}</td>
                                <td>
                                    <div class="buttons are-small">
                                        <button class="button is-success" @click="approveDeck(deck)">
                                            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['check']" /></span>
                                            <span>Approve</span>
                                        </button>
                                        <button class="button is-info" @click="openEdit(deck)">
                                            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['pen-to-square']" /></span>
                                            <span>Edit</span>
                                        </button>
                                        <button class="button is-danger" @click="deleteDeck(deck)">
                                            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']" /></span>
                                            <span>Delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Approved Decks -->
            <h2 class="title is-4">Approved Decks</h2>

            <div class="field">
                <div class="control has-icons-left">
                    <input class="input" type="text" v-model="search" placeholder="Search decks by name, artist, or ID..." />
                    <span class="icon is-small is-left"><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']" /></span>
                </div>
            </div>

            <div class="table-container">
                <table class="table is-fullwidth is-hoverable is-striped">
                    <thead>
                        <tr>
                            <SortableTh label="ID" sort-key="deck_id" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Name" sort-key="name" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Artist" sort-key="artist" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <th>System</th>
                            <SortableTh label="Cards" sort-key="total_cards" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="deck in visibleDecks" :key="deck.deck_id">
                            <td>{{ deck.deck_id }}</td>
                            <td>{{ deck.name }}</td>
                            <td>{{ deck.artist }}</td>
                            <td><span class="tag is-info is-light">{{ deck.system_short_name || systemName(deck.deck_system_id) }}</span></td>
                            <td>{{ deck.system_total_cards }}{{ deck.additional_cards ? ' + ' + deck.additional_cards : '' }}</td>
                            <td>
                                <span class="tag is-success is-light mr-1" v-if="deck.usable">Usable</span>
                                <span class="tag is-light mr-1" v-else>Not Usable</span>
                            </td>
                            <td>
                                <div class="buttons are-small">
                                    <button
                                        class="button"
                                        :class="deck.usable ? 'is-warning' : 'is-success'"
                                        @click="toggleUsable(deck)"
                                        :title="deck.usable ? 'Mark as not usable' : 'Mark as usable'"
                                    >
                                        <span class="icon"><FontAwesomeIcon :icon="deck.usable ? byPrefixAndName.fas['eye-slash'] : byPrefixAndName.fas['eye']" /></span>
                                        <span>{{ deck.usable ? 'Unlist' : 'Make Usable' }}</span>
                                    </button>
                                    <button class="button is-info" @click="openEdit(deck)">
                                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['pen-to-square']" /></span>
                                        <span>Edit</span>
                                    </button>
                                    <button
                                        class="button is-link"
                                        :class="{ 'is-loading': thumbBusyId === deck.deck_id }"
                                        @click="generateThumbnails(deck)"
                                        title="Generate thumbnails for this deck"
                                    >
                                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['images']" /></span>
                                        <span>Thumbnails</span>
                                    </button>
                                    <button class="button is-danger" @click="deleteDeck(deck)">
                                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']" /></span>
                                        <span>Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Add/Edit Modal -->
    <BaseModal
        :active="editingDeck !== null"
        :title="isNew ? 'Add Deck' : 'Edit Deck #' + (editingDeck?.deck_id ?? '')"
        max-width="700px"
        @close="closeEdit"
    >
        <template v-if="editingDeck">
            <div class="field">
                <label class="label">Name</label>
                <input class="input" v-model="editingDeck.name" />
            </div>
            <div class="field">
                <label class="label">Artist</label>
                <input class="input" v-model="editingDeck.artist" />
            </div>
            <div class="field">
                <label class="label">Purchase URL</label>
                <input class="input" v-model="editingDeck.purchase_url" />
            </div>
            <div class="field">
                <label class="label">Deck System</label>
                <div class="select is-fullwidth">
                    <select v-model.number="editingDeck.deck_system_id">
                        <option v-for="sys in deckSystems" :key="sys.deck_system_id" :value="sys.deck_system_id">
                            {{ sys.name }} — {{ sys.total_cards }} cards
                        </option>
                    </select>
                </div>
            </div>
            <div class="columns">
                <div class="column is-6">
                    <div class="field">
                        <label class="label">Additional Cards</label>
                        <input class="input" type="number" v-model.number="editingDeck.additional_cards" min="0" />
                        <p class="help">Extra cards beyond the deck system's standard count.</p>
                    </div>
                </div>
            </div>
            <div class="field">
                <label class="label">Card Aspect Ratio <span class="has-text-grey is-size-7 has-text-weight-normal">(width × height — any unit)</span></label>
                <div class="field has-addons">
                    <p class="control">
                        <input class="input" type="number" step="0.1" min="0.1" v-model.number="editingDeck.card_aspect_w" aria-label="Card width" />
                    </p>
                    <p class="control"><span class="button is-static">×</span></p>
                    <p class="control">
                        <input class="input" type="number" step="0.1" min="0.1" v-model.number="editingDeck.card_aspect_h" aria-label="Card height" />
                    </p>
                </div>
                <p class="help">Controls the card slot shape — only the ratio matters.</p>
            </div>
        </template>

        <template #footer>
            <button class="button" @click="closeEdit">Cancel</button>
            <button class="button is-success" :class="{ 'is-loading': saving }" @click="saveDeck">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']" /></span>
                <span>{{ isNew ? 'Create' : 'Save' }}</span>
            </button>
        </template>
    </BaseModal>
</template>
