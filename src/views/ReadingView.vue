<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useDecks } from '@/composables/useDecks'
import { useSpreadLayout } from '@/composables/useSpreadLayout'
import { useToasts } from '@/composables/useToasts'
import { useRecentReadings } from '@/composables/useRecentReadings'
import { useReadingExport } from '@/composables/useReadingExport'
import { renderMarkdown } from '@/utils/markdown'
import { formatDateTime } from '@/utils/datetime'
import { cardAspectStyle } from '@/utils/cardAspect'
import type { Reading, ReadingInfo, ReadingCard, SpreadPosition } from '@/types'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import LightboxOverlay from '@/components/LightboxOverlay.vue'
import BaseModal from '@/components/BaseModal.vue'

const route = useRoute()
const { deckLookup } = useDecks()
const toasts = useToasts()
const { record: recordReading } = useRecentReadings()
const { exporting, exportReading } = useReadingExport()
const showExportModal = ref(false)

const reading = ref<Reading | null>(null)
const readingInfo = ref<ReadingInfo | null>(null)
const isLoading = ref(false)
const notFound = ref(false)
const lightboxIndex = ref<number | null>(null)

// Author label + custom title + password gate.
const readerName = ref('Guest')
const readingName = ref<string | null>(null)
const readingNotes = ref<string | null>(null)
const showNotes = ref(true)
const locked = ref(false)
const unlockPassword = ref('')
const unlocking = ref(false)
const unlockError = ref('')

// Custom title takes precedence over the generic heading.
const displayTitle = computed(() => readingName.value || 'Your Reading')
const readingNotesHtml = computed(() => (readingNotes.value ? renderMarkdown(readingNotes.value) : ''))

const readingDeck = computed(() => {
    if (!readingInfo.value) return null
    return deckLookup.value[readingInfo.value.deck_id] ?? null
})

const cardBackUrl = computed(() => {
    if (!readingInfo.value) return ''
    return '/assets/decks/' + readingInfo.value.deck_id + '/Card_Back.png'
})

const cardBackThumbUrl = computed(() => {
    if (!readingInfo.value) return ''
    return '/assets/decks/' + readingInfo.value.deck_id + '/thumbs/Card_Back.webp'
})

const readingUrl = computed(() => {
    if (!reading.value) return ''
    return 'https://tarotgen.io/reading/' + reading.value.reading_id
})

const readingCards = computed<ReadingCard[]>(() => {
    if (!readingInfo.value?.draw) return []
    const deckPath = '/assets/decks/' + readingInfo.value.deck_id
    return readingInfo.value.draw.map(card => {
        const filename = 'Card_' + String(card.card_id).padStart(4, '0')
        return {
            ...card,
            imgUrl: deckPath + '/' + filename + '.png',
            thumbUrl: deckPath + '/thumbs/' + filename + '.webp',
        }
    })
})

const spread = computed(() => readingInfo.value?.spread ?? null)
const showSpreadDetails = ref(false)

const spreadDescriptionHtml = computed(() =>
    spread.value ? renderMarkdown(spread.value.description) : ''
)

interface SpreadCard {
    position: SpreadPosition
    card: ReadingCard
    drawIndex: number
}

// Pair each spread position (by order) with the drawn card at the matching index.
const spreadCards = computed<SpreadCard[]>(() => {
    if (!spread.value) return []
    const cards = readingCards.value
    return [...spread.value.positions]
        .sort((a, b) => a.order - b.order)
        .map(position => {
            const drawIndex = position.order - 1
            return { position, card: cards[drawIndex], drawIndex }
        })
        .filter((sc): sc is SpreadCard => sc.card !== undefined)
})

// Spread canvas geometry (bounding-box fit + per-card positioning) is shared
// with the new-reading preview via this composable.
const { cardStyle } = useSpreadLayout(() => spread.value?.positions ?? [])

// Unified model for the Card Details panel + flip mechanic — covers both spreads
// and free draws (which have no positions/titles).
interface DetailItem {
    order: number
    title: string
    card: ReadingCard
    drawIndex: number
}

const detailItems = computed<DetailItem[]>(() => {
    if (spread.value) {
        return spreadCards.value.map(sc => ({
            order: sc.position.order,
            title: sc.position.title,
            card: sc.card,
            drawIndex: sc.drawIndex,
        }))
    }
    return readingCards.value.map((card, i) => ({ order: i + 1, title: '', card, drawIndex: i }))
})

// Free draws are shown as a "Free Draw" spread for display purposes.
const displaySpreadName = computed(() => spread.value?.name ?? 'Free Draw')

