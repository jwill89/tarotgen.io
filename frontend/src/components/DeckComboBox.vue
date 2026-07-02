<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, nextTick } from 'vue'
import type { Deck } from '@/types'
import { useFavoriteDecks } from '@/composables/useFavoriteDecks'
import { useUser } from '@/composables/useUser'

const props = defineProps<{
  decks: Deck[]
  modelValue: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const { isFavorite, toggleFavorite } = useFavoriteDecks()
const { isLoggedIn } = useUser()

const query = ref('')
const open = ref(false)
const highlightIndex = ref(-1)
const inputRef = ref<HTMLInputElement | null>(null)
const listRef = ref<HTMLElement | null>(null)

const selectedDeck = computed(() => props.decks.find((d) => d.deck_id === props.modelValue) ?? null)

const filtered = computed(() => {
  const q = query.value.toLowerCase().trim()
  if (!q) return props.decks
  return props.decks.filter(
    (d) =>
      d.name.toLowerCase().includes(q) ||
      d.artist.toLowerCase().includes(q) ||
      d.system_short_name.toLowerCase().includes(q),
  )
})

const groupedOptions = computed(() => {
  const favs = filtered.value.filter((d) => isFavorite(d.deck_id))
  const rest = filtered.value.filter((d) => !isFavorite(d.deck_id))

  // Group the rest by system_short_name
  const systemGroups: Record<string, Deck[]> = {}
  for (const deck of rest) {
    const key = deck.system_short_name || 'Other'
    if (!Object.hasOwn(systemGroups, key)) systemGroups[key] = []
    systemGroups[key].push(deck)
  }

  const groups: { label: string; items: Deck[] }[] = []
  if (favs.length > 0) groups.push({ label: 'Favorites', items: favs })
  for (const [system, decks] of Object.entries(systemGroups).sort((a, b) =>
    a[0].localeCompare(b[0]),
  )) {
    groups.push({ label: system, items: decks })
  }
  return groups
})

const flatItems = computed(() => groupedOptions.value.flatMap((g) => g.items))

watch(
  () => props.modelValue,
  () => {
    if (!open.value && selectedDeck.value) {
      query.value = selectedDeck.value.name
    }
  },
)

function onFocus() {
  open.value = true
  query.value = ''
  highlightIndex.value = -1
}

function onBlur() {
  setTimeout(() => {
    open.value = false
    if (selectedDeck.value) {
      query.value = selectedDeck.value.name
    } else {
      query.value = ''
    }
  }, 150)
}

function selectOption(deck: Deck) {
  emit('update:modelValue', deck.deck_id)
  open.value = false
  query.value = deck.name
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
    highlightIndex.value = Math.min(Number(highlightIndex.value) + 1, flatItems.value.length - 1)
    scrollToHighlighted()
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    highlightIndex.value = Math.max(highlightIndex.value - 1, 0)
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

function onToggleFavorite(deck: Deck, e: Event) {
  e.stopPropagation()
  void toggleFavorite(deck.deck_id)
}
</script>

<template>
  <div class="deck-combobox">
    <div class="control has-icons-left">
      <input
        ref="inputRef"
        v-model="query"
        class="input"
        type="text"
        :placeholder="selectedDeck ? selectedDeck.name : 'Search decks...'"
        autocomplete="off"
        @focus="onFocus"
        @blur="onBlur"
        @keydown="onKeydown"
      />
      <span class="icon is-small is-left">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['book']" />
      </span>
    </div>

    <div v-show="open" ref="listRef" class="deck-combobox__dropdown">
      <template v-if="groupedOptions.length > 0">
        <template v-for="group in groupedOptions" :key="group.label">
          <div class="deck-combobox__group">{{ group.label }}</div>
          <div
            v-for="deck in group.items"
            :key="deck.deck_id"
            class="deck-combobox__item"
            :class="{
              'is-highlighted': flatItems.indexOf(deck) === highlightIndex,
              'is-selected': deck.deck_id === modelValue,
            }"
            @mousedown.prevent="selectOption(deck)"
          >
            <span class="deck-combobox__item-name">{{ deck.name }}</span>
            <span class="deck-combobox__item-artist ml-2">{{ deck.artist }}</span>
            <span v-if="deck.additional_cards > 0" class="deck-combobox__extra ml-2"
              >+{{ deck.additional_cards }}</span
            >
            <span
              v-if="isLoggedIn"
              class="deck-combobox__star ml-auto"
              :class="{ 'is-active': isFavorite(deck.deck_id) }"
              :title="isFavorite(deck.deck_id) ? 'Remove from favorites' : 'Add to favorites'"
              @mousedown.prevent.stop="onToggleFavorite(deck, $event)"
            >
              <FontAwesomeIcon
                :icon="
                  isFavorite(deck.deck_id)
                    ? byPrefixAndName.fas['star']
                    : byPrefixAndName.far['star']
                "
              />
            </span>
          </div>
        </template>
      </template>

      <div v-else class="deck-combobox__empty">No decks found.</div>
    </div>
  </div>
</template>

<style scoped>
.deck-combobox {
  position: relative;
}

.deck-combobox__dropdown {
  position: absolute;
  z-index: 30;
  top: 100%;
  left: 0;
  right: 0;
  max-height: 300px;
  overflow-y: auto;
  background: var(--myst-surface);
  border: 1px solid var(--myst-border-strong);
  border-radius: 0 0 var(--bulma-radius) var(--bulma-radius);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}

.deck-combobox__group {
  padding: 0.4em 0.75em 0.2em;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--myst-text-muted);
  border-top: 1px solid var(--myst-border);
}

.deck-combobox__group:first-child {
  border-top: none;
}

.deck-combobox__item {
  padding: 0.5em 0.75em;
  cursor: pointer;
  display: flex;
  align-items: center;
  color: var(--myst-text);
}

.deck-combobox__item:hover,
.deck-combobox__item.is-highlighted {
  background: var(--myst-surface-3);
}

.deck-combobox__item.is-selected {
  font-weight: 600;
}

.deck-combobox__item-name {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.deck-combobox__item-artist {
  font-size: 0.75rem;
  color: var(--myst-text-dim);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.deck-combobox__extra {
  font-size: 0.7rem;
  background: var(--myst-surface-3);
  color: var(--myst-text-muted);
  padding: 0.1em 0.4em;
  border-radius: 3px;
  white-space: nowrap;
}

.deck-combobox__star {
  flex-shrink: 0;
  cursor: pointer;
  opacity: 0.4;
  transition:
    opacity 0.15s,
    color 0.15s;
  font-size: 0.85rem;
  padding: 0.1em 0.25em;
  color: var(--myst-text-muted);
}

.deck-combobox__star:hover {
  opacity: 1;
  color: var(--myst-gold);
}

.deck-combobox__star.is-active {
  opacity: 1;
  color: var(--myst-gold);
}

.deck-combobox__empty {
  padding: 0.75em;
  color: var(--myst-text-muted);
  text-align: center;
  font-style: italic;
}
</style>
