<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import { useDataTable } from '@/composables/useDataTable'
import { useToasts } from '@/composables/useToasts'
import SortableTh from '@/components/admin/SortableTh.vue'
import Tooltip from '@/components/Tooltip.vue'
import PageHeader from '@/components/PageHeader.vue'
import IconButton from '@/components/IconButton.vue'
import NumberField from '@/components/NumberField.vue'
import DeckCardListEditor from '@/components/DeckCardListEditor.vue'
import {
  resizeCards,
  allCardsNamed,
  missingNameCount,
  type DeckCardListEditorApi,
} from '@/components/deckCards'
import type { DeckSystem, DeckSystemWithCards, DeckSystemCard } from '@/types'

const api = useAdminApi()
const { confirm } = useConfirm()
const toasts = useToasts()

const systems = ref<DeckSystem[]>([])
const pendingSystems = ref<DeckSystem[]>([])
const editingSystem = ref<(Partial<DeckSystemWithCards> & { cards: DeckSystemCard[] }) | null>(null)
const isNew = ref(false)
const saving = ref(false)

const cardEditor = ref<DeckCardListEditorApi | null>(null)

const {
  search,
  sortKey,
  sortDir,
  rows: visibleSystems,
  toggleSort,
} = useDataTable(systems, {
  searchText: (s) => `${s.name} ${s.short_name} ${s.deck_system_id}`,
  sortAccessors: {
    deck_system_id: (s) => s.deck_system_id,
    name: (s) => s.name,
    short_name: (s) => s.short_name,
    total_cards: (s) => s.total_cards,
  },
  initialSort: 'name',
})

const allCardTitlesValid = computed(() => allCardsNamed(editingSystem.value?.cards ?? []))

async function fetchSystems() {
  const data = await api.get<DeckSystem[]>(endpoints.admin.deckSystems.list)
  if (data) systems.value = data
}

async function fetchPendingSystems() {
  const data = await api.get<DeckSystem[]>(endpoints.admin.deckSystems.pending)
  if (data) pendingSystems.value = data
}

function openAdd() {
  isNew.value = true
  editingSystem.value = {
    name: '',
    short_name: '',
    total_cards: 78,
    cards: resizeCards([], 78),
  }
}

async function openEdit(system: DeckSystem) {
  isNew.value = false
  const full = await api.get<DeckSystemWithCards>(
    endpoints.admin.deckSystems.byId(system.deck_system_id),
  )
  if (full) {
    editingSystem.value = { ...full }
  }
}

function closeEdit() {
  editingSystem.value = null
}

function updateCardCount() {
  if (!editingSystem.value) return
  editingSystem.value.cards = resizeCards(
    editingSystem.value.cards,
    editingSystem.value.total_cards ?? 78,
  )
}

// Watch rather than a native @change listener: Reka's NumberField commits the
// model on blur/Enter AND on the −/+ steppers, but the steppers set it
// programmatically, which fires no native `change` — so an @change handler
// silently missed every stepper click. Typing is safe here because the model is
// only committed on blur, never per keystroke (NumberFieldInput's onInput
// updates the displayed text only).
watch(() => editingSystem.value?.total_cards, updateCardCount)

