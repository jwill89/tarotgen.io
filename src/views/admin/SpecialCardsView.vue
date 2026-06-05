<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { useConfirm } from '@/composables/useConfirm'
import { useDataTable } from '@/composables/useDataTable'
import BaseModal from '@/components/BaseModal.vue'
import SortableTh from '@/components/admin/SortableTh.vue'
import type { SpecialCard, Deck } from '@/types'

const api = useAdminApi()
const { confirm } = useConfirm()

const specialCards = ref<SpecialCard[]>([])
const decks = ref<Deck[]>([])
const filterDeckId = ref<number | null>(null)
const editing = ref<Partial<SpecialCard> | null>(null)
const isNew = ref(false)
const saving = ref(false)

const deckLookup = computed(() => {
    const map: Record<number, Deck> = {}
    decks.value.forEach(d => { map[d.deck_id] = d })
    return map
})

// First narrow by the deck dropdown, then apply search + sort.
const deckFiltered = computed(() =>
    filterDeckId.value === null
        ? specialCards.value
        : specialCards.value.filter(c => c.deck_id === filterDeckId.value)
)

const { search, sortKey, sortDir, rows: visibleCards, toggleSort } = useDataTable(deckFiltered, {
    searchText: c => `${c.name} ${c.card_id} ${deckLookup.value[c.deck_id]?.name ?? ''}`,
    sortAccessors: {
        deck: c => deckLookup.value[c.deck_id]?.name ?? '',
        card_id: c => c.card_id,
        name: c => c.name,
    },
    initialSort: 'deck',
})

async function fetchData() {
    const [sc, dk] = await Promise.all([
        api.get<SpecialCard[]>('/special-cards'),
        api.get<Deck[]>('/decks'),
    ])
    if (sc) specialCards.value = sc
    if (dk) decks.value = dk
}

function openAdd() {
    isNew.value = true
    editing.value = {
        deck_id: filterDeckId.value ?? (decks.value.length > 0 ? decks.value[0].deck_id : undefined),
        card_id: undefined,
        name: '',
    }
}

function openEdit(card: SpecialCard) {
    isNew.value = false
    editing.value = { ...card }
}

function closeEdit() {
    editing.value = null
}

async function saveCard() {
    if (!editing.value) return
    saving.value = true
    try {
        const result = isNew.value
            ? await api.post('/special-cards', editing.value, 'Special card created.')
            : await api.put('/special-cards/' + editing.value.deck_id + '/' + editing.value.card_id, editing.value, 'Special card updated.')
        if (!result) return
        await fetchData()
        closeEdit()
    } finally {
        saving.value = false
    }
}

async function deleteCard(card: SpecialCard) {
    const deckName = deckLookup.value[card.deck_id]?.name ?? ('deck ' + card.deck_id)
    const ok = await confirm({
        title: 'Delete special card',
        message: `Delete "${card.name}" (Card ID ${card.card_id}) from ${deckName}? This cannot be undone.`,
        confirmLabel: 'Delete',
        danger: true,
    })
    if (!ok) return
    const result = await api.del('/special-cards/' + card.deck_id + '/' + card.card_id, 'Special card deleted.')
    if (result) await fetchData()
}

onMounted(fetchData)
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
                        <h1 class="title is-3">Manage Special Cards</h1>
                        <p class="subtitle is-5">Manage extra cards specific to certain decks.</p>
                    </div>
                </div>
                <div class="level-right">
                    <button class="button is-primary" @click="openAdd">
                        <span class="icon"><i class="fa-solid fa-plus"></i></span>
                        <span>Add Special Card</span>
                    </button>
                </div>
            </div>

            <div class="columns">
                <div class="column is-4">
                    <div class="field">
                        <label class="label">Filter by Deck</label>
                        <div class="control is-expanded">
                            <div class="select is-fullwidth">
                                <select v-model="filterDeckId">
                                    <option :value="null">All Decks</option>
                                    <option v-for="deck in decks" :key="deck.deck_id" :value="deck.deck_id">
                                        {{ deck.name }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-8">
                    <div class="field">
                        <label class="label">Search</label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" v-model="search" placeholder="Search by name, card ID, or deck..." />
                            <span class="icon is-small is-left"><i class="fa-solid fa-magnifying-glass"></i></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table class="table is-fullwidth is-hoverable is-striped">
                    <thead>
                        <tr>
                            <SortableTh label="Deck" sort-key="deck" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <th>System</th>
                            <SortableTh label="Card ID" sort-key="card_id" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Name" sort-key="name" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="card in visibleCards" :key="card.deck_id + '-' + card.card_id">
                            <td>{{ deckLookup[card.deck_id]?.name ?? card.deck_id }}</td>
                            <td><span class="tag is-info is-light">{{ deckLookup[card.deck_id]?.system_short_name ?? '—' }}</span></td>
                            <td>{{ card.card_id }}</td>
                            <td>{{ card.name }}</td>
                            <td>
                                <div class="buttons are-small">
                                    <button class="button is-info" @click="openEdit(card)">
                                        <span class="icon"><i class="fa-solid fa-pen-to-square"></i></span>
                                        <span>Edit</span>
                                    </button>
                                    <button class="button is-danger" @click="deleteCard(card)">
                                        <span class="icon"><i class="fa-solid fa-trash"></i></span>
                                        <span>Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="has-text-grey" v-if="visibleCards.length === 0">No special cards found.</p>
        </div>
    </section>

    <!-- Add/Edit Modal -->
    <BaseModal
        :active="editing !== null"
        :title="isNew ? 'Add Special Card' : 'Edit Special Card'"
        max-width="600px"
        @close="closeEdit"
    >
        <template v-if="editing">
            <div class="field">
                <label class="label">Deck</label>
                <div class="control">
                    <div class="select is-fullwidth">
                        <select v-model.number="editing.deck_id" :disabled="!isNew">
                            <option v-for="deck in decks" :key="deck.deck_id" :value="deck.deck_id">
                                {{ deck.name }}
                            </option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="field">
                <label class="label">Card ID</label>
                <input class="input" type="number" v-model.number="editing.card_id" :disabled="!isNew" min="1" />
            </div>
            <div class="field">
                <label class="label">Name</label>
                <input class="input" v-model="editing.name" />
            </div>
            <div class="field">
                <label class="label">Keywords</label>
                <input class="input" v-model="editing.keywords" placeholder="comma, separated, keywords" />
            </div>
            <div class="field">
                <label class="label">Meaning</label>
                <textarea class="textarea" v-model="editing.meaning" rows="3"></textarea>
            </div>
            <div class="field">
                <label class="label">Advice</label>
                <textarea class="textarea" v-model="editing.advice" rows="3"></textarea>
            </div>
            <div class="field">
                <label class="label">Keywords (Reversed)</label>
                <input class="input" v-model="editing.keywords_reversed" placeholder="comma, separated, keywords" />
            </div>
            <div class="field">
                <label class="label">Meaning (Reversed)</label>
                <textarea class="textarea" v-model="editing.meaning_reversed" rows="3"></textarea>
            </div>
            <div class="field">
                <label class="label">Advice (Reversed)</label>
                <textarea class="textarea" v-model="editing.advice_reversed" rows="3"></textarea>
            </div>
        </template>

        <template #footer>
            <button class="button" @click="closeEdit">Cancel</button>
            <button class="button is-success" :class="{ 'is-loading': saving }" @click="saveCard">
                <span class="icon"><i class="fa-solid fa-floppy-disk"></i></span>
                <span>{{ isNew ? 'Create' : 'Save' }}</span>
            </button>
        </template>
    </BaseModal>

</template>
