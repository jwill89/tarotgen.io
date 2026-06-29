<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { APP_VERSION } from '@/constants'
import type { UsageStats } from '@/types'

const api = useAdminApi()

const counts = ref({ deckSystems: 0, decks: 0, specialCards: 0, spreads: 0, readings: 0, changelog: 0, users: 0, unreadContacts: 0 })
const stats = ref<UsageStats | null>(null)
const loading = ref(true)

// Largest bar values, used to scale the simple CSS bar charts.
const maxDeck = computed(() => Math.max(1, ...(stats.value?.topDecks ?? []).map(d => d.count)))
const maxSpread = computed(() => Math.max(1, ...(stats.value?.topSpreads ?? []).map(s => s.count)))
const maxDaily = computed(() => Math.max(1, ...(stats.value?.daily ?? []).map(d => d.count)))

function dayLabel(date: string): string {
    // date is 'YYYY-MM-DD' (UTC); show day-of-month for a compact axis.
    return date.slice(8, 10)
}

onMounted(async () => {
    const data = await api.get<{ counts: typeof counts.value; stats: UsageStats }>('/summary')
    if (data) {
        counts.value = data.counts
        stats.value = data.stats
    }
    loading.value = false
})
</script>

<template>
    <section class="section">
        <div class="container">
            <h1 class="title is-3">
                Admin Dashboard
                <span class="tag is-primary is-light ml-2 app-version" title="Application version">v{{ APP_VERSION }}</span>
            </h1>
            <p class="subtitle is-5">Manage your tarot data.</p>

            <div class="columns is-multiline">
                <div class="column is-3">
                    <router-link :to="{ name: 'admin-decks' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-info">
                            <FontAwesomeIcon :icon="byPrefixAndName.fad['cards-blank']" size="2x" />
                        </span>
                        <p class="title is-4 mt-3">Decks</p>
                        <p class="subtitle is-6">{{ loading ? 'Loading…' : counts.decks + ' records' }}</p>
                    </router-link>
                </div>
                <div class="column is-3">
                    <router-link :to="{ name: 'admin-deck-systems' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-link">
                            <FontAwesomeIcon :icon="byPrefixAndName.fad['layer-group']" size="2x" />
                        </span>
                        <p class="title is-4 mt-3">Deck Systems</p>
                        <p class="subtitle is-6">{{ loading ? 'Loading…' : counts.deckSystems + ' systems' }}</p>
                    </router-link>
                </div>
                <div class="column is-3">
                    <router-link :to="{ name: 'admin-special-cards' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-warning">
                            <FontAwesomeIcon :icon="byPrefixAndName.fad['sparkles']" size="2x" />
                        </span>
                        <p class="title is-4 mt-3">Special Cards</p>
                        <p class="subtitle is-6">{{ loading ? 'Loading…' : counts.specialCards + ' records' }}</p>
                    </router-link>
                </div>
                <div class="column is-3">
                    <router-link :to="{ name: 'admin-spreads' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-link">
                            <FontAwesomeIcon :icon="byPrefixAndName.fad['table-cells']" size="2x" />
                        </span>
                        <p class="title is-4 mt-3">Spreads</p>
                        <p class="subtitle is-6">{{ loading ? 'Loading…' : counts.spreads + ' records' }}</p>
                    </router-link>
                </div>
                <div class="column is-3">
                    <router-link :to="{ name: 'admin-readings' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-grey-light">
                            <FontAwesomeIcon :icon="byPrefixAndName.fad['scroll']" size="2x" />
                        </span>
                        <p class="title is-4 mt-3">Readings</p>
                        <p class="subtitle is-6">{{ loading ? 'Loading…' : counts.readings + ' records' }}</p>
                    </router-link>
                </div>
                <div class="column is-3">
                    <router-link :to="{ name: 'admin-users' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-primary">
                            <FontAwesomeIcon :icon="byPrefixAndName.fad['users']" size="2x" />
                        </span>
                        <p class="title is-4 mt-3">Users</p>
                        <p class="subtitle is-6">{{ loading ? 'Loading…' : counts.users + ' accounts' }}</p>
                    </router-link>
                </div>
                <div class="column is-3">
                    <router-link :to="{ name: 'admin-contacts' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-success">
                            <FontAwesomeIcon :icon="byPrefixAndName.fad['envelope']" size="2x" />
                        </span>
                        <p class="title is-4 mt-3">Contacts</p>
                        <p class="subtitle is-6">{{ loading ? 'Loading…' : counts.unreadContacts + ' unread' }}</p>
                    </router-link>
                </div>
                <div class="column is-3">
                    <router-link :to="{ name: 'admin-changelog' }" class="box home-action has-text-centered">
                        <span class="icon is-large has-text-danger">
                            <FontAwesomeIcon :icon="byPrefixAndName.fad['newspaper']" size="2x" />
                        </span>
                        <p class="title is-4 mt-3">Changelog</p>
                        <p class="subtitle is-6">{{ loading ? 'Loading…' : counts.changelog + ' records' }}</p>
                    </router-link>
                </div>
            </div>

            <!-- Usage insights -->
            <p v-if="loading" class="has-text-grey mt-5">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['spinner']" spin /></span>
                <span>Loading insights…</span>
            </p>

            <template v-if="stats">
                <h2 class="title is-4 mt-6">Reading Insights</h2>

                <div class="columns is-multiline">
                    <div class="column is-4">
                        <div class="box stat-box">
                            <p class="stat-value">{{ stats.totals.readings.toLocaleString() }}</p>
                            <p class="stat-label">Total readings</p>
                        </div>
                    </div>
                    <div class="column is-4">
                        <div class="box stat-box">
                            <p class="stat-value">{{ stats.totals.last7.toLocaleString() }}</p>
                            <p class="stat-label">Last 7 days</p>
                        </div>
                    </div>
                    <div class="column is-4">
                        <div class="box stat-box">
                            <p class="stat-value">{{ stats.totals.last30.toLocaleString() }}</p>
                            <p class="stat-label">Last 30 days</p>
                        </div>
                    </div>
                </div>

                <div class="columns">
                    <!-- Readings per day -->
                    <div class="column is-7">
                        <div class="box">
                            <h3 class="title is-6">Readings per day (last 14)</h3>
                            <div class="daily-chart" v-if="stats.daily.length">
                                <div v-for="d in stats.daily" :key="d.date" class="daily-col" :title="d.date + ': ' + d.count">
                                    <div class="daily-bar" :style="{ height: Math.round((d.count / maxDaily) * 100) + '%' }"></div>
                                    <span class="daily-axis">{{ dayLabel(d.date) }}</span>
                                </div>
                            </div>
                            <p class="has-text-grey" v-else>No readings yet.</p>
                        </div>
                    </div>

                    <!-- Reading types -->
                    <div class="column is-5">
                        <div class="box">
                            <h3 class="title is-6">Reading types</h3>
                            <div class="type-row"><span>Free draw</span><strong>{{ stats.byType.freeDraw.toLocaleString() }}</strong></div>
                            <div class="type-row"><span>Spread</span><strong>{{ stats.byType.spread.toLocaleString() }}</strong></div>
                            <div class="type-row"><span>Custom</span><strong>{{ stats.byType.custom.toLocaleString() }}</strong></div>
                        </div>
                    </div>
                </div>

                <div class="columns">
                    <!-- Top decks -->
                    <div class="column is-6">
                        <div class="box">
                            <h3 class="title is-6">Most-used decks</h3>
                            <div v-for="d in stats.topDecks" :key="d.deck_id" class="rank-row">
                                <span class="rank-name">{{ d.name }}</span>
                                <span class="rank-track"><span class="rank-fill" :style="{ width: Math.round((d.count / maxDeck) * 100) + '%' }"></span></span>
                                <span class="rank-count">{{ d.count }}</span>
                            </div>
                            <p class="has-text-grey" v-if="stats.topDecks.length === 0">No data yet.</p>
                        </div>
                    </div>

                    <!-- Top spreads -->
                    <div class="column is-6">
                        <div class="box">
                            <h3 class="title is-6">Most-used spreads</h3>
                            <div v-for="s in stats.topSpreads" :key="s.name" class="rank-row">
                                <span class="rank-name">{{ s.name }}</span>
                                <span class="rank-track"><span class="rank-fill" :style="{ width: Math.round((s.count / maxSpread) * 100) + '%' }"></span></span>
                                <span class="rank-count">{{ s.count }}</span>
                            </div>
                            <p class="has-text-grey" v-if="stats.topSpreads.length === 0">No spread readings yet.</p>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </section>
