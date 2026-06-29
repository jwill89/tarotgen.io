<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, reactive, computed, watch, onMounted, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import ReadingOwnerOptions from '@/components/ReadingOwnerOptions.vue'
import DeckComboBox from '@/components/DeckComboBox.vue'
import { useDecks } from '@/composables/useDecks'
import { useFavoriteDecks } from '@/composables/useFavoriteDecks'
import { useSpreads } from '@/composables/useSpreads'
import { useConfirm } from '@/composables/useConfirm'
import { local } from '@/utils/storage'
import { STORAGE_KEYS } from '@/constants'
import { useToasts } from '@/composables/useToasts'
import { useRecentReadings } from '@/composables/useRecentReadings'
import { readApiError } from '@/composables/useApi'
import { defaultDeckId } from '@/utils/deck'
import { usePanZoom } from '@/composables/usePanZoom'
import { useLayoutTools } from '@/composables/useLayoutTools'
import { useUndoRedo } from '@/composables/useUndoRedo'
import { cardAspectStyle } from '@/utils/cardAspect'
import type { DeckCard } from '@/types'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import BaseModal from '@/components/BaseModal.vue'

// Half a card's height as a % of the (square) canvas — for the floating controls.
const CARD_HALF_H_PCT = 9.46

const LAST_DECK_KEY = STORAGE_KEYS.lastDeck
// Snap matches the 2.5% grid cell so card centers land on grid intersections.
const SNAP = 2.5

// Canvas pan/zoom (on-screen only; coordinates stay 0–100%).
const {
    zoom, MIN_ZOOM, MAX_ZOOM, viewportRef,
    zoomIn, zoomOut, resetZoom,
    onPanStart, onPanMove, onPanEnd, onWheel, wasDragged, canvasStyle,
} = usePanZoom({ max: 3 })

// A background drag pans; a background click (no drag) deselects.
function onCanvasPointerUp(e: PointerEvent) {
    onPanEnd(e)
    if (!wasDragged()) clearSelection()
}

const router = useRouter()
const { decks, fetchDecks } = useDecks()
const { fetchFavoriteDecks } = useFavoriteDecks()
const { spreads, fetchSpreads } = useSpreads()
const { confirm } = useConfirm()

interface Slot {
    title: string
    x: number
    y: number
    rotation: number
    placed: boolean
    cardId: number | null
    reversed: boolean
}

const deckId = ref<number | null>(null)
const readingName = ref('')
const deckCards = ref<DeckCard[]>([])
const loadingCards = ref(false)
const cardCount = ref(1)
const baseSpreadId = ref<number | null>(null)
const slots = reactive<Slot[]>([])
const editIndex = ref<number | null>(null)
const snapToGrid = ref(true)
const isLoading = ref(false)
const ownerOptions = useTemplateRef<InstanceType<typeof ReadingOwnerOptions>>('ownerOptions')
const toasts = useToasts()
const { record: recordReading } = useRecentReadings()

const canvasRef = ref<HTMLElement | null>(null)
let draggingIndex = -1

onMounted(() => {
    fetchDecks()
    fetchSpreads()
    fetchFavoriteDecks()
    if (slots.length === 0) {
        slots.push(newSlot())
    }
    resetHistory()
})

// Spreads offered as base templates, alphabetised.
const sortedSpreads = computed(() =>
    [...spreads.value].sort((a, b) => a.name.localeCompare(b.name))
)

// Apply a saved spread as a starting layout: copy its positions (count, x/y,
// rotation, titles) into the slots, leaving each card unassigned so the user
// still chooses what sat where.
async function applyTemplate() {
    const spread = spreads.value.find(s => s.spread_id === baseSpreadId.value)
    if (!spread) return

    const hasWork = slots.some(s => s.placed || s.cardId !== null)
    if (hasWork) {
        const ok = await confirm({
            title: 'Apply spread template',
            message: `Replace your current layout with the "${spread.name}" template? The positions will be filled in for you — you'll still pick which card goes in each spot.`,
            confirmLabel: 'Apply template',
        })
        if (!ok) {
            baseSpreadId.value = null
            return
        }
    }

    const positions = [...spread.positions].sort((a, b) => a.order - b.order)
    slots.length = 0
    for (const p of positions) {
        slots.push({ title: p.title, x: p.x, y: p.y, rotation: p.rotation, placed: true, cardId: null, reversed: false })
    }
    if (slots.length === 0) slots.push(newSlot())
    cardCount.value = slots.length
    clearSelection()
    editIndex.value = null
    toasts.success(`Applied the "${spread.name}" template.`)
}