const allFlipped = computed(() =>
    detailItems.value.length > 0 && detailItems.value.every(d => flipped.value.has(d.drawIndex))
)

// Per-draw-index flip state for spread cards.
const flipped = ref<Set<number>>(new Set())
const flippingAll = ref(false)
// The card/row currently highlighted (hover or focus).
const activeIndex = ref<number | null>(null)

function flipCard(drawIndex: number) {
    const next = new Set(flipped.value)
    next.add(drawIndex)
    flipped.value = next
}

// Hover/focus a card or its details row → highlight the matching pair.
function setActive(drawIndex: number) {
    activeIndex.value = drawIndex
}

function clearActive() {
    if (!flippingAll.value) activeIndex.value = null
}

function onSpreadCardClick(drawIndex: number) {
    if (!flipped.value.has(drawIndex)) {
        // First tap reveals the card.
        flipCard(drawIndex)
        activeIndex.value = drawIndex
    } else {
        // Tapping an already-revealed card enlarges it.
        lightboxIndex.value = drawIndex
    }
}

// Toggle: reveal every remaining card one-by-one, or — if all are already
// revealed — flip them all back face-down (same flip animation either way).
async function flipAll() {
    if (flippingAll.value) return
    if (allFlipped.value) {
        flipped.value = new Set()
        activeIndex.value = null
        return
    }
    flippingAll.value = true
    for (const item of detailItems.value) {
        if (!flipped.value.has(item.drawIndex)) {
            flipCard(item.drawIndex)
            activeIndex.value = item.drawIndex
            await new Promise(resolve => setTimeout(resolve, 250))
        }
    }
    flippingAll.value = false
    activeIndex.value = null
}

function copyReadingUrl() {
    navigator.clipboard.writeText(readingUrl.value)
    toasts.success('Reading link copied to clipboard.')
}

function exportAt(scale: number) {
    showExportModal.value = false
    if (!reading.value || !readingInfo.value) return
    exportReading({
        fileName: 'tarot-reading-' + reading.value.reading_id + (scale > 1 ? '-hires' : '') + '.png',
        readingId: reading.value.reading_id,
        title: spread.value?.name || 'Tarot Reading',
        subtitle: (readingDeck.value?.name ?? 'Tarot') + '  ·  ' + formatDateTime(reading.value.reading_time),
        cards: readingCards.value.map(c => ({
            imgUrl: c.imgUrl,
            reversed: c.reversed,
            card_name: c.card_name,
        })),
        positions: spread.value?.positions ?? null,
        cardAspect: readingDeck.value && readingDeck.value.card_aspect_w > 0
            ? readingDeck.value.card_aspect_h / readingDeck.value.card_aspect_w
            : undefined,
        scale,
    })
}

// Populate view state from an accessible reading payload.
function applyReading(data: Reading) {
    reading.value = data
    const info = data.reading_info
    readingInfo.value = info
    readingName.value = data.reading_name ?? null
    readingNotes.value = data.reading_notes ?? null
    readerName.value = data.reader ?? 'Guest'
    locked.value = false
    recordReading({
        id: data.reading_id,
        deckName: deckLookup.value[info.deck_id]?.name ?? 'Tarot',
        summary: data.reading_name
            ?? info.spread?.name
            ?? (info.draw.length + (info.draw.length === 1 ? ' card' : ' cards')),
        at: data.reading_time,
    })
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function fetchReading(id: string) {
    isLoading.value = true
    notFound.value = false
    locked.value = false
    unlockError.value = ''
    unlockPassword.value = ''
    reading.value = null
    readingInfo.value = null
    readingName.value = null
    readingNotes.value = null
    readerName.value = 'Guest'
    try {
        const res = await fetch('/api/reading/' + encodeURIComponent(id))
        if (!res.ok) {
            notFound.value = true
            toasts.error('We couldn\'t find a reading with that code.')
            return
        }
        const data: Reading = await res.json()
        if (data.locked) {
            locked.value = true
            readingName.value = data.reading_name ?? null
            return
        }
        applyReading(data)
    } catch (e) {
        notFound.value = true
        toasts.error('Network error while loading the reading.', { detail: e })
    } finally {
        isLoading.value = false
    }
}

async function unlock() {
    const id = route.params.id
    if (typeof id !== 'string' || !id) return
    unlocking.value = true
    unlockError.value = ''
    try {
        const res = await fetch('/api/reading/' + encodeURIComponent(id) + '/unlock', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: unlockPassword.value }),
        })
        const data = await res.json().catch(() => ({}))
        if (res.ok && !data.locked) {
            applyReading(data as Reading)
        } else {
            unlockError.value = (data as { error?: string }).error || 'Incorrect password.'
        }
    } catch (e) {
        unlockError.value = 'Network error. Please try again.'
        toasts.error('Network error while unlocking the reading.', { detail: e })
    } finally {
        unlocking.value = false
    }
}

