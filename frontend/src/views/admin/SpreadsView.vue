<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted, useTemplateRef, useId } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import { useDataTable } from '@/composables/useDataTable'
import AdminListPage from '@/components/admin/AdminListPage.vue'
import AdminSearchField from '@/components/admin/AdminSearchField.vue'
import BaseModal from '@/components/BaseModal.vue'
import BaseSelect from '@/components/BaseSelect.vue'
import IconButton from '@/components/IconButton.vue'
import SortableTh from '@/components/admin/SortableTh.vue'
import type { PendingSpread, Spread, SpreadCategory, SpreadPosition } from '@/types'
import SpreadEditor from '@/components/admin/SpreadEditor.vue'
import SpreadCategoryManager from '@/components/admin/SpreadCategoryManager.vue'
import SpreadLayoutPreview from '@/components/SpreadLayoutPreview.vue'
import { formatDateTime } from '@/utils/datetime'

const api = useAdminApi()
const { confirm } = useConfirm()

const uid = useId()
const spreads = ref<Spread[]>([])
const categories = ref<SpreadCategory[]>([])
const pending = ref<PendingSpread[]>([])
/** The category the submission being previewed is published in. */
const approveCategoryId = ref<number | null>(null)
const mode = ref<'list' | 'edit'>('list')
const editing = ref<Spread | null>(null)
const previewing = ref<PendingSpread | null>(null)
const busyPendingId = ref<number | null>(null)
const editorRef = useTemplateRef<InstanceType<typeof SpreadEditor>>('editorRef')

const {
  search,
  sortKey,
  sortDir,
  rows: visibleSpreads,
  toggleSort,
} = useDataTable(spreads, {
  searchText: (s) => `${s.name} ${s.spread_id} ${categoryName(s)}`,
  sortAccessors: {
    spread_id: (s) => s.spread_id,
    name: (s) => s.name,
    // By the categories' own order, as the library lists them.
    category: (s) => categoryById.value.get(s.category_id)?.sort_order ?? 0,
    card_count: (s) => s.card_count,
  },
  initialSort: 'spread_id',
})

const categoryById = computed(() => new Map(categories.value.map((c) => [c.category_id, c])))
const categoryOptions = computed(() =>
  categories.value.map((c) => ({ value: c.category_id, label: c.name })),
)

function categoryName(spread: Spread): string {
  return categoryById.value.get(spread.category_id)?.name ?? '—'
}

async function fetchSpreads() {
  const data = await api.get<Spread[]>(endpoints.admin.spreads.list)
  if (data) spreads.value = data
}

// Their spread counts change with the spreads, so reloaded alongside them.
async function fetchCategories() {
  const data = await api.get<SpreadCategory[]>(endpoints.admin.spreadCategories.list)
  if (data) categories.value = data
}

async function fetchPending() {
  const data = await api.get<PendingSpread[]>(endpoints.admin.pendingSpreads.list)
  if (data) pending.value = data
}

function sortedPositions(p: PendingSpread): SpreadPosition[] {
  return p.positions.slice().sort((a, b) => a.order - b.order)
}

/** Publish a submission: in the category chosen, or (none given) the first. */
async function approvePending(p: PendingSpread, categoryId: number | null = null) {
  busyPendingId.value = p.pending_id
  const result = await api.post<Spread>(
    endpoints.admin.pendingSpreads.approve(p.pending_id),
    categoryId === null ? {} : { category_id: categoryId },
    'Spread approved and published.',
  )
  busyPendingId.value = null
  if (result) {
    if (previewing.value?.pending_id === p.pending_id) previewing.value = null
    await Promise.all([fetchSpreads(), fetchPending(), fetchCategories()])
  }
}

