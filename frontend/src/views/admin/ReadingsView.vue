<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted, useTemplateRef, nextTick } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import { useToasts } from '@/composables/useToasts'
import { useDataTable } from '@/composables/useDataTable'
import SortableTh from '@/components/admin/SortableTh.vue'
import BaseModal from '@/components/BaseModal.vue'
import { formatDateTime } from '@/utils/datetime'

interface AdminReading {
    reading_id: string
    reading_time: string
    user_id: number | null
    display_name: string
    deck_id: number
    deck_name: string
    spread_name: string
}

const api = useAdminApi()
const { confirm } = useConfirm()
const toasts = useToasts()

const allReadings = ref<AdminReading[]>([])
const loading = ref(true)
const page = ref(1)
const perPage = ref(50)
const perPageOptions = [25, 50, 100, 200]
const topPaginationRef = useTemplateRef<HTMLElement>('topPaginationRef')

const { search, sortKey, sortDir, rows: sortedReadings, toggleSort } = useDataTable(allReadings, {
    searchText: r => `${r.reading_id} ${r.deck_name} ${r.spread_name} ${r.display_name}`,
    sortAccessors: {
        reading_time: r => r.reading_time,
        reading_id: r => r.reading_id,
        spread_name: r => r.spread_name,
        deck_name: r => r.deck_name,
        display_name: r => r.display_name,
    },
    initialSort: 'reading_time',
    initialDir: 'desc',
})

const totalFiltered = computed(() => sortedReadings.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(totalFiltered.value / perPage.value)))

// Clamp page when filtering reduces the total
const safePage = computed(() => Math.min(page.value, totalPages.value))

const pagedReadings = computed(() => {
    const start = (safePage.value - 1) * perPage.value
    return sortedReadings.value.slice(start, start + perPage.value)
})

/** Pages to show in the window around the current page (excludes first/last). */
const paginationWindow = computed(() => {
    const pages: number[] = []
    const current = safePage.value
    const start = Math.max(2, current - 1)
    const end = Math.min(totalPages.value - 1, current + 1)
    for (let p = start; p <= end; p++) {
        pages.push(p)
    }
    return pages
})

// Clean readings modal
const cleanModalActive = ref(false)
const cleanDays = ref(30)
const cleanBusy = ref(false)
const cleanOptions = [7, 14, 30, 60, 90, 180, 365]

async function fetchReadings() {
    loading.value = true
    const data = await api.get<{ rows: AdminReading[]; total: number }>(endpoints.admin.readings.list + '?limit=100000&offset=0')
    if (data) {
        allReadings.value = data.rows
    }
    loading.value = false
}

function goToPage(p: number) {
    if (p < 1 || p > totalPages.value) return
    page.value = p
}

function goToPageFromBottom(p: number) {
    if (p < 1 || p > totalPages.value) return
    page.value = p
    nextTick(() => {
        topPaginationRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    })
}

function viewReading(reading: AdminReading) {
    window.open(`/reading/${reading.reading_id}`, '_blank')
}

async function deleteReading(reading: AdminReading) {
    const ok = await confirm({
        title: 'Delete Reading',
        message: `Permanently delete reading "${reading.reading_id}"? This cannot be undone.`,
        confirmLabel: 'Delete',
        danger: true,
    })
    if (!ok) return

    const result = await api.del(endpoints.admin.readings.byId(reading.reading_id), 'Reading deleted.')
    if (result) {
        allReadings.value = allReadings.value.filter(r => r.reading_id !== reading.reading_id)
    }
}

async function cleanOldReadings() {
    cleanBusy.value = true
    const result = await api.del<{ success: boolean; deleted: number }>(
        endpoints.admin.readings.clean(cleanDays.value),
    )
    cleanBusy.value = false

    if (result) {
        toasts.success(`Cleaned ${result.deleted} guest reading(s) older than ${cleanDays.value} days.`)
        cleanModalActive.value = false
        await fetchReadings()
    }
}

