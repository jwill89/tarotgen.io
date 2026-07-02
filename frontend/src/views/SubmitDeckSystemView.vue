<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, watch, nextTick } from 'vue'
import { useUser } from '@/composables/useUser'
import { useToasts } from '@/composables/useToasts'
import { endpoints } from '@/api/endpoints'
import type { DeckSystemCard } from '@/types'

const { isLoggedIn } = useUser()
const toasts = useToasts()

const name = ref('')
const shortName = ref('')
const totalCards = ref(78)
const cards = ref<Partial<DeckSystemCard>[]>([])
const submitting = ref(false)
const submitted = ref(false)
const errorMsg = ref('')
const expandedIndex = ref(0)

// Refs for card title inputs (used for focus management)
const cardTitleRefs = ref<(HTMLInputElement | null)[]>([])

// Initialize cards when total changes
function regenerateCards() {
  const count = Math.max(1, totalCards.value)
  const existing = cards.value
  const newCards: Partial<DeckSystemCard>[] = []
  for (let i = 0; i < count; i++) {
    newCards.push({
      card_id: i + 1,
      name: existing[i]?.name ?? '',
      keywords: existing[i]?.keywords ?? null,
      meaning: existing[i]?.meaning ?? null,
      advice: existing[i]?.advice ?? null,
      reversed_keywords: existing[i]?.reversed_keywords ?? null,
      reversed_meaning: existing[i]?.reversed_meaning ?? null,
      reversed_advice: existing[i]?.reversed_advice ?? null,
    })
  }
  cards.value = newCards
}

// Seed with 78 empty cards
regenerateCards()

watch(totalCards, () => {
  regenerateCards()
})

const allCardTitlesValid = computed(() => cards.value.every((c) => c.name?.trim()))

function toggleCard(index: number) {
  expandedIndex.value = expandedIndex.value === index ? -1 : index
}

function markDone(index: number) {
  expandedIndex.value = -1
  // Move to next card if available
  const nextIndex = index + 1
  if (nextIndex < cards.value.length) {
    nextTick(() => {
      expandedIndex.value = nextIndex
      nextTick(() => {
        cardTitleRefs.value[nextIndex]?.focus()
      })
    })
  }
}

function expandAll() {
  // Use -2 as sentinel for "all expanded"
  expandedIndex.value = -2
}

function collapseAll() {
  expandedIndex.value = -1
}

function isExpanded(index: number): boolean {
  return expandedIndex.value === -2 || expandedIndex.value === index
}