function openPreview(p: PendingSpread) {
  approveCategoryId.value = categories.value[0]?.category_id ?? null
  previewing.value = p
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
  const result = await api.del(
    endpoints.admin.pendingSpreads.byId(p.pending_id),
    'Submission rejected.',
  )
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

async function saveSpread(payload: {
  name: string
  description: string
  card_count: number
  positions: SpreadPosition[]
  category_id?: number
}) {
  let result: Spread | null
  if (editing.value) {
    result = await api.put<Spread>(
      endpoints.admin.spreads.byId(editing.value.spread_id),
      payload,
      'Spread updated.',
    )
  } else {
    result = await api.post<Spread>(endpoints.admin.spreads.list, payload, 'Spread created.')
  }

  if (result) {
    await Promise.all([fetchSpreads(), fetchCategories()])
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
  const result = await api.del(endpoints.admin.spreads.byId(spread.spread_id), 'Spread deleted.')
  if (result) await Promise.all([fetchSpreads(), fetchCategories()])
}

onMounted(() => {
  void fetchSpreads()
  void fetchCategories()
  void fetchPending()
})
</script>

<template>
  <AdminListPage
    v-if="mode === 'list'"
    title="Manage Spreads"
    subtitle="Create and arrange tarot spreads."
  >
    <template #actions>
      <button class="button is-primary" @click="openAdd">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
        <span>Add Spread</span>
      </button>
    </template>

    <AdminSearchField v-model="search" placeholder="Search spreads by name, category or ID..." />

    <div class="settings-panel">
      <div class="table-container">
        <table class="table is-fullwidth is-hoverable is-striped">
          <thead>
            <tr>
              <SortableTh
                label="ID"
                sort-key="spread_id"
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
                label="Category"
                sort-key="category"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Cards"
                sort-key="card_count"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="spread in visibleSpreads" :key="spread.spread_id">
              <td>{{ spread.spread_id }}</td>
              <td>{{ spread.name }}</td>
              <td>{{ categoryName(spread) }}</td>
              <td>{{ spread.card_count }}</td>
              <td>
                <div class="row-actions">
                  <IconButton
                    :icon="byPrefixAndName.fas['pen-to-square']"
                    label="Edit"
                    @click="openEdit(spread)"
                  />
                  <IconButton
                    :icon="byPrefixAndName.fas['trash']"
                    label="Delete"
                    intent="danger"
                    @click="deleteSpread(spread)"
                  />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <p v-if="spreads.length === 0" class="has-text-grey">
      No spreads yet. Click "Add Spread" to create one.
    </p>

    <!-- Approval queue -->
    <div class="mt-6">
      <h2 class="title is-4">
        Submission Queue
        <span v-if="pending.length" class="tag is-warning ml-2">{{ pending.length }}</span>
      </h2>
      <p class="subtitle is-6">
        Review user-submitted spreads. Approving copies the spread into the list above.
      </p>

      <p v-if="pending.length === 0" class="has-text-grey">No pending submissions right now.</p>
      <div v-else class="settings-panel">
        <div class="table-container">
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
                  <div class="row-actions">
                    <IconButton
                      :icon="byPrefixAndName.fas['eye']"
                      label="Preview"
                      @click="openPreview(p)"
                    />
                    <IconButton
                      :icon="byPrefixAndName.fas['check']"
                      :label="
                        categories.length > 0 ? `Approve into ${categories[0].name}` : 'Approve'
                      "
                      intent="success"
                      :loading="busyPendingId === p.pending_id"
                      @click="approvePending(p)"
                    />
                    <IconButton
                      :icon="byPrefixAndName.fas['xmark']"
                      label="Reject"
                      intent="danger"
                      @click="rejectPending(p)"
                    />
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <SpreadCategoryManager class="mt-6" :categories="categories" @changed="fetchCategories" />
  </AdminListPage>

  <AdminListPage v-else :title="editing ? 'Edit Spread' : 'Add Spread'">
    <div class="settings-panel">
      <SpreadEditor
        ref="editorRef"
        :spread="editing"
        :categories="categories"
        @save="saveSpread"
        @cancel="cancelEdit"
      />
    </div>
  </AdminListPage>

  <!-- Pending Spread Preview -->
  <BaseModal
    :active="previewing !== null"
    :title="previewing?.name ?? ''"
    max-width="640px"
    @close="previewing = null"
  >
    <template v-if="previewing">
      <p v-if="previewing.submitter" class="has-text-grey mb-3">
        Submitted by {{ previewing.submitter }}
      </p>
      <SpreadLayoutPreview :positions="previewing.positions" class="pending-preview-shape" />
      <div class="mt-4">
        <p class="label mb-2">Card Positions</p>
        <ol class="pending-position-list">
          <li v-for="pos in sortedPositions(previewing)" :key="'title-' + pos.order">
            {{ pos.title || '(untitled)' }}
            <span v-if="pos.description" class="pending-position-about">{{ pos.description }}</span>
          </li>
        </ol>
      </div>

      <div v-if="previewing.description" class="content mt-4" style="white-space: pre-wrap">
        {{ previewing.description }}
      </div>

      <div class="field mt-4">
        <label :for="uid + '-approve-category'" class="label">Publish in</label>
        <BaseSelect
          :id="uid + '-approve-category'"
          v-model="approveCategoryId"
          :options="categoryOptions"
          placeholder="Choose a category"
        />
      </div>
    </template>

    <template v-if="previewing" #footer>
      <button
        class="button is-success"
        :class="{ 'is-loading': busyPendingId === previewing.pending_id }"
        @click="approvePending(previewing, approveCategoryId)"
      >
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['check']" /></span>
        <span>Approve</span>
      </button>
      <button class="button is-danger" @click="rejectPending(previewing)">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" /></span>
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

/* The layout as New Draw and the library show it, small enough to keep the
   positions and the category in view. */
.pending-preview-shape {
  max-width: 22rem;
  margin: 0 auto;
}

.pending-position-about {
  display: block;
  font-size: 0.85rem;
  color: var(--myst-text-muted);
}
</style>