onMounted(fetchReadings)
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
                        <h1 class="title is-3">Manage Readings</h1>
                        <p class="subtitle is-5">Browse and manage all saved readings.</p>
                    </div>
                </div>
                <div class="level-right">
                    <button class="button is-danger" @click="cleanModalActive = true">
                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['broom']" /></span>
                        <span>Clean Readings</span>
                    </button>
                </div>
            </div>

            <div class="field">
                <div class="control has-icons-left">
                    <input class="input" type="text" v-model="search" placeholder="Search by reading ID, deck, spread, or user..." />
                    <span class="icon is-small is-left"><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']" /></span>
                </div>
            </div>

            <p v-if="loading" class="has-text-grey">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin /></span>
                <span>Loading readings…</span>
            </p>

            <template v-else>
                <div class="level mb-4">
                    <div class="level-left">
                        <div class="level-item">
                            <div class="field has-addons">
                                <p class="control">
                                    <span class="button is-static">Per page</span>
                                </p>
                                <p class="control">
                                    <span class="select">
                                        <select v-model.number="perPage">
                                            <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
                                        </select>
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <nav ref="topPaginationRef" class="pagination is-centered mb-4" role="navigation" v-if="totalPages > 1">
                    <button class="pagination-previous" :disabled="safePage <= 1" @click="goToPage(safePage - 1)">Previous</button>
                    <button class="pagination-next" :disabled="safePage >= totalPages" @click="goToPage(safePage + 1)">Next</button>
                    <ul class="pagination-list">
                        <li>
                            <button class="pagination-link" :class="{ 'is-current': safePage === 1 }" @click="goToPage(1)">1</button>
                        </li>
                        <li v-if="safePage > 3"><span class="pagination-ellipsis">&hellip;</span></li>
                        <template v-for="p in paginationWindow" :key="'top-' + p">
                            <li>
                                <button class="pagination-link" :class="{ 'is-current': p === safePage }" @click="goToPage(p)">{{ p }}</button>
                            </li>
                        </template>
                        <li v-if="safePage < totalPages - 2"><span class="pagination-ellipsis">&hellip;</span></li>
                        <li v-if="totalPages > 1">
                            <button class="pagination-link" :class="{ 'is-current': safePage === totalPages }" @click="goToPage(totalPages)">{{ totalPages }}</button>
                        </li>
                    </ul>
                </nav>

                <div class="table-container">
                    <table class="table is-fullwidth is-hoverable is-striped">
                        <thead>
                            <tr>
                                <SortableTh label="Date" sort-key="reading_time" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                                <SortableTh label="Reading ID" sort-key="reading_id" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                                <SortableTh label="User" sort-key="display_name" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                                <SortableTh label="Spread" sort-key="spread_name" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                                <SortableTh label="Deck" sort-key="deck_name" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="reading in pagedReadings" :key="reading.reading_id">
                                <td>{{ formatDateTime(reading.reading_time) }}</td>
                                <td><code>{{ reading.reading_id }}</code></td>
                                <td>{{ reading.display_name }}</td>
                                <td>{{ reading.spread_name || '—' }}</td>
                                <td>{{ reading.deck_name }}</td>
                                <td>
                                    <div class="buttons are-small">
                                        <button class="button is-info is-small" @click="viewReading(reading)">
                                            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['eye']" /></span>
                                            <span>View</span>
                                        </button>
                                        <button class="button is-danger is-small" @click="deleteReading(reading)">
                                            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']" /></span>
                                            <span>Delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav class="pagination is-centered mt-4" role="navigation" v-if="totalPages > 1">
                    <button class="pagination-previous" :disabled="safePage <= 1" @click="goToPageFromBottom(safePage - 1)">Previous</button>
                    <button class="pagination-next" :disabled="safePage >= totalPages" @click="goToPageFromBottom(safePage + 1)">Next</button>
                    <ul class="pagination-list">
                        <li>
                            <button class="pagination-link" :class="{ 'is-current': safePage === 1 }" @click="goToPageFromBottom(1)">1</button>
                        </li>
                        <li v-if="safePage > 3"><span class="pagination-ellipsis">&hellip;</span></li>
                        <template v-for="p in paginationWindow" :key="'bot-' + p">
                            <li>
                                <button class="pagination-link" :class="{ 'is-current': p === safePage }" @click="goToPageFromBottom(p)">{{ p }}</button>
                            </li>
                        </template>
                        <li v-if="safePage < totalPages - 2"><span class="pagination-ellipsis">&hellip;</span></li>
                        <li v-if="totalPages > 1">
                            <button class="pagination-link" :class="{ 'is-current': safePage === totalPages }" @click="goToPageFromBottom(totalPages)">{{ totalPages }}</button>
                        </li>
                    </ul>
                </nav>

                <p class="has-text-grey mt-3">
                    Showing {{ pagedReadings.length }} of {{ totalFiltered.toLocaleString() }} readings (page {{ safePage }} of {{ totalPages }}).
                </p>
            </template>
        </div>
    </section>

    <BaseModal :active="cleanModalActive" title="Clean Guest Readings" max-width="32rem" @close="cleanModalActive = false">
        <p class="mb-4">
            Delete all readings that <strong>do not belong to a registered user</strong> and are older than the selected period.
        </p>
        <div class="field">
            <label class="label">Older than</label>
            <div class="control">
                <div class="select is-fullwidth">
                    <select v-model.number="cleanDays">
                        <option v-for="d in cleanOptions" :key="d" :value="d">{{ d }} days</option>
                    </select>
                </div>
            </div>
        </div>
        <template #footer>
            <button class="button" @click="cleanModalActive = false">Cancel</button>
            <button class="button is-danger" :class="{ 'is-loading': cleanBusy }" :disabled="cleanBusy" @click="cleanOldReadings">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['broom']" /></span>
                <span>Clean</span>
            </button>
        </template>
    </BaseModal>
</template>