async function submitSystem() {
  errorMsg.value = ''

  if (!name.value.trim() || !shortName.value.trim()) {
    errorMsg.value = 'Name and Short Name are required.'
    return
  }

  if (totalCards.value < 1) {
    errorMsg.value = 'Total cards must be at least 1.'
    return
  }

  // Validate all cards have names
  const missingNames = cards.value.filter((c) => !c.name?.trim())
  if (missingNames.length > 0) {
    errorMsg.value = `All ${totalCards.value} cards require a name. ${missingNames.length} card(s) are missing names.`
    // Expand the first card missing a name
    const firstMissing = cards.value.findIndex((c) => !c.name?.trim())
    if (firstMissing >= 0) {
      expandedIndex.value = firstMissing
      nextTick(() => {
        cardTitleRefs.value[firstMissing]?.focus()
      })
    }
    return
  }

  submitting.value = true
  try {
    const res = await fetch('/api' + endpoints.deckSystems.list, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: name.value.trim(),
        short_name: shortName.value.trim(),
        total_cards: totalCards.value,
        cards: cards.value,
      }),
    })

    if (res.ok) {
      submitted.value = true
      toasts.success('Deck system submitted successfully!')
    } else {
      const data = (await res.json().catch(() => ({}))) as { error?: string }
      errorMsg.value = data.error || 'Failed to submit deck system. Please try again.'
    }
  } catch {
    errorMsg.value = 'Network error. Please check your connection and try again.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="section">
    <div class="container" style="max-width: 900px">
      <h1 class="title is-3">Submit a Deck System</h1>
      <p class="subtitle is-5">Propose a new card naming system for decks on TarotGen.io.</p>

      <template v-if="!isLoggedIn">
        <div class="notification is-warning">
          <p>
            You must be
            <router-link :to="{ name: 'login', query: { redirect: '/submit-deck-system' } }"
              >logged in</router-link
            >
            to submit a deck system.
          </p>
        </div>
      </template>

      <template v-else-if="submitted">
        <div class="notification is-success">
          <p>
            <strong>Thank you!</strong> Your deck system submission has been received and will be
            reviewed by an admin.
          </p>
        </div>
        <router-link :to="{ name: 'home' }" class="button is-link">
          <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['house']" /></span>
          <span>Back to Home</span>
        </router-link>
      </template>

      <template v-else>
        <div class="notification is-info is-light">
          <p>
            A deck system defines the card names and meanings used by one or more decks. For
            example, "Rider-Waite-Smith" and "Thoth" are two different deck systems. Each card must
            have at least a name.
          </p>
        </div>

        <div v-if="errorMsg" class="notification is-danger is-light">
          {{ errorMsg }}
        </div>

        <div class="columns">
          <div class="column is-5">
            <div class="field">
              <label class="label">System Name <span class="has-text-danger">*</span></label>
              <div class="control">
                <input v-model="name" class="input" type="text" placeholder="e.g. Marseille" />
              </div>
            </div>
          </div>
          <div class="column is-4">
            <div class="field">
              <label class="label">Short Name <span class="has-text-danger">*</span></label>
              <div class="control">
                <input v-model="shortName" class="input" type="text" placeholder="e.g. TdM" />
              </div>
              <p class="help">A brief abbreviation shown in deck lists.</p>
            </div>
          </div>
          <div class="column is-3">
            <div class="field">
              <label class="label">Total Cards <span class="has-text-danger">*</span></label>
              <div class="control">
                <input v-model.number="totalCards" class="input" type="number" min="1" max="200" />
              </div>
            </div>
          </div>
        </div>

        <div class="is-flex is-align-items-center is-justify-content-space-between mt-4 mb-3">
          <h4 class="title is-5 mb-0">Card Definitions</h4>
          <div class="buttons are-small mb-0">
            <button type="button" class="button is-small is-ghost" @click="expandAll">
              <span class="icon"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-down']"
              /></span>
              <span>Expand All</span>
            </button>
            <button type="button" class="button is-small is-ghost" @click="collapseAll">
              <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['angles-up']" /></span>
              <span>Collapse All</span>
            </button>
          </div>
        </div>
        <p class="mb-4 has-text-grey">
          Enter a name for each card. All other fields are optional but helpful.
        </p>

        <div class="deck-system-cards">
          <div
            v-for="(card, index) in cards"
            :key="card.card_id"
            class="deck-card-entry"
            :class="{ 'is-expanded': isExpanded(index), 'is-missing-name': !card.name?.trim() }"
          >
            <div class="deck-card-header" @click="toggleCard(index)">
              <span class="deck-card-number">{{ card.card_id }}</span>
              <span class="deck-card-title">{{ card.name?.trim() || 'Untitled Card' }}</span>
              <span v-if="!card.name?.trim()" class="tag is-danger is-light ml-2"
                >Name required</span
              >
              <span class="icon deck-card-chevron">
                <FontAwesomeIcon
                  :icon="
                    isExpanded(index)
                      ? byPrefixAndName.fas['chevron-up']
                      : byPrefixAndName.fas['chevron-down']
                  "
                />
              </span>
            </div>

            <div v-show="isExpanded(index)" class="deck-card-body">
              <div class="field">
                <label class="label is-small"
                  >Card Title <span class="has-text-danger">*</span></label
                >
                <div class="control">
                  <input
                    :ref="
                      (el) => {
                        cardTitleRefs[index] = el as HTMLInputElement | null
                      }
                    "
                    v-model="card.name"
                    class="input"
                    type="text"
                    placeholder="e.g. The Fool"
                  />
                </div>
              </div>

              <div class="columns is-multiline">
                <div class="column is-6">
                  <div class="field">
                    <label class="label is-small">Keywords</label>
                    <div class="control">
                      <input
                        v-model="card.keywords"
                        class="input"
                        type="text"
                        placeholder="Keywords..."
                      />
                    </div>
                  </div>
                </div>
                <div class="column is-6">
                  <div class="field">
                    <label class="label is-small">Reversed Keywords</label>
                    <div class="control">
                      <input
                        v-model="card.reversed_keywords"
                        class="input"
                        type="text"
                        placeholder="Reversed keywords..."
                      />
                    </div>
                  </div>
                </div>
                <div class="column is-6">
                  <div class="field">
                    <label class="label is-small">Meaning</label>
                    <div class="control">
                      <textarea
                        v-model="card.meaning"
                        class="textarea is-small"
                        placeholder="Meaning..."
                        rows="3"
                      ></textarea>
                    </div>
                  </div>
                </div>
                <div class="column is-6">
                  <div class="field">
                    <label class="label is-small">Reversed Meaning</label>
                    <div class="control">
                      <textarea
                        v-model="card.reversed_meaning"
                        class="textarea is-small"
                        placeholder="Reversed meaning..."
                        rows="3"
                      ></textarea>
                    </div>
                  </div>
                </div>
                <div class="column is-6">
                  <div class="field">
                    <label class="label is-small">Advice</label>
                    <div class="control">
                      <textarea
                        v-model="card.advice"
                        class="textarea is-small"
                        placeholder="Advice..."
                        rows="3"
                      ></textarea>
                    </div>
                  </div>
                </div>
                <div class="column is-6">
                  <div class="field">
                    <label class="label is-small">Reversed Advice</label>
                    <div class="control">
                      <textarea
                        v-model="card.reversed_advice"
                        class="textarea is-small"
                        placeholder="Reversed advice..."
                        rows="3"
                      ></textarea>
                    </div>
                  </div>
                </div>
              </div>

              <div class="has-text-right">
                <button type="button" class="button is-small is-success" @click="markDone(index)">
                  <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['check']" /></span>
                  <span>Done</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="field mt-5">
          <button
            class="button is-primary"
            :class="{ 'is-loading': submitting }"
            :disabled="submitting || !allCardTitlesValid"
            @click="submitSystem"
          >
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['paper-plane']" /></span>
            <span>Submit Deck System</span>
          </button>
          <p v-if="!allCardTitlesValid" class="help is-danger mt-2">
            All cards must have a title before submitting.
          </p>
        </div>
      </template>
    </div>
  </section>
</template>

<style scoped>
.deck-system-cards {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.deck-card-entry {
  border: 1px solid var(--myst-border, rgba(255, 255, 255, 0.12));
  border-radius: 8px;
  overflow: hidden;
  transition: border-color 0.15s ease;
}

.deck-card-entry.is-expanded {
  border-color: var(--myst-border-strong, rgba(255, 255, 255, 0.25));
}

.deck-card-entry.is-missing-name:not(.is-expanded) {
  border-color: hsl(348, 86%, 61%);
}

.deck-card-header {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.65rem 0.9rem;
  cursor: pointer;
  background: var(--myst-surface-3, rgba(255, 255, 255, 0.04));
  user-select: none;
  transition: background-color 0.12s ease;
}

.deck-card-header:hover {
  background: var(--myst-surface-3, rgba(255, 255, 255, 0.07));
}

.deck-card-number {
  font-size: 0.8rem;
  font-weight: 700;
  opacity: 0.5;
  min-width: 2ch;
  text-align: right;
}

.deck-card-title {
  font-weight: 600;
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.deck-card-chevron {
  margin-left: auto;
  opacity: 0.5;
  transition: transform 0.15s ease;
}

.deck-card-body {
  padding: 1rem;
  border-top: 1px solid var(--myst-border, rgba(255, 255, 255, 0.08));
}
</style>