function newSlot(): Slot {
    return { title: '', x: 50, y: 50, rotation: 0, placed: false, cardId: null, reversed: false }
}

// Pick a default deck once decks load: prefer the user's remembered choice.
watch(decks, (val) => {
    if (val.length > 0 && deckId.value === null) {
        deckId.value = defaultDeckId(val, local.get<number | null>(LAST_DECK_KEY, null))
    }
}, { immediate: true })

// Load the deck's cards whenever the deck changes; clear stale selections.
watch(deckId, async (val) => {
    if (val === null) return
    local.set(LAST_DECK_KEY, val)
    slots.forEach(s => { s.cardId = null })
    loadingCards.value = true
    try {
        const res = await fetch('/api/deck/' + val + '/cards')
        deckCards.value = res.ok ? (await res.json() as DeckCard[]) : []
    } catch {
        deckCards.value = []
    } finally {
        loadingCards.value = false
    }
}, { immediate: true })

// Resize the slot list when the card count changes.
watch(cardCount, (val) => {
    const target = Math.max(1, Math.min(78, Math.floor(val || 1)))
    while (slots.length < target) slots.push(newSlot())
    while (slots.length > target) slots.pop()
    pruneSelection(slots.length)
})

const selectedDeck = computed(() =>
    deckId.value === null ? null : decks.value.find(d => d.deck_id === deckId.value) ?? null
)

const unplacedIndexes = computed(() =>
    slots.map((s, i) => ({ s, i })).filter(x => !x.s.placed).map(x => x.i)
)
const placedIndexes = computed(() =>
    slots.map((s, i) => ({ s, i })).filter(x => x.s.placed).map(x => x.i)
)

// Multi-select + alignment/distribution/centering tools (shared across editors).
const {
    selected, selectedCount, isSelected, setSelection, toggleSelection, clearSelection, pruneSelection,
    snapVal, centerAll, align, distribute,
} = useLayoutTools(slots, () => placedIndexes.value, snapToGrid, SNAP)

// Undo/redo over the slot layout. cardCount + selection are re-synced on restore.
const { canUndo, canRedo, undo, redo, reset: resetHistory, record, suspend, resume } = useUndoRedo(slots, () => {
    cardCount.value = slots.length
    pruneSelection(slots.length)
})

// Collapse modal edits (card / title / reversed) into a single history entry.
watch(editIndex, (val, old) => {
    if (val !== null) {
        suspend()
    } else if (old !== null) {
        resume()
        record()
    }
})

const allReady = computed(() =>
    slots.length > 0 && slots.every(s => s.placed && s.cardId !== null)
)

// A physical deck holds each card once, so track which cards are already chosen.
const usedCardIds = computed(
    () => new Set(slots.filter(s => s.cardId !== null).map(s => s.cardId))
)

// The slot currently open in the edit modal (null when closed).
const editingSlot = computed(() => (editIndex.value === null ? null : slots[editIndex.value]))

function cardThumb(cardId: number | null): string {
    if (cardId === null || deckId.value === null) return ''
    const filename = 'Card_' + String(cardId).padStart(4, '0')
    return '/assets/decks/' + deckId.value + '/thumbs/' + filename + '.webp'
}

// ── Placement & drag (mirrors the spread editor) ────────────
function placeFromTray(index: number) {
    const placedCount = placedIndexes.value.length
    slots[index].x = snapVal(40 + (placedCount % 5) * 5)
    slots[index].y = snapVal(40 + Math.floor(placedCount / 5) * 8)
    slots[index].placed = true
    setSelection([index])
}

function unplace(index: number) {
    slots[index].placed = false
    if (selected.value.has(index)) toggleSelection(index)
    if (editIndex.value === index) editIndex.value = null
}

function onCardPointerDown(e: PointerEvent, index: number) {
    e.stopPropagation() // don't let the background pan handler also fire

    // Shift/Ctrl/Cmd-click toggles multi-selection without starting a drag.
    if (e.shiftKey || e.ctrlKey || e.metaKey) {
        toggleSelection(index)
        return
    }

    if (!selected.value.has(index)) {
        setSelection([index])
    }
    draggingIndex = index
    suspend() // collapse the whole drag into one undo step
    ;(e.currentTarget as HTMLElement).setPointerCapture?.(e.pointerId)
    e.preventDefault()
}

function onDrag(e: PointerEvent) {
    if (draggingIndex < 0 || !canvasRef.value) return
    const rect = canvasRef.value.getBoundingClientRect()
    const x = ((e.clientX - rect.left) / rect.width) * 100
    const y = ((e.clientY - rect.top) / rect.height) * 100
    slots[draggingIndex].x = snapVal(x)
    slots[draggingIndex].y = snapVal(y)
}

