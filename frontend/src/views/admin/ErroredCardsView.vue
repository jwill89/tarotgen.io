<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useDataTable } from '@/composables/useDataTable'
import SortableTh from '@/components/admin/SortableTh.vue'
import BaseModal from '@/components/BaseModal.vue'
import PageHeader from '@/components/PageHeader.vue'
import IconButton from '@/components/IconButton.vue'
import Tooltip from '@/components/Tooltip.vue'
import { formatDateTime } from '@/utils/datetime'
import {
  currentSubmissions,
  earlierSubmissions,
  reasonLabel,
  reportsWithoutReason,
  tallyReasons,
} from '@/utils/cardReports'
import type { CardReport } from '@/types'

const api = useAdminApi()

const reports = ref<CardReport[]>([])
const showResolved = ref(false)
const busyId = ref<number | null>(null)
const viewing = ref<CardReport | null>(null)

const {
  search,
  sortKey,
  sortDir,
  rows: visibleReports,
  toggleSort,
} = useDataTable(reports, {
  searchText: (r) =>
    [
      r.deck_name,
      r.card_name,
      r.card_id,
      r.deck_id,
      ...r.submissions.map((s) => `${reasonLabel(s.reason)} ${s.details}`),
    ].join(' '),
  sortAccessors: {
    report_count: (r) => r.report_count,
    last_reported_at: (r) => r.last_reported_at,
    deck_name: (r) => r.deck_name,
    card_name: (r) => r.card_name,
  },
  initialSort: 'report_count',
  initialDir: 'desc',
})

// Each row summarises its current problem: the reasons given since the card was
// last resolved, plus the newest description so an admin can often act without
// opening the full list.
const tableRows = computed(() =>
  visibleReports.value.map((report) => {
    const current = currentSubmissions(report)
    return {
      report,
      reasons: tallyReasons(current),
      latestDetails: current.find((s) => s.details !== '')?.details ?? '',
    }
  }),
)

const viewingCurrent = computed(() => (viewing.value ? currentSubmissions(viewing.value) : []))
const viewingEarlier = computed(() => (viewing.value ? earlierSubmissions(viewing.value) : []))
const viewingWithoutReason = computed(() =>
  viewing.value ? reportsWithoutReason(viewing.value) : 0,
)

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
function deckLabel(r: CardReport): string {
  return r.deck_name || `Deck #${r.deck_id}`
}
function cardLabel(r: CardReport): string {
  return r.card_name || `Card #${r.card_id}`
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
            subtitle="Cards users have reported, and what they say is wrong with each image."
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
                placeholder="Search by deck, card, or reason..."
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
                    <th>Reason</th>
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
                    v-for="row in tableRows"
                    :key="row.report.report_id"
                    :class="{ 'has-text-grey': row.report.resolved_at !== null }"
                  >
                    <td>
                      <Tooltip text="Open full-size scan">
                        <a
                          :href="cardFull(row.report)"
                          target="_blank"
                          rel="noopener"
                          aria-label="Open full-size scan"
                        >
                          <img
                            :src="cardThumb(row.report)"
                            :alt="row.report.card_name"
                            style="height: 64px; width: auto; border-radius: 4px"
                          />
                        </a>
                      </Tooltip>
                    </td>
                    <td>{{ deckLabel(row.report) }}</td>
                    <td>
                      {{ row.report.card_name || '—' }}
                      <span class="has-text-grey is-size-7">#{{ row.report.card_id }}</span>
                    </td>
                    <td>
                      <template v-if="row.reasons.length">
                        <div class="reason-chips">
                          <span v-for="r in row.reasons" :key="r.reason" class="data-chip">
                            {{ r.label }}<template v-if="r.count > 1"> ×{{ r.count }}</template>
                          </span>
                        </div>
                        <p v-if="row.latestDetails" class="reason-snippet">
                          “{{ row.latestDetails }}”
                        </p>
                      </template>
                      <span v-else class="has-text-grey is-size-7">No reason given</span>
                    </td>
                    <td>
                      <span
                        class="tag is-rounded"
                        :class="row.report.report_count > 1 ? 'is-danger' : 'is-warning'"
                      >
                        {{ row.report.report_count }}
                      </span>
                    </td>
                    <td>{{ formatDateTime(row.report.last_reported_at) }}</td>
                    <td>
                      <div class="row-actions">
                        <IconButton
                          :icon="byPrefixAndName.fas['eye']"
                          label="View reports"
                          @click="viewing = row.report"
                        />
                        <IconButton
                          :icon="
                            row.report.resolved_at
                              ? byPrefixAndName.fas['rotate-left']
                              : byPrefixAndName.fas['check']
                          "
                          :label="row.report.resolved_at ? 'Reopen' : 'Resolve'"
                          :intent="row.report.resolved_at ? 'warning' : 'success'"
                          :loading="busyId === row.report.report_id"
                          @click="toggleResolved(row.report)"
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

  <BaseModal
    :active="viewing !== null"
    :title="viewing ? cardLabel(viewing) : ''"
    max-width="40rem"
    @close="viewing = null"
  >
    <template v-if="viewing">
      <div class="report-summary">
        <Tooltip text="Open full-size scan">
          <a
            :href="cardFull(viewing)"
            target="_blank"
            rel="noopener"
            aria-label="Open full-size scan"
            class="report-summary__scan"
          >
            <img :src="cardThumb(viewing)" :alt="viewing.card_name" />
          </a>
        </Tooltip>
        <dl class="report-summary__facts">
          <div>
            <dt>Deck</dt>
            <dd>{{ deckLabel(viewing) }}</dd>
          </div>
          <div>
            <dt>Card</dt>
            <dd>#{{ viewing.card_id }}</dd>
          </div>
          <div>
            <dt>Status</dt>
            <dd>
              <span class="status-tag" :class="viewing.resolved_at ? 'is-on' : 'is-warn'">
                {{
                  viewing.resolved_at ? 'Resolved ' + formatDateTime(viewing.resolved_at) : 'Open'
                }}
              </span>
            </dd>
          </div>
          <div>
            <dt>Reports</dt>
            <dd>{{ viewing.report_count }}</dd>
          </div>
          <div>
            <dt>First reported</dt>
            <dd>{{ formatDateTime(viewing.first_reported_at) }}</dd>
          </div>
          <div>
            <dt>Last reported</dt>
            <dd>{{ formatDateTime(viewing.last_reported_at) }}</dd>
          </div>
        </dl>
      </div>

      <ul v-if="viewingCurrent.length" class="report-list">
        <li v-for="s in viewingCurrent" :key="s.submission_id" class="report-entry">
          <div class="report-entry__head">
            <span class="data-chip">{{ reasonLabel(s.reason) }}</span>
            <span class="report-entry__date">{{ formatDateTime(s.submitted_at) }}</span>
          </div>
          <p v-if="s.details" class="report-entry__details">{{ s.details }}</p>
          <p v-else class="report-entry__none">No details given.</p>
        </li>
      </ul>

      <template v-if="viewingEarlier.length">
        <p class="report-section-title">Resolved earlier</p>
        <ul class="report-list is-earlier">
          <li v-for="s in viewingEarlier" :key="s.submission_id" class="report-entry">
            <div class="report-entry__head">
              <span class="data-chip">{{ reasonLabel(s.reason) }}</span>
              <span class="report-entry__date">{{ formatDateTime(s.submitted_at) }}</span>
            </div>
            <p v-if="s.details" class="report-entry__details">{{ s.details }}</p>
            <p v-else class="report-entry__none">No details given.</p>
          </li>
        </ul>
      </template>

      <p v-if="viewingWithoutReason" class="report-note">
        {{ viewingWithoutReason }}
        {{ viewingWithoutReason === 1 ? 'report came' : 'reports came' }} without a reason — sent
        before reasons were collected, or from an older plugin version.
      </p>
    </template>
  </BaseModal>
</template>

<style scoped>
.reason-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}

