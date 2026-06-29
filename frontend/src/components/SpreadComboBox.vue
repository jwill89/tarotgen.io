<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, nextTick } from 'vue'
import type { SpreadOption } from '@/types'
import { useFavoriteSpreads } from '@/composables/useFavoriteSpreads'
import { useUser } from '@/composables/useUser'

const props = defineProps<{
    options: SpreadOption[]
    modelValue: SpreadOption | null
}>()

const emit = defineEmits<{
    'update:modelValue': [value: SpreadOption | null]
    'free-draw': []
}>()

const { toggleFavorite } = useFavoriteSpreads()
const { isLoggedIn } = useUser()

const query = ref('')
const open = ref(false)
const highlightIndex = ref(-1)
const inputRef = ref<HTMLInputElement | null>(null)
const listRef = ref<HTMLElement | null>(null)
const freeDrawSelected = ref(props.modelValue === null)

const filtered = computed(() => {
    const q = query.value.toLowerCase().trim()
    if (!q) return props.options
    return props.options.filter(o => o.name.toLowerCase().includes(q))
})

const groupedOptions = computed(() => {
    const favs = filtered.value.filter(o => o.isFavorite)
    const personal = filtered.value.filter(o => o.type === 'personal' && !o.isFavorite)
    const pub = filtered.value.filter(o => o.type === 'public' && !o.isFavorite)
    const groups: { label: string; items: SpreadOption[] }[] = []
    if (favs.length > 0) groups.push({ label: 'Favorites', items: favs })
    if (personal.length > 0) groups.push({ label: 'My Spreads', items: personal })
    if (pub.length > 0) groups.push({ label: 'Public Spreads', items: pub })
    return groups
})

// Flat list for keyboard navigation
const flatItems = computed(() => groupedOptions.value.flatMap(g => g.items))

watch(() => props.modelValue, (val) => {
    if (val && !open.value) {
        query.value = val.name
        freeDrawSelected.value = false
    }
})

function onFocus() {
    open.value = true
    query.value = ''
    highlightIndex.value = -1
}

function onBlur() {
    // Delay so click on option registers first
    setTimeout(() => {
        open.value = false
        // Restore display name if nothing was picked
        if (props.modelValue) {
            query.value = props.modelValue.name
        } else if (freeDrawSelected.value) {
            query.value = 'No Spread (Free Draw)'
        } else {
            query.value = ''
        }
    }, 150)
}

function selectOption(option: SpreadOption | null) {
    emit('update:modelValue', option)
    open.value = false
    if (option) {
        query.value = option.name
        freeDrawSelected.value = false
    } else {
        query.value = 'No Spread (Free Draw)'
        freeDrawSelected.value = true
        emit('free-draw')
    }
    inputRef.value?.blur()
}

function onKeydown(e: KeyboardEvent) {
    if (!open.value) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            open.value = true
            e.preventDefault()
        }
        return
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault()
        highlightIndex.value = Math.min(highlightIndex.value + 1, flatItems.value.length - 1)
        scrollToHighlighted()
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        highlightIndex.value = Math.max(highlightIndex.value - 1, -1)
        scrollToHighlighted()
    } else if (e.key === 'Enter') {
        e.preventDefault()
        if (highlightIndex.value >= 0 && highlightIndex.value < flatItems.value.length) {
            selectOption(flatItems.value[highlightIndex.value])
        }
    } else if (e.key === 'Escape') {
        open.value = false
        inputRef.value?.blur()
    }
}

function scrollToHighlighted() {
    nextTick(() => {
        const el = listRef.value?.querySelector('.is-highlighted')
        el?.scrollIntoView({ block: 'nearest' })
    })
}

function clearSelection() {
    selectOption(null)
}

function onToggleFavorite(option: SpreadOption, e: Event) {
    e.stopPropagation()
    const spreadType = option.type === 'personal' ? 'personal' : 'public'
    const spreadId = option.type === 'personal' ? option.user_spread_id! : option.spread_id!
    toggleFavorite(spreadType, spreadId)
}
</script>