</template>

<style scoped>
.app-version {
    vertical-align: middle;
    font-size: 0.6em;
    font-weight: 600;
}

.stat-box {
    text-align: center;
    border: 1px solid rgba(255, 255, 255, 0.12);
}

.stat-value {
    font-size: 2.25rem;
    font-weight: 800;
    line-height: 1;
}

.stat-label {
    margin-top: 0.35rem;
    opacity: 0.7;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-size: 0.8rem;
}

.daily-chart {
    display: flex;
    align-items: flex-end;
    gap: 0.35rem;
    height: 160px;
    padding-top: 0.5rem;
}

.daily-col {
    flex: 1 1 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    height: 100%;
    justify-content: flex-end;
    gap: 0.25rem;
}

.daily-bar {
    width: 100%;
    min-height: 2px;
    border-radius: 4px 4px 0 0;
    background: linear-gradient(to top, #6d4ed6, #b794f6);
}

.daily-axis {
    font-size: 0.7rem;
    opacity: 0.6;
}

.rank-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 0.5rem;
}

.rank-name {
    flex: 0 0 38%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 0.9rem;
}

.rank-track {
    flex: 1 1 auto;
    height: 0.6rem;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 999px;
    overflow: hidden;
}

.rank-fill {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(to right, #6d4ed6, #b794f6);
}

.rank-count {
    flex: none;
    min-width: 2rem;
    text-align: right;
    font-variant-numeric: tabular-nums;
    font-weight: 600;
}

.type-row {
    display: flex;
    justify-content: space-between;
    padding: 0.4rem 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.type-row:last-child { border-bottom: none; }
</style>