.reason-snippet {
  max-width: 16rem;
  margin-top: 0.35rem;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: 0.82rem;
  color: var(--myst-text-muted);
}

/* ── View-reports dialog ───────────────────────────────────── */
.report-summary {
  display: flex;
  align-items: flex-start;
  gap: 1.1rem;
  padding-bottom: 1.1rem;
  margin-bottom: 1.1rem;
  border-bottom: 1px solid var(--myst-border);
}

.report-summary__scan {
  flex: none;
  display: block;
  line-height: 0;
}

.report-summary__scan img {
  height: 7.5rem;
  width: auto;
  border-radius: 6px;
  border: 1px solid var(--myst-border-strong);
}

.report-summary__facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem 1.25rem;
  flex: 1 1 auto;
  margin: 0;
}

.report-summary__facts dt {
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--myst-text-dim);
}

.report-summary__facts dd {
  margin: 0.1rem 0 0;
  color: var(--myst-text);
}

.report-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin: 0;
  list-style: none;
}

.report-entry {
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--myst-border);
  border-radius: 10px;
  background: var(--myst-surface-2);
}

.report-entry__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.report-entry__date {
  font-size: 0.8rem;
  color: var(--myst-text-dim);
  white-space: nowrap;
}

.report-entry__details {
  margin-top: 0.5rem;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  color: var(--myst-text);
}

.report-entry__none {
  margin-top: 0.4rem;
  font-size: 0.85rem;
  color: var(--myst-text-dim);
}

.report-section-title {
  margin: 1.25rem 0 0.6rem;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--myst-text-dim);
}

/* Earlier rounds were already dealt with — recessive, but still AA-legible
   (muted text token rather than opacity, which would drop below the floor). */
.report-list.is-earlier .report-entry {
  background: transparent;
}
.report-list.is-earlier .report-entry__details {
  color: var(--myst-text-muted);
}

.report-note {
  margin-top: 1rem;
  font-size: 0.85rem;
  color: var(--myst-text-muted);
}

@media screen and (max-width: 480px) {
  .report-summary {
    flex-direction: column;
  }
}
</style>