<template>
    <div class="spread-combobox">
        <div class="control has-icons-left" :class="{ 'has-icons-right': modelValue }">
            <input
                ref="inputRef"
                class="input"
                type="text"
                v-model="query"
                :placeholder="modelValue ? modelValue.name : (freeDrawSelected ? 'No Spread (Free Draw)' : 'Search spreads...')"
                autocomplete="off"
                @focus="onFocus"
                @blur="onBlur"
                @keydown="onKeydown"
            />
            <span class="icon is-small is-left">
                <FontAwesomeIcon :icon="byPrefixAndName.fas['table-cells']" />
            </span>
            <span v-if="modelValue" class="icon is-small is-right is-clickable" @mousedown.prevent="clearSelection">
                <FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" />
            </span>
        </div>

        <div v-show="open" ref="listRef" class="spread-combobox__dropdown">
            <div class="spread-combobox__item spread-combobox__item--none" @mousedown.prevent="selectOption(null)">
                <em>No Spread (Free Draw)</em>
            </div>

            <template v-if="groupedOptions.length > 0">
                <template v-for="group in groupedOptions" :key="group.label">
                    <div class="spread-combobox__group">{{ group.label }}</div>
                    <div
                        v-for="option in group.items"
                        :key="option.id"
                        class="spread-combobox__item"
                        :class="{ 'is-highlighted': flatItems.indexOf(option) === highlightIndex }"
                        @mousedown.prevent="selectOption(option)"
                    >
                        <span class="spread-combobox__item-name">{{ option.name }}</span>
                        <span class="spread-combobox__card-count ml-2">
                            {{ option.card_count }} card{{ option.card_count === 1 ? '' : 's' }}
                        </span>
                        <span
                            v-if="isLoggedIn"
                            class="spread-combobox__star ml-auto"
                            :class="{ 'is-active': option.isFavorite }"
                            :title="option.isFavorite ? 'Remove from favorites' : 'Add to favorites'"
                            @mousedown.prevent.stop="onToggleFavorite(option, $event)"
                        >
                            <FontAwesomeIcon :icon="option.isFavorite ? byPrefixAndName.fas['star'] : byPrefixAndName.far['star']" />
                        </span>
                    </div>
                </template>
            </template>

            <div v-else class="spread-combobox__empty">No spreads found.</div>
        </div>
    </div>
</template>

<style scoped>
.spread-combobox {
    position: relative;
}

.spread-combobox__dropdown {
    position: absolute;
    z-index: 30;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 280px;
    overflow-y: auto;
    background: var(--myst-surface);
    border: 1px solid var(--myst-border-strong);
    border-radius: 0 0 var(--bulma-radius) var(--bulma-radius);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}

.spread-combobox__group {
    padding: 0.4em 0.75em 0.2em;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--myst-text-muted);
    border-top: 1px solid var(--myst-border);
}

.spread-combobox__group:first-child {
    border-top: none;
}

.spread-combobox__item {
    padding: 0.5em 0.75em;
    cursor: pointer;
    display: flex;
    align-items: center;
    color: var(--myst-text);
}

.spread-combobox__item:hover,
.spread-combobox__item.is-highlighted {
    background: var(--myst-surface-3);
}

.spread-combobox__item--none {
    border-bottom: 1px solid var(--myst-border);
}

.spread-combobox__item-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.spread-combobox__card-count {
    font-size: 0.75rem;
    color: var(--myst-text-dim);
    white-space: nowrap;
}

.spread-combobox__star {
    flex-shrink: 0;
    cursor: pointer;
    opacity: 0.4;
    transition: opacity 0.15s, color 0.15s;
    font-size: 0.85rem;
    padding: 0.1em 0.25em;
    color: var(--myst-text-muted);
}

.spread-combobox__star:hover {
    opacity: 1;
    color: var(--myst-gold);
}

.spread-combobox__star.is-active {
    opacity: 1;
    color: var(--myst-gold);
}

.spread-combobox__empty {
    padding: 0.75em;
    color: var(--myst-text-muted);
    text-align: center;
    font-style: italic;
}

.is-clickable {
    pointer-events: auto !important;
    cursor: pointer;
}
</style>

