<script setup lang="ts">
/**
 * Spread picker built on Reka UI's Combobox. Same public API as before:
 * `options` + `modelValue` (a SpreadOption or null), `update:modelValue`, and a
 * `free-draw` emit. The null "No Spread (Free Draw)" choice is modelled as a
 * sentinel value so it can live in Reka's value-keyed selection, then mapped back
 * to null + the `free-draw` emit. Favourites / My Spreads / Public grouping and
 * the in-item favourite star are preserved.
 */
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed } from 'vue'
import type { SpreadOption } from '@/types'
import {
  ComboboxRoot,
  ComboboxAnchor,
  ComboboxInput,
  ComboboxTrigger,
  ComboboxPortal,
  ComboboxContent,
  ComboboxViewport,
  ComboboxGroup,
  ComboboxLabel,
  ComboboxItem,
  ComboboxEmpty,
} from 'reka-ui'
import { useFavoriteSpreads } from '@/composables/useFavoriteSpreads'
import { useUser } from '@/composables/useUser'
import Tooltip from '@/components/Tooltip.vue'

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

const searchTerm = ref('')
const freeDrawSelected = ref(props.modelValue === null)

// Sentinel so "No Spread (Free Draw)" is a real, value-keyed Combobox choice.
const FREE_DRAW_ID = '__free_draw__'
const FREE_DRAW = { id: FREE_DRAW_ID } as unknown as SpreadOption

const selected = computed<SpreadOption | undefined>({
  get: () => props.modelValue ?? (freeDrawSelected.value ? FREE_DRAW : undefined),
  set: (val) => {
    if (!val || val.id === FREE_DRAW_ID) {
      freeDrawSelected.value = true
      emit('update:modelValue', null)
      emit('free-draw')
    } else {
      freeDrawSelected.value = false
      emit('update:modelValue', val)
    }
  },
})

const filtered = computed(() => {
  const q = searchTerm.value.toLowerCase().trim()
  if (!q) return props.options
  return props.options.filter((o) => o.name.toLowerCase().includes(q))
})

const groupedOptions = computed(() => {
  const favs = filtered.value.filter((o) => o.isFavorite)
  const personal = filtered.value.filter((o) => o.type === 'personal' && !o.isFavorite)
  const pub = filtered.value.filter((o) => o.type === 'public' && !o.isFavorite)
  const groups: { label: string; items: SpreadOption[] }[] = []
  if (favs.length > 0) groups.push({ label: 'Favorites', items: favs })
  if (personal.length > 0) groups.push({ label: 'My Spreads', items: personal })
  if (pub.length > 0) groups.push({ label: 'Public Spreads', items: pub })
  return groups
})

function displayValue(val: SpreadOption | undefined): string {
  if (!val) return ''
  return val.id === FREE_DRAW_ID ? 'No Spread (Free Draw)' : val.name
}

function onOpenChange(open: boolean): void {
  if (open) searchTerm.value = ''
}

function selectFreeDraw(): void {
  selected.value = FREE_DRAW
}

function onToggleFavorite(option: SpreadOption): void {
  if (option.type === 'personal') {
    if (option.user_spread_id != null) void toggleFavorite('personal', option.user_spread_id)
  } else if (option.spread_id != null) {
    void toggleFavorite('public', option.spread_id)
  }
}
</script>

<template>
  <ComboboxRoot
    v-model="selected"
    v-model:search-term="searchTerm"
    by="id"
    :ignore-filter="true"
    :reset-search-term-on-blur="true"
    class="myst-combobox"
    @update:open="onOpenChange"
  >
    <ComboboxAnchor
      class="myst-combobox__anchor control has-icons-left"
      :class="{ 'has-clear': !!modelValue }"
    >
      <span class="icon is-small is-left">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['table-cells']" />
      </span>
      <ComboboxInput class="input" :display-value="displayValue" placeholder="Search spreads..." />
      <button
        v-if="modelValue"
        type="button"
        class="myst-combobox__clear"
        aria-label="Clear spread (free draw)"
        @pointerdown.prevent="selectFreeDraw"
      >
        <FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" />
      </button>
      <ComboboxTrigger class="myst-combobox__trigger" aria-label="Toggle spreads">
        <FontAwesomeIcon :icon="byPrefixAndName.fas['chevron-down']" />
      </ComboboxTrigger>
    </ComboboxAnchor>

    <ComboboxPortal>
      <ComboboxContent class="myst-combobox__content" position="popper" :side-offset="4">
        <ComboboxViewport class="myst-combobox__viewport">
          <ComboboxItem :value="FREE_DRAW" class="myst-combobox__item myst-combobox__item--none">
            No Spread (Free Draw)
          </ComboboxItem>

          <ComboboxEmpty class="myst-combobox__empty">No spreads found.</ComboboxEmpty>

          <ComboboxGroup
            v-for="group in groupedOptions"
            :key="group.label"
            class="myst-combobox__group-wrap"
          >
            <ComboboxLabel class="myst-combobox__group">{{ group.label }}</ComboboxLabel>
            <ComboboxItem
              v-for="option in group.items"
              :key="option.id"
              :value="option"
              class="myst-combobox__item"
            >
              <span class="myst-combobox__item-name">{{ option.name }}</span>
              <span class="myst-combobox__card-count ml-2">
                {{ option.card_count }} card{{ option.card_count === 1 ? '' : 's' }}
              </span>
              <Tooltip
                v-if="isLoggedIn"
                :text="option.isFavorite ? 'Remove from favorites' : 'Add to favorites'"
              >
                <span
                  class="myst-combobox__star ml-auto"
                  :class="{ 'is-active': option.isFavorite }"
                  :aria-label="option.isFavorite ? 'Remove from favorites' : 'Add to favorites'"
                  role="button"
                  @pointerdown.stop.prevent
                  @click.stop.prevent="onToggleFavorite(option)"
                >
                  <FontAwesomeIcon
                    :icon="
                      option.isFavorite ? byPrefixAndName.fas['star'] : byPrefixAndName.far['star']
                    "
                  />
                </span>
              </Tooltip>
            </ComboboxItem>
          </ComboboxGroup>
        </ComboboxViewport>
      </ComboboxContent>
    </ComboboxPortal>
  </ComboboxRoot>
</template>