function endDrag(e?: PointerEvent) {
    e?.stopPropagation() // keep the background pointerup (deselect) from firing
    if (draggingIndex !== -1) {
        draggingIndex = -1
        resume()
        record()
    }
}

function rotate(index: number, delta: number) {
    let r = (slots[index].rotation + delta) % 360
    if (r < 0) r += 360
    slots[index].rotation = r
}

function resetForm() {
    readingName.value = ''
    baseSpreadId.value = null
    cardCount.value = 1
    slots.length = 0
    slots.push(newSlot())
    clearSelection()
    editIndex.value = null
    resetHistory()
}

async function submit() {
    if (deckId.value === null) {
        toasts.warning('Please choose a deck.')
        return
    }
    if (!allReady.value) {
        toasts.warning('Every card must be placed on the layout and have a card selected.')
        return
    }

    const chosenIds = slots.map(s => s.cardId)
    if (new Set(chosenIds).size !== chosenIds.length) {
        toasts.warning('Each card can only be used once in a reading.')
        return
    }

    const cards = slots.map(s => ({
        card_id: s.cardId,
        reversed: s.reversed,
        title: s.title,
        x: s.x,
        y: s.y,
        rotation: s.rotation,
    }))

    const payload: Record<string, unknown> = { deck_id: deckId.value, name: readingName.value, cards }
    const opts = ownerOptions.value?.collect()
    if (opts) {
        if (opts.reading_name) payload.reading_name = opts.reading_name
        payload.hide_user = opts.hide_user
        if (opts.password) payload.password = opts.password
        if (opts.reading_notes) payload.reading_notes = opts.reading_notes
    }

    isLoading.value = true
    try {
        const res = await fetch('/api/reading/custom/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })

        if (!res.ok) {
            toasts.error(await readApiError(res, 'There was an error saving your reading.'))
            return
        }

        const data = await res.json()
        recordReading({
            id: data.reading_id,
            deckName: selectedDeck.value?.name ?? 'Tarot',
            summary: readingName.value.trim() || (slots.length + (slots.length === 1 ? ' card' : ' cards')),
            at: data.reading_time ?? new Date().toISOString(),
        })
        toasts.success('Your custom reading has been saved!')
        router.push({ name: 'reading', params: { id: data.reading_id } })
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
            <h1 class="title is-3 is-size-4-mobile">Recreate Draw</h1>
            <p class="subtitle is-5 is-size-6-mobile">
                Recreate a real spread by placing specific cards in the positions you want: choose the deck, place each card where
                it sat, name the positions, and pick the exact card (and orientation) for each spot.
            </p>

            <div class="columns">
                <div class="column is-6">
                    <div class="field">
                        <label class="label" for="cr-deck">Deck</label>
                        <DeckComboBox :decks="decks" v-model="deckId" />
                    </div>
                </div>
                <div class="column is-6">
                    <div class="field">
                        <label class="label" for="cr-name">Spread Name <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label>
                        <div class="control">
                            <input class="input" id="cr-name" v-model="readingName" maxlength="100" autocomplete="off" placeholder="e.g. My Morning Three-Card Pull" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="columns">
                <div class="column is-4">
                    <div class="field">
                        <label class="label" for="cr-count">Number of Cards</label>
                        <input class="input" id="cr-count" type="number" v-model.number="cardCount" min="1" max="78" />
                    </div>
                </div>
                <div class="column is-8">
                    <div class="field">
                        <label class="label" for="cr-template">Base on a Spread <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label>
                        <div class="control has-icons-left">
                            <div class="select is-fullwidth">
                                <select id="cr-template" v-model="baseSpreadId" @change="applyTemplate" autocomplete="off">
                                    <option :value="null">Start from scratch</option>
                                    <option v-for="s in sortedSpreads" :key="s.spread_id" :value="s.spread_id">
                                        {{ s.name }} ({{ s.card_count }} cards)
                                    </option>
                                </select>
                            </div>
                            <span class="icon is-small is-left"><FontAwesomeIcon :icon="byPrefixAndName.fas['table-cells']" /></span>
                        </div>
                        <p class="help">Pre-fills the card count and positions; you still choose which card goes in each spot.</p>
                    </div>
                </div>
            </div>

            <!-- Tray of unplaced cards -->
            <div class="field">
                <label class="label">Unplaced Cards <span class="has-text-grey is-size-7">(click to place, then drag to position)</span></label>
                <div class="spread-token-tray">
                    <button
                        v-for="i in unplacedIndexes"
                        :key="'tray-' + i"
                        class="spread-token"
                        @click="placeFromTray(i)"
                        :title="'Place card ' + (i + 1)"
                    >
                        <span>{{ i + 1 }}</span>
                    </button>
                    <span v-if="unplacedIndexes.length === 0" class="has-text-grey is-align-self-center">All cards placed.</span>
                </div>
            </div>

            <!-- Full-width layout canvas -->
            <div class="field">
                <!-- Layout toolbar: snap, undo/redo, center, align, distribute, zoom -->
                <div class="spread-tools is-flex is-flex-wrap-wrap is-align-items-center mb-2">
                    <label class="label mb-0 mr-1" title="Drag to move · shift-click to multi-select">Layout</label>
                    <span class="has-text-grey is-size-7 spread-tools-hint">{{ selectedCount }} selected</span>

                    <label class="toggle-switch">
                        <input type="checkbox" v-model="snapToGrid" />
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                        <span class="toggle-state">Snap to grid</span>
                    </label>

                    <div class="buttons has-addons are-small mb-0">
                        <button class="button is-small" tabindex="-1" :disabled="!canUndo" @click="undo" title="Undo">
                            <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-left']" /></span>
                        </button>
                        <button class="button is-small" tabindex="-1" :disabled="!canRedo" @click="redo" title="Redo">
                            <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-right']" /></span>
                        </button>
                    </div>

                    <button class="button is-small" :disabled="placedIndexes.length === 0" @click="centerAll" title="Center all cards in the panel (keeps spacing)">
                        <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-to-dot']" /></span>
                        <span>Center</span>
                    </button>
                    <div class="buttons has-addons are-small mb-0">
                        <span class="button is-static is-small">Align</span>
                        <button class="button is-small" :disabled="selectedCount < 2" @click="align('left')" title="Align left edges"><span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['align-left']" /></span></button>
                        <button class="button is-small" :disabled="selectedCount < 2" @click="align('hcenter')" title="Align horizontal centers"><span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['align-center']" /></span></button>
                        <button class="button is-small" :disabled="selectedCount < 2" @click="align('right')" title="Align right edges"><span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['align-right']" /></span></button>
                        <button class="button is-small" :disabled="selectedCount < 2" @click="align('top')" title="Align top edges"><span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-up']" /></span></button>
                        <button class="button is-small" :disabled="selectedCount < 2" @click="align('vmiddle')" title="Align to same height (vertical centers)"><span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['bars']" /></span></button>
                        <button class="button is-small" :disabled="selectedCount < 2" @click="align('bottom')" title="Align bottom edges"><span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-down']" /></span></button>
                    </div>
                    <div class="buttons has-addons are-small mb-0">
                        <span class="button is-static is-small">Distribute</span>
                        <button class="button is-small" :disabled="selectedCount < 3" @click="distribute('h')" title="Equal horizontal spacing"><span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-left-right']" /></span></button>
                        <button class="button is-small" :disabled="selectedCount < 3" @click="distribute('v')" title="Equal vertical spacing"><span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-up-down']" /></span></button>
                    </div>

                    <div class="buttons has-addons are-small mb-0 ml-auto">
                        <button class="button is-small" tabindex="-1" :disabled="zoom <= MIN_ZOOM" @click="zoomOut" title="Zoom out">
                            <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass-minus']" /></span>
                        </button>
                        <button class="button is-small" tabindex="-1" @click="resetZoom" title="Reset zoom" style="min-width: 3.5rem;">
                            {{ Math.round(zoom * 100) }}%
                        </button>
                        <button class="button is-small" tabindex="-1" :disabled="zoom >= MAX_ZOOM" @click="zoomIn" title="Zoom in">
                            <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass-plus']" /></span>
                        </button>
                    </div>
                </div>

                <div
                    class="spread-canvas-viewport"
                    :class="{ 'is-zoomed': zoom > 1 }"
                    :style="cardAspectStyle(selectedDeck)"
                    ref="viewportRef"
                    @pointerdown="onPanStart"
                    @pointermove="onPanMove"
                    @pointerup="onCanvasPointerUp"
                    @wheel="onWheel"
                >
                    <div
                        ref="canvasRef"
                        class="spread-canvas spread-canvas--zoomable"
                        :class="{ 'has-grid': snapToGrid }"
                        :style="canvasStyle"
                    >
                        <template v-for="i in placedIndexes" :key="'card-' + i">
                            <div
                                class="spread-card spread-card--editor"
                                :class="{ 'is-selected': isSelected(i), 'is-empty': slots[i].cardId === null }"
                                :style="{ left: slots[i].x + '%', top: slots[i].y + '%', '--rotation': slots[i].rotation + 'deg' }"
                                @pointerdown="onCardPointerDown($event, i)"
                                @pointermove="onDrag"
                                @pointerup="endDrag"
                            >
                                <span class="spread-order-badge">{{ i + 1 }}</span>
                                <img
                                    v-if="slots[i].cardId !== null"
                                    :src="cardThumb(slots[i].cardId)"
                                    :class="{ reversed: slots[i].reversed }"
                                    class="cr-card-img"
                                    alt=""
                                    draggable="false"
                                />
                            </div>

                            <!-- Floating controls for a single selected card -->
                            <div
                                v-if="selectedCount === 1 && isSelected(i)"
                                class="card-controls"
                                :style="{ left: slots[i].x + '%', top: (slots[i].y - CARD_HALF_H_PCT) + '%' }"
                                @pointerdown.stop
                                @pointerup.stop
                            >
                                <button class="button is-small" tabindex="-1" @click.stop="rotate(i, -15)" title="Rotate left">
                                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-left']" /></span>
                                </button>
                                <button class="button is-small" tabindex="-1" @click.stop="rotate(i, 15)" title="Rotate right">
                                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-right']" /></span>
                                </button>
                                <button class="button is-small" :class="{ 'is-warning': slots[i].reversed }" tabindex="-1" @click.stop="slots[i].reversed = !slots[i].reversed" title="Toggle reversed">
                                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrows-up-down']" /></span>
                                </button>
                                <button class="button is-small is-info" tabindex="-1" @click.stop="editIndex = i" title="Choose card / title">
                                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['pen']" /></span>
                                </button>
                                <button class="button is-small is-danger" tabindex="-1" @click.stop="unplace(i)" title="Remove from layout">
                                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" /></span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <ReadingOwnerOptions ref="ownerOptions" :notes="true" class="mt-4" />

            <div class="field is-grouped mt-4">
                <div class="control">
                    <button class="button is-primary is-medium" @click="submit">
                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['wand-magic-sparkles']" /></span>
                        <span>Recreate Draw</span>
                    </button>
                </div>
                <div class="control">
                    <button class="button is-medium" @click="resetForm">
                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['rotate-left']" /></span>
                        <span>Reset</span>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Per-position edit modal -->
    <BaseModal
        :active="editingSlot !== null"
        :title="editIndex !== null ? 'Position #' + (editIndex + 1) : ''"
        max-width="30rem"
        @close="editIndex = null"
    >
        <template v-if="editingSlot">
            <div class="field">
                <label class="label">Position Title <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label>
                <input class="input" v-model="editingSlot.title" placeholder="e.g. The Present" />
            </div>
            <div class="field">
                <label class="label">Card</label>
                <div class="control">
                    <div class="select is-fullwidth">
                        <select v-model.number="editingSlot.cardId" :disabled="loadingCards">
                            <option :value="null" disabled>{{ loadingCards ? 'Loading cards…' : 'Select a card…' }}</option>
                            <option
                                v-for="c in deckCards"
                                :key="c.card_id"
                                :value="c.card_id"
                                :disabled="editingSlot.cardId !== c.card_id && usedCardIds.has(c.card_id)"
                            >{{ c.name }}</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="field">
                <label class="toggle-switch">
                    <input type="checkbox" v-model="editingSlot.reversed" />
                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    <span class="toggle-state">Reversed</span>
                </label>
            </div>
        </template>
        <template #footer>
            <button class="button is-primary" @click="editIndex = null">Done</button>
        </template>
    </BaseModal>

    <LoadingOverlay v-if="isLoading" message="Saving your reading..." />
</template>

<style scoped>
.spread-tools {
    gap: 0.4rem 0.75rem;
}

.spread-tools-hint {
    min-width: 5rem;
}

.cr-card-img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 8px;
}

.cr-card-img.reversed {
    transform: rotate(180deg);
}

/* Placed slot with no card chosen yet: show the number prominently. */
.spread-card--editor.is-empty::after {
    content: "";
}

/* Floating control bar above the selected card; stays upright (not rotated). */
.card-controls {
    position: absolute;
    transform: translate(-50%, -100%);
    margin-top: -0.35rem;
    z-index: 6;
    display: flex;
    gap: 0.2rem;
    padding: 0.25rem;
    border-radius: 8px;
    background: var(--myst-surface);
    border: 1px solid var(--myst-border-strong);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.5);
}

.card-controls .button {
    height: 1.9rem;
    width: 1.9rem;
    padding: 0;
}
</style>
