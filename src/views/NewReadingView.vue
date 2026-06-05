<script setup lang="ts">
import { reactive, computed, watch, ref, onMounted, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import ReadingOwnerOptions from '@/components/ReadingOwnerOptions.vue'
import SpreadComboBox from '@/components/SpreadComboBox.vue'
import DeckComboBox from '@/components/DeckComboBox.vue'
import { useDecks } from '@/composables/useDecks'
import { useFavoriteDecks } from '@/composables/useFavoriteDecks'
import { useSpreads } from '@/composables/useSpreads'
import { useSpreadLayout } from '@/composables/useSpreadLayout'
import { useToasts } from '@/composables/useToasts'
import { useRecentReadings } from '@/composables/useRecentReadings'
import { readApiError } from '@/composables/useApi'
import { renderMarkdown } from '@/utils/markdown'
import { deckLabel, defaultDeckId } from '@/utils/deck'
import { cardAspectStyle } from '@/utils/cardAspect'
import { local } from '@/utils/storage'
import { STORAGE_KEYS } from '@/constants'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import type { SpreadOption } from '@/types'

const LAST_DECK_KEY = STORAGE_KEYS.lastDeck

const router = useRouter()
const { decks } = useDecks()
const { fetchFavoriteDecks } = useFavoriteDecks()
const { spreads, spreadOptions, fetchSpreads } = useSpreads()
const toasts = useToasts()
const { record: recordReading } = useRecentReadings()

const isLoading = ref(false)
const ownerOptions = useTemplateRef<InstanceType<typeof ReadingOwnerOptions>>('ownerOptions')
const selectedSpreadOption = ref<SpreadOption | null>(null)
const freeDrawChosen = ref(true)

const form = reactive({
    deckId: null as number | null,
    spreadId: null as number | null,
    userSpreadId: null as number | null,
    numberOfCards: 1,
    useReversals: false,
    useAdditionalCards: false,
    freeDrawMode: 'standard' as 'standard' | 'placement',
})

onMounted(() => {
    fetchSpreads()
    fetchFavoriteDecks()
})

// Set the deck once loaded: prefer the user's last pick (if still available),
// otherwise default to Rider-Waite.
watch(decks, (val) => {
    if (val.length > 0 && form.deckId === null) {
        form.deckId = defaultDeckId(val, local.get<number | null>(LAST_DECK_KEY, null))
    }
}, { immediate: true })

// Remember the chosen deck across visits/sessions.
watch(() => form.deckId, (val) => {
    if (val !== null) {
        local.set(LAST_DECK_KEY, val)
    }
})

// Reset additional cards when deck changes
watch(() => form.deckId, () => {
    form.useAdditionalCards = false
})

const selectedDeck = computed(() => {
    if (form.deckId === null) return null
    return decks.value.find(d => d.deck_id === form.deckId) ?? null
})

// Free-draw max: the deck's system total, plus its extras only when that toggle is on.
const maxCards = computed(() => {
    const d = selectedDeck.value
    if (!d) return 78
    const baseCards = d.system_total_cards || d.total_cards
    return baseCards + (form.useAdditionalCards ? d.additional_cards : 0)
})

// Keep the requested count within the deck's available range (free draw only).
watch(maxCards, (max) => {
    if (!selectedSpreadOption.value && form.numberOfCards > max) {
        form.numberOfCards = max
    }
})

const selectedSpread = computed(() => {
    if (!selectedSpreadOption.value) return null
    return {
        spread_id: selectedSpreadOption.value.spread_id ?? 0,
        user_spread_id: selectedSpreadOption.value.user_spread_id,
        name: selectedSpreadOption.value.name,
        description: selectedSpreadOption.value.description,
        card_count: selectedSpreadOption.value.card_count,
        positions: selectedSpreadOption.value.positions,
        type: selectedSpreadOption.value.type,
    }
})

// Sync combo selection back to form IDs.
watch(selectedSpreadOption, (val) => {
    if (val) {
        form.spreadId = val.spread_id ?? null
        form.userSpreadId = val.user_spread_id ?? null
        freeDrawChosen.value = false
    } else {
        form.spreadId = null
        form.userSpreadId = null
    }
})

function onFreeDrawSelected() {
    freeDrawChosen.value = true
}

// A spread is chosen (either explicit or free draw default), so the rest of the form can render.
const spreadReady = computed(() => selectedSpreadOption.value !== null || freeDrawChosen.value)

const spreadDescriptionHtml = computed(() =>
    selectedSpread.value ? renderMarkdown(selectedSpread.value.description) : ''
)

const selectedSpreadPositions = computed(() => {
    if (!selectedSpread.value) return []
    return [...selectedSpread.value.positions].sort((a, b) => a.order - b.order)
})

// Spread canvas geometry (auto-fit + projection) is shared with the reading
// view via this composable so the preview trims dead space identically.
const { cardSize, project } = useSpreadLayout(selectedSpreadPositions)

// Build the preview cards, and for each pick a number position that isn't
// covered by a later (on-top) card. The canvas is square, so x/y percentages
// share the same pixel scale and we can do plain point-in-rotated-rect math.
interface PreviewCard {
    order: number
    style: Record<string, string>
    numStyle: Record<string, string>
}

const previewCards = computed<PreviewCard[]>(() => {
    const positions = selectedSpreadPositions.value
    const { w: cardW, h: cardH } = cardSize.value
    const toRad = (d: number) => (d * Math.PI) / 180

    const cards = positions.map(p => {
        const { x, y } = project(p)
        return { order: p.order, rot: p.rotation, gx: x, gy: y }
    })

    // Is global point (px,py) inside card c's rotated rectangle?
    const inside = (px: number, py: number, c: typeof cards[number]) => {
        const r = toRad(-c.rot)
        const dx = px - c.gx
        const dy = py - c.gy
        const lx = dx * Math.cos(r) - dy * Math.sin(r)
        const ly = dx * Math.sin(r) + dy * Math.cos(r)
        return Math.abs(lx) <= cardW / 2 && Math.abs(ly) <= cardH / 2
    }

    // Candidate number spots as a fraction of the card's half-size: centre
    // first, then edge midpoints, then corners.
    const candidates: [number, number][] = [
        [0, 0],
        [0, -0.68], [0, 0.68], [-0.68, 0], [0.68, 0],
        [-0.62, -0.62], [0.62, -0.62], [-0.62, 0.62], [0.62, 0.62],
    ]

    return cards.map((c, i) => {
        const onTop = cards.filter((_, j) => j > i)
        let chosen = candidates[0]
        for (const [fx, fy] of candidates) {
            const lx = fx * (cardW / 2)
            const ly = fy * (cardH / 2)
            const r = toRad(c.rot)
            const gx = c.gx + (lx * Math.cos(r) - ly * Math.sin(r))
            const gy = c.gy + (lx * Math.sin(r) + ly * Math.cos(r))
            if (!onTop.some(lc => inside(gx, gy, lc))) {
                chosen = [fx, fy]
                break
            }
        }
        return {
            order: c.order,
            style: {
                left: c.gx + '%',
                top: c.gy + '%',
                width: cardW + '%',
                '--rotation': c.rot + 'deg',
            },
            numStyle: {
                left: (50 + chosen[0] * 50) + '%',
                top: (50 + chosen[1] * 50) + '%',
            },
        }
    })
})

// Custom titles the user can enter per position (aligned with sorted positions).
const positionTitles = ref<string[]>([])

// A spread dictates its own card count and resets the custom titles.
watch(selectedSpread, (val) => {
    if (val) {
        form.numberOfCards = val.card_count
        positionTitles.value = selectedSpreadPositions.value.map(() => '')
    } else {
        positionTitles.value = []
    }
})

function resetForm() {
    form.deckId = defaultDeckId(decks.value)
    form.spreadId = null
    form.userSpreadId = null
    form.useAdditionalCards = false
    form.numberOfCards = 1
    form.useReversals = false
    form.freeDrawMode = 'standard'
    selectedSpreadOption.value = null
    freeDrawChosen.value = true
    positionTitles.value = []
}

async function submitNewReading() {
    isLoading.value = true
    try {
        const body = new URLSearchParams({
            deck_id: String(form.deckId),
            number_of_cards: String(form.numberOfCards),
            use_reversals: String(form.useReversals),
            use_additional_cards: String(form.useAdditionalCards),
        })

        if (form.spreadId !== null) {
            body.set('spread_id', String(form.spreadId))
            body.set('position_titles', JSON.stringify(positionTitles.value))
        } else if (form.userSpreadId !== null) {
            body.set('user_spread_id', String(form.userSpreadId))
            body.set('position_titles', JSON.stringify(positionTitles.value))
        }

        const opts = ownerOptions.value?.collect()
        if (opts) {
            if (opts.reading_name) body.set('reading_name', opts.reading_name)
            body.set('hide_user', String(opts.hide_user))
            if (opts.password) body.set('password', opts.password)
        }

        const res = await fetch('/api/reading/new/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })

        if (!res.ok) {
            toasts.error(await readApiError(res, 'There was an error generating your reading.'))
            return
        }

        const data = await res.json()
        recordReading({
            id: data.reading_id,
            deckName: selectedDeck.value?.name ?? 'Tarot',
            summary: selectedSpread.value
                ? selectedSpread.value.name
                : form.numberOfCards + (form.numberOfCards === 1 ? ' card' : ' cards'),
            at: data.reading_time ?? new Date().toISOString(),
        })

        // Free Draw With Placement: redirect to the placement editor instead.
        if (!selectedSpreadOption.value && form.freeDrawMode === 'placement') {
            toasts.success('Cards drawn! Now arrange your spread.')
            router.push({ name: 'free-draw-placement', params: { id: data.reading_id } })
        } else {
            toasts.success('Your reading is ready!')
            router.push({ name: 'reading', params: { id: data.reading_id } })
        }
    } catch (e) {
        toasts.error('Network error. Please check your connection and try again.', { detail: e })
    } finally {
        isLoading.value = false
    }
}
</script>

<template>
    <section class="section">
        <div class="container">
            <div class="columns is-centered">
                <div class="column is-8-desktop is-10-tablet">
                    <h1 class="title is-3 is-size-4-mobile">New Draw</h1>
                    <p class="subtitle is-5 is-size-6-mobile">Choose the settings for your draw.</p>

                    <div class="field">
                        <label class="label" for="deck_id">Deck</label>
                        <DeckComboBox :decks="decks" v-model="form.deckId" />
                    </div>

                    <div class="notification is-warning is-light" v-if="selectedDeck && selectedDeck.additional_cards > 0">
                        <p class="mb-2"><strong>This deck has {{ selectedDeck.additional_cards }} extra card{{ selectedDeck.additional_cards === 1 ? '' : 's' }}.</strong> Turn this on to allow them to be drawn in your reading.</p>
                        <div class="control">
                            <label class="toggle-switch">
                                <input type="checkbox" v-model="form.useAdditionalCards" />
                                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                                <span class="toggle-state">{{ form.useAdditionalCards ? 'On' : 'Off' }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="field">
                        <label class="label">Spread</label>
                        <SpreadComboBox
                            :options="spreadOptions"
                            v-model="selectedSpreadOption"
                            @free-draw="onFreeDrawSelected"
                        />
                    </div>

                    <div class="columns" v-if="selectedSpread">
                        <div class="column" v-if="selectedSpread.description">
                            <div class="notification spread-description">
                                <div class="content" v-html="spreadDescriptionHtml"></div>
                            </div>
                        </div>
                        <div class="column is-half">
                            <div class="spread-canvas spread-canvas--preview has-grid mx-auto" :style="cardAspectStyle(selectedDeck)">
                                <div
                                    v-for="card in previewCards"
                                    :key="'preview-' + card.order"
                                    class="spread-card spread-card--editor"
                                    :style="card.style"
                                >
                                    <span class="preview-number" :style="card.numStyle">{{ card.order }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="field" v-if="selectedSpread">
                        <label class="label">
                            Position Titles
                            <span class="has-text-grey is-size-7 has-text-weight-normal">(optional — leave blank to use the spread's defaults)</span>
                        </label>
                        <div class="columns is-multiline">
                            <div class="column is-6" v-for="(pos, idx) in selectedSpreadPositions" :key="'title-' + pos.order">
                                <div class="field has-addons mb-0">
                                    <p class="control">
                                        <span class="button is-static">#{{ pos.order }}</span>
                                    </p>
                                    <p class="control is-expanded">
                                        <input
                                            class="input"
                                            v-model="positionTitles[idx]"
                                            :placeholder="pos.title || ('Position ' + pos.order)"
                                            autocomplete="off"
                                        />
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="field" v-if="freeDrawChosen && !selectedSpread">
                        <label class="label">Free Draw Mode</label>
                        <div class="myst-segmented">
                            <button
                                type="button"
                                class="myst-segmented__btn"
                                :class="{ 'is-active': form.freeDrawMode === 'standard' }"
                                @click="form.freeDrawMode = 'standard'"
                            >
                                <span class="icon"><i class="fa-solid fa-shuffle"></i></span>
                                <span>Standard</span>
                            </button>
                            <button
                                type="button"
                                class="myst-segmented__btn"
                                :class="{ 'is-active': form.freeDrawMode === 'placement' }"
                                @click="form.freeDrawMode = 'placement'"
                            >
                                <span class="icon"><i class="fa-solid fa-hand-pointer"></i></span>
                                <span>With Placement</span>
                            </button>
                        </div>
                        <p class="help" v-if="form.freeDrawMode === 'standard'">Cards are drawn and displayed in order.</p>
                        <p class="help" v-else>After drawing, you'll arrange the cards on a spread canvas and name each position.</p>
                    </div>

                    <div class="columns is-multiline" v-if="spreadReady">
                        <div class="column is-4-desktop is-6-tablet">
                            <div class="field">
                                <label class="label" for="number_of_cards">Number of Cards</label>
                                <div class="control has-icons-left">
                                    <input class="input" type="number" id="number_of_cards" v-model.number="form.numberOfCards" min="1" :max="maxCards" autocomplete="off" :disabled="selectedSpread !== null" />
                                    <span class="icon is-small is-left">
                                        <i class="fa-solid fa-diamond"></i>
                                    </span>
                                </div>
                                <p class="help" v-if="selectedSpread">Set by the selected spread.</p>
                                <p class="help" v-else>Up to {{ maxCards }} cards for this deck.</p>
                            </div>
                        </div>
                        <div class="column is-4-desktop is-6-tablet">
                            <div class="field">
                                <label class="label">Reversed Cards</label>
                                <div class="control">
                                    <label class="toggle-switch">
                                        <input type="checkbox" v-model="form.useReversals" />
                                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                                        <span class="toggle-state">{{ form.useReversals ? 'On' : 'Off' }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <ReadingOwnerOptions v-if="spreadReady" ref="ownerOptions" />

                    <div class="field">
                        <div class="buttons">
                            <button class="button is-primary is-medium" @click="submitNewReading">
                                <span class="icon"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                                <span>Generate New Draw</span>
                            </button>
                            <button class="button is-medium" @click="resetForm">
                                <span class="icon"><i class="fa-solid fa-rotate-left"></i></span>
                                <span>Reset</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <LoadingOverlay v-if="isLoading" message="Drawing your cards..." />
</template>