async function saveSystem() {
  if (!editingSystem.value) return

  // Validate card names
  const missing = missingNameCount(editingSystem.value.cards)
  if (missing > 0) {
    toasts.error(`${missing} card(s) are missing names.`)
    cardEditor.value?.revealFirstMissing()
    return
  }

  saving.value = true
  try {
    let result
    if (isNew.value) {
      const res = await fetch('/api' + endpoints.deckSystems.list, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(editingSystem.value),
      })
      if (res.ok) {
        result = await res.json()
        toasts.success('Deck system created.')
      } else {
        const err = (await res.json().catch(() => ({}))) as { error?: string }
        toasts.error(err.error || 'Failed to create deck system.')
        return
      }
    } else {
      result = await api.put(
        endpoints.admin.deckSystems.byId(editingSystem.value.deck_system_id),
        editingSystem.value,
        'Deck system updated.',
      )
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
  const result = await api.post(
    endpoints.admin.deckSystems.approve(system.deck_system_id),
    {},
    'Deck system approved.',
  )
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
  const result = await api.del(
    endpoints.admin.deckSystems.byId(system.deck_system_id),
    'Deck system deleted.',
  )
  if (result) {
    await fetchSystems()
    await fetchPendingSystems()
  }
}

onMounted(() => {
  void fetchSystems()
  void fetchPendingSystems()
})
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-11-desktop is-12-tablet">
          <router-link :to="{ name: 'admin-dashboard' }" class="button is-small is-ghost mb-4">
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-left']" /></span>
            <span>Back to Dashboard</span>
          </router-link>

          <PageHeader
            title="Manage Deck Systems"
            subtitle="Manage card naming systems (e.g. Rider-Waite-Smith, Thoth)."
          >
            <button class="button is-primary" :disabled="editingSystem !== null" @click="openAdd">
              <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
              <span>Add Deck System</span>
            </button>
          </PageHeader>

          <!-- Inline Add/Edit Form -->
          <div v-if="editingSystem" class="settings-panel mb-6 deck-system-editor">
            <div class="is-flex is-align-items-center is-justify-content-space-between mb-4">
              <h3 class="title is-4 mb-0">
                {{
                  isNew ? 'Add Deck System' : 'Edit Deck System #' + editingSystem.deck_system_id
                }}
              </h3>
              <button class="delete" aria-label="close" @click="closeEdit"></button>
            </div>

            <div class="columns">
              <div class="column is-4">
                <div class="field">
                  <label class="label">Name <span class="has-text-danger">*</span></label>
                  <input
                    v-model="editingSystem.name"
                    class="input"
                    placeholder="e.g. Rider-Waite-Smith"
                  />
                </div>
              </div>
              <div class="column is-4">
                <div class="field">
                  <label class="label">Short Name <span class="has-text-danger">*</span></label>
                  <input v-model="editingSystem.short_name" class="input" placeholder="e.g. RWS" />
                </div>
              </div>
              <div class="column is-4">
                <div class="field">
                  <label class="label">Total Cards <span class="has-text-danger">*</span></label>
                  <NumberField v-model="editingSystem.total_cards" :min="1" />
                </div>
              </div>
            </div>

            <DeckCardListEditor
              :key="isNew ? 'new' : editingSystem.deck_system_id"
              ref="cardEditor"
              v-model="editingSystem.cards"
              heading-tag="h5"
              max-height="600px"
              :initial-open="isNew ? ['0'] : []"
            >
              <template #hint>
                <p class="mb-4 has-text-grey is-size-7">
                  Each card needs a name at minimum. Other fields are optional.
                </p>
              </template>
            </DeckCardListEditor>

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
                  <span class="icon"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']"
                  /></span>
                  <span>{{ isNew ? 'Create' : 'Save' }}</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Pending Submissions -->
          <div v-if="pendingSystems.length > 0" class="mb-6">
            <h2 class="title is-4">
              Pending Submissions
              <span class="tag is-warning ml-2">{{ pendingSystems.length }}</span>
            </h2>
            <div class="settings-panel">
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
                        <div class="row-actions">
                          <IconButton
                            :icon="byPrefixAndName.fas['check']"
                            label="Approve"
                            intent="success"
                            @click="approveSystem(sys)"
                          />
                          <IconButton
                            :icon="byPrefixAndName.fas['pen-to-square']"
                            label="Edit"
                            @click="openEdit(sys)"
                          />
                          <IconButton
                            :icon="byPrefixAndName.fas['trash']"
                            label="Delete"
                            intent="danger"
                            @click="deleteSystem(sys)"
                          />
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Approved Systems -->
          <h2 class="title is-4">Approved Systems</h2>

          <div class="field">
            <div class="control has-icons-left">
              <input
                v-model="search"
                class="input"
                type="text"
                placeholder="Search by name or short name..."
              />
              <span class="icon is-small is-left"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']"
              /></span>
            </div>
          </div>

          <div class="settings-panel">
            <div class="table-container">
              <table class="table is-fullwidth is-hoverable is-striped">
                <thead>
                  <tr>
                    <SortableTh
                      label="ID"
                      sort-key="deck_system_id"
                      :active-key="sortKey"
                      :dir="sortDir"
                      @sort="toggleSort"
                    />
                    <SortableTh
                      label="Name"
                      sort-key="name"
                      :active-key="sortKey"
                      :dir="sortDir"
                      @sort="toggleSort"
                    />
                    <SortableTh
                      label="Short Name"
                      sort-key="short_name"
                      :active-key="sortKey"
                      :dir="sortDir"
                      @sort="toggleSort"
                    />
                    <SortableTh
                      label="Total Cards"
                      sort-key="total_cards"
                      :active-key="sortKey"
                      :dir="sortDir"
                      @sort="toggleSort"
                    />
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="sys in visibleSystems" :key="sys.deck_system_id">
                    <td>{{ sys.deck_system_id }}</td>
                    <td>{{ sys.name }}</td>
                    <td>
                      <Tooltip :text="sys.name">
                        <span class="data-chip">{{ sys.short_name }}</span>
                      </Tooltip>
                    </td>
                    <td>{{ sys.total_cards }}</td>
                    <td>
                      <div class="row-actions">
                        <IconButton
                          :icon="byPrefixAndName.fas['pen-to-square']"
                          label="Edit"
                          @click="openEdit(sys)"
                        />
                        <IconButton
                          :icon="byPrefixAndName.fas['trash']"
                          label="Delete"
                          intent="danger"
                          @click="deleteSystem(sys)"
                        />
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <p v-if="visibleSystems.length === 0" class="has-text-grey">
            No deck systems match your search.
          </p>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.deck-system-editor {
  border: 2px solid var(--myst-border-strong);
}
</style>