// Refetch whenever the :id changes — including reading → reading navigation
// (e.g. the navbar "Reading Code" box), where the component is reused and
// onMounted would not fire again. Reset the per-reading view state too.
watch(() => route.params.id, (id) => {
    if (typeof id !== 'string' || !id) return
    flipped.value = new Set()
    activeIndex.value = null
    showSpreadDetails.value = false
    lightboxIndex.value = null
    fetchReading(id)
}, { immediate: true })
</script>

<template>
    <section class="section" v-if="reading && readingInfo">
        <div class="container">
            <h1 class="title is-3 is-size-4-mobile">{{ displayTitle }}</h1>

            <div class="columns reading-top">
                <div class="column is-7">
                    <dl class="reading-meta box">
                        <div class="meta-row">
                            <dt><span class="icon"><i class="fa-solid fa-hashtag"></i></span>Reading ID</dt>
                            <dd><code>{{ reading.reading_id }}</code></dd>
                        </div>
                        <div class="meta-row">
                            <dt><span class="icon"><i class="fa-solid fa-link"></i></span>Reading URL</dt>
                            <dd class="meta-url">
                                <span class="reading-url-text">{{ readingUrl }}</span>
                                <button class="button is-small is-link" @click="copyReadingUrl" title="Copy Reading URL">
                                    <span class="icon is-small"><i class="fa-solid fa-copy"></i></span>
                                    <span>Copy</span>
                                </button>
                            </dd>
                        </div>
                        <div class="meta-row">
                            <dt><span class="icon"><i class="fa-solid fa-calendar-day"></i></span>Date</dt>
                            <dd>{{ formatDateTime(reading.reading_time) }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt><span class="icon"><i class="fa-solid fa-table-cells"></i></span>Spread</dt>
                            <dd>{{ displaySpreadName }}</dd>
                        </div>
                        <div class="meta-row">
                            <dt><span class="icon"><i class="fa-solid fa-user"></i></span>Reader</dt>
                            <dd>{{ readerName }}</dd>
                        </div>
                        <div class="meta-row" v-if="readingDeck">
                            <dt><span class="icon"><i class="fa-solid fa-book"></i></span>Deck</dt>
                            <dd>
                                {{ readingDeck.name }}
                                <span v-if="readingDeck.artist" class="meta-sub">· art by {{ readingDeck.artist }}</span>
                                <span v-if="readingDeck.system_short_name" class="tag is-info is-light ml-2">{{ readingDeck.system_short_name }}</span>
                            </dd>
                        </div>
                        <div class="meta-row" v-if="readingDeck && readingDeck.purchase_url">
                            <dt><span class="icon"><i class="fa-solid fa-cart-shopping"></i></span>Purchase</dt>
                            <dd><a :href="readingDeck.purchase_url" target="_blank" rel="noopener noreferrer">Buy this deck</a></dd>
                        </div>
                    </dl>

                    <!-- Free draw: actions sit under the reading details. -->
                    <div v-if="!spread" class="buttons mt-3">
                        <button class="button is-primary" @click="flipAll" :disabled="flippingAll">
                            <span class="icon"><i class="fa-solid fa-arrows-rotate"></i></span>
                            <span>{{ allFlipped ? 'Flip All Back' : 'Flip All' }}</span>
                        </button>
                        <button class="button is-link" @click="showExportModal = true" :class="{ 'is-loading': exporting }">
                            <span class="icon"><i class="fa-solid fa-image"></i></span>
                            <span>Save as Image</span>
                        </button>
                    </div>
                </div>

                <!-- Spread description sits beside the details to use the space. -->
                <div class="column is-5" v-if="spread && spread.description">
                    <div class="spread-details">
                        <button
                            class="spread-details-header"
                            @click="showSpreadDetails = !showSpreadDetails"
                            :aria-expanded="showSpreadDetails ? 'true' : 'false'"
                        >
                            <span class="icon">
                                <i class="fa-solid" :class="showSpreadDetails ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                            </span>
                            <span>Spread Details: {{ spread.name }}</span>
                        </button>
                        <div class="spread-details-body content" v-show="showSpreadDetails" v-html="spreadDescriptionHtml"></div>
                    </div>
                </div>
            </div>

            <!-- Free draw: cards use the full width; no per-card details list. -->
            <div v-if="!spread" class="reading-grid" :style="cardAspectStyle(readingDeck)">
                <div
                    v-for="item in detailItems"
                    :key="'g-' + item.drawIndex"
                    class="reading-grid-card"
                    :class="{ 'is-flipped': flipped.has(item.drawIndex), 'is-active': activeIndex === item.drawIndex }"
                    @mouseenter="setActive(item.drawIndex)"
                    @mouseleave="clearActive"
                    @focusin="setActive(item.drawIndex)"
                >
                    <div
                        class="spread-flip"
                        @click="onSpreadCardClick(item.drawIndex)"
                        role="button"
                        tabindex="0"
                        @keydown.enter.prevent="onSpreadCardClick(item.drawIndex)"
                        @keydown.space.prevent="onSpreadCardClick(item.drawIndex)"
                    >
                        <div class="spread-face spread-face--back">
                            <img :src="cardBackThumbUrl" alt="Card Back" />
                            <span class="spread-order-badge">{{ item.order }}</span>
                        </div>
                        <div class="spread-face spread-face--reveal">
                            <img :src="item.card.thumbUrl" :class="{ reversed: item.card.reversed }" :alt="item.card.card_name" loading="lazy" />
                        </div>
                    </div>
                    <p class="reading-grid-caption">
                        <template v-if="flipped.has(item.drawIndex)">
                            {{ item.card.card_name }}<span v-if="item.card.reversed" class="has-text-warning"> (Reversed)</span>
                        </template>
                        <span v-else class="has-text-grey is-italic">Card {{ item.order }}</span>
                    </p>
                </div>
            </div>

            <!-- Spread: the canvas and card details sit side by side. -->
            <div v-else class="columns reading-main">
                <div class="column is-7-desktop">
                    <div class="spread-canvas reading-canvas" :style="cardAspectStyle(readingDeck)">
                        <div
                            v-for="sc in spreadCards"
                            :key="sc.drawIndex"
                            class="spread-card"
                            :class="{ 'is-flipped': flipped.has(sc.drawIndex), 'is-active': activeIndex === sc.drawIndex }"
                            :style="cardStyle(sc.position)"
                            @mouseenter="setActive(sc.drawIndex)"
                            @mouseleave="clearActive"
                            @focusin="setActive(sc.drawIndex)"
                        >
                            <div
                                class="spread-flip"
                                @click="onSpreadCardClick(sc.drawIndex)"
                                role="button"
                                tabindex="0"
                                @keydown.enter.prevent="onSpreadCardClick(sc.drawIndex)"
                                @keydown.space.prevent="onSpreadCardClick(sc.drawIndex)"
                            >
                                <div class="spread-face spread-face--back">
                                    <img :src="cardBackThumbUrl" alt="Card Back" />
                                    <span class="spread-order-badge">{{ sc.position.order }}</span>
                                </div>
                                <div class="spread-face spread-face--reveal">
                                    <img :src="sc.card.thumbUrl" :class="{ reversed: sc.card.reversed }" :alt="sc.card.card_name" loading="lazy" />
                                    <span class="spread-order-badge spread-order-badge--reveal">{{ sc.position.order }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="column is-5-desktop">
                    <!-- Card details + actions -->
                    <div class="reading-details">
                        <h2 class="spread-detail-list-title">Card Details</h2>
                        <p class="has-text-grey is-size-7 mb-3">
                            Tap each card to reveal it. Tap a revealed card again to enlarge it.
                        </p>

                        <div class="reading-detail-grid">
                            <div
                                v-for="item in detailItems"
                                :key="'row-' + item.drawIndex"
                                class="spread-detail-row"
                                :class="{ 'is-active': activeIndex === item.drawIndex, 'is-revealed': flipped.has(item.drawIndex) }"
                                @mouseenter="setActive(item.drawIndex)"
                                @mouseleave="clearActive"
                                @click="onSpreadCardClick(item.drawIndex)"
                            >
                                <span class="spread-detail-order">{{ item.order }}</span>
                                <span class="spread-detail-text">
                                    <strong v-if="item.title">{{ item.title }}</strong>
                                    <template v-if="flipped.has(item.drawIndex)">
                                        <span>{{ item.card.card_name }}</span>
                                        <span v-if="item.card.reversed" class="has-text-warning">(Reversed)</span>
                                    </template>
                                    <span v-else class="has-text-grey is-italic">Not yet revealed</span>
                                </span>
                            </div>
                        </div>

                        <div class="buttons mt-4">
                            <button class="button is-primary" @click="flipAll" :disabled="flippingAll">
                                <span class="icon"><i class="fa-solid fa-arrows-rotate"></i></span>
                                <span>{{ allFlipped ? 'Flip All Back' : 'Flip All' }}</span>
                            </button>
                            <button class="button is-link" @click="showExportModal = true" :class="{ 'is-loading': exporting }">
                                <span class="icon"><i class="fa-solid fa-image"></i></span>
                                <span>Save as Image</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reading Notes: full-width detailed interpretation, below the cards. -->
            <div v-if="readingNotes" class="reading-notes mt-5">
                <button
                    class="spread-details-header"
                    @click="showNotes = !showNotes"
                    :aria-expanded="showNotes ? 'true' : 'false'"
                >
                    <span class="icon">
                        <i class="fa-solid" :class="showNotes ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                    </span>
                    <span>Reading Notes</span>
                </button>
                <div class="spread-details-body content" v-show="showNotes" v-html="readingNotesHtml"></div>
            </div>
        </div>
    </section>

    <!-- Password-protected: prompt before revealing anything. -->
    <section class="section" v-else-if="locked && !isLoading">
        <div class="container">
            <div class="columns is-centered">
                <div class="column is-5-desktop is-7-tablet">
                    <h1 class="title is-3 is-size-4-mobile has-text-centered">
                        {{ readingName || 'Protected Reading' }}
                    </h1>
                    <div class="box has-text-centered">
                        <span class="icon is-large has-text-warning">
                            <i class="fa-solid fa-lock fa-2x"></i>
                        </span>
                        <p class="mt-3 mb-4">This reading is password-protected. Enter the password to view it.</p>
                        <form @submit.prevent="unlock">
                            <div class="field">
                                <div class="control has-icons-left">
                                    <input
                                        class="input"
                                        type="password"
                                        v-model="unlockPassword"
                                        placeholder="Reading password"
                                        autocomplete="off"
                                        autofocus
                                    />
                                    <span class="icon is-small is-left"><i class="fa-solid fa-key"></i></span>
                                </div>
                            </div>
                            <div class="notification is-danger is-light" v-if="unlockError">{{ unlockError }}</div>
                            <button
                                class="button is-primary is-fullwidth"
                                type="submit"
                                :class="{ 'is-loading': unlocking }"
                                :disabled="unlocking || !unlockPassword"
                            >
                                <span class="icon"><i class="fa-solid fa-unlock"></i></span>
                                <span>View Reading</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" v-else-if="notFound && !isLoading">
        <div class="container has-text-centered">
            <span class="icon is-large has-text-grey-light">
                <i class="fa-solid fa-circle-question fa-2x"></i>
            </span>
            <h1 class="title is-4 mt-3">Reading not found</h1>
            <p class="subtitle is-6">
                We couldn't find a reading with that code. It may have been mistyped.
            </p>
            <div class="buttons is-centered mt-4">
                <router-link class="button is-primary" :to="{ name: 'new-reading' }">
                    <span class="icon"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                    <span>Start a New Draw</span>
                </router-link>
                <router-link class="button" :to="{ name: 'home' }">Back to Home</router-link>
            </div>
        </div>
    </section>

    <LoadingOverlay v-if="isLoading" message="Loading your reading..." />

    <LightboxOverlay
        v-if="lightboxIndex !== null"
        :cards="readingCards"
        :card-back-url="cardBackUrl"
        :initial-index="lightboxIndex"
        @close="lightboxIndex = null"
    />

    <BaseModal :active="showExportModal" title="Save reading image" max-width="30rem" @close="showExportModal = false">
        <p class="mb-4">Choose an image size:</p>
        <div class="export-options">
            <button class="button is-medium export-option" @click="exportAt(1)">
                <span class="export-option-main">
                    <strong>Standard</strong>
                    <span class="export-option-sub">Best for quick sharing · ~1600px wide</span>
                </span>
            </button>
            <button class="button is-medium is-primary export-option" @click="exportAt(2)">
                <span class="export-option-main">
                    <strong>High resolution</strong>
                    <span class="export-option-sub">Larger, sharper cards · ~3200px wide</span>
                </span>
            </button>
        </div>
    </BaseModal>
</template>

<style scoped>
.export-options {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.export-option {
    height: auto;
    padding: 0.9rem 1.1rem;
    justify-content: flex-start;
    text-align: left;
    white-space: normal;
}

.export-option-main {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.export-option-sub {
    font-size: 0.8rem;
    font-weight: 400;
    opacity: 0.75;
}
</style>
