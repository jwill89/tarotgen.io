<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useDataTable } from '@/composables/useDataTable'
import SortableTh from '@/components/admin/SortableTh.vue'
import PageHeader from '@/components/PageHeader.vue'
import IconButton from '@/components/IconButton.vue'
import Tooltip from '@/components/Tooltip.vue'
import { formatDateTime } from '@/utils/datetime'
import type { CardReport } from '@/types'

const api = useAdminApi()

const reports = ref<CardReport[]>([])
const showResolved = ref(false)
const busyId = ref<number | null>(null)

const {
  search,
  sortKey,
  sortDir,
  rows: visibleReports,
  toggleSort,
} = useDataTable(reports, {
  searchText: (r) => `${r.deck_name} ${r.card_name} ${r.card_id} ${r.deck_id}`,
  sortAccessors: {
    report_count: (r) => r.report_count,
    last_reported_at: (r) => r.last_reported_at,
    deck_name: (r) => r.deck_name,
    card_name: (r) => r.card_name,
  },
  initialSort: 'report_count',
  initialDir: 'desc',
})

/** Padded 4-digit card image path, matching the reading/deck asset layout. */
function cardId4(cardId: number): string {
  return String(cardId).padStart(4, '0')
}
function cardThumb(r: CardReport): string {
  return `/assets/decks/${r.deck_id}/thumbs/Card_${cardId4(r.card_id)}.webp`
}
function cardFull(r: CardReport): string {
  return `/assets/decks/${r.deck_id}/Card_${cardId4(r.card_id)}.png`
}

async function fetchReports() {
  const data = await api.get<CardReport[]>(endpoints.admin.cardReports.list(showResolved.value))
  if (data) reports.value = data
}

async function toggleResolved(report: CardReport) {
  busyId.value = report.report_id
  const result = await api.patch(endpoints.admin.cardReports.byId(report.report_id), {
    resolved: report.resolved_at === null,
  })
  busyId.value = null
  if (result) await fetchReports()
}

function toggleShowResolved() {
  showResolved.value = !showResolved.value
  void fetchReports()
}

onMounted(fetchReports)
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
            title="Errored Cards"
            subtitle="Card scans users have reported as having artefacts or issues to re-scan."
          >
            <button
              class="button"
              :class="showResolved ? 'is-link' : ''"
              @click="toggleShowResolved"
            >
              <span class="icon"
                ><FontAwesomeIcon
                  :icon="
                    showResolved ? byPrefixAndName.fas['eye'] : byPrefixAndName.fas['eye-slash']
                  "
              /></span>
              <span>{{ showResolved ? 'Showing Resolved' : 'Show Resolved' }}</span>
            </button>
          </PageHeader>

          <div class="field">
            <div class="control has-icons-left">
              <input
                v-model="search"
                class="input"
                type="text"
                placeholder="Search by deck or card..."
              />
              <span class="icon is-small is-left"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']"
              /></span>
            </div>
          </div>

          <div class="settings-panel">
            <div class="table-container">
              <table class="table is-fullwidth is-hoverable is-striped is-vcentered">
                <thead>
                  <tr>
                    <th>Card</th>
                    <SortableTh
                      label="Deck"
                      sort-key="deck_name"
                      :active-key="sortKey"
                      :dir="sortDir"
                      @sort="toggleSort"
                    />
                    <SortableTh
                      label="Name"
                      sort-key="card_name"
                      :active-key="sortKey"
                      :dir="sortDir"
                      @sort="toggleSort"
                    />
                    <SortableTh
                      label="Reports"
                      sort-key="report_count"
                      :active-key="sortKey"
                      :dir="sortDir"
                      @sort="toggleSort"
                    />
                    <SortableTh
                      label="Last Reported"
                      sort-key="last_reported_at"
                      :active-key="sortKey"
                      :dir="sortDir"
                      @sort="toggleSort"
                    />
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="report in visibleReports"
                    :key="report.report_id"
                    :class="{ 'has-text-grey': report.resolved_at !== null }"
                  >
                    <td>
                      <Tooltip text="Open full-size scan">
                        <a
                          :href="cardFull(report)"
                          target="_blank"
                          rel="noopener"
                          aria-label="Open full-size scan"
                        >
                          <img
                            :src="cardThumb(report)"
                            :alt="report.card_name"
                            style="height: 64px; width: auto; border-radius: 4px"
                          />
                        </a>
                      </Tooltip>
                    </td>
                    <td>{{ report.deck_name || 'Deck #' + report.deck_id }}</td>
                    <td>
                      {{ report.card_name || '—' }}
                      <span class="has-text-grey is-size-7">#{{ report.card_id }}</span>
                    </td>
                    <td>
                      <span
                        class="tag is-rounded"
                        :class="report.report_count > 1 ? 'is-danger' : 'is-warning'"
                      >
                        {{ report.report_count }}
                      </span>
                    </td>
                    <td>{{ formatDateTime(report.last_reported_at) }}</td>
                    <td>
                      <div class="row-actions">
                        <IconButton
                          :icon="
                            report.resolved_at
                              ? byPrefixAndName.fas['rotate-left']
                              : byPrefixAndName.fas['check']
                          "
                          :label="report.resolved_at ? 'Reopen' : 'Resolve'"
                          :intent="report.resolved_at ? 'warning' : 'success'"
                          :loading="busyId === report.report_id"
                          @click="toggleResolved(report)"
                        />
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <p v-if="reports.length === 0" class="has-text-grey">
            No reported cards{{ showResolved ? '' : ' (open)' }}.
          </p>
        </div>
      </div>
    </div>
  </section>
</template>
