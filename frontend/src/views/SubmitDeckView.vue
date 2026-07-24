<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import PageHeader from '@/components/PageHeader.vue'
import BaseSelect from '@/components/BaseSelect.vue'
import { ref, computed, onMounted } from 'vue'
import { useUser } from '@/composables/useUser'
import { useToasts } from '@/composables/useToasts'
import { endpoints } from '@/api/endpoints'
import type { DeckSystem } from '@/types'

const { isLoggedIn } = useUser()
const toasts = useToasts()

const name = ref('')
const artist = ref('')
const purchaseUrl = ref('')
const deckSystemId = ref<number | null>(null)
const additionalCards = ref(0)
const deckSystems = ref<DeckSystem[]>([])
const submitting = ref(false)
const submitted = ref(false)
const errorMsg = ref('')

const deckSystemOptions = computed(() =>
  deckSystems.value.map((sys) => ({
    value: sys.deck_system_id,
    label: `${sys.name} — ${sys.total_cards} cards`,
  })),
)

async function fetchDeckSystems() {
  try {
    const res = await fetch('/api' + endpoints.deckSystems.list)
    if (res.ok) {
      deckSystems.value = await res.json()
      if (deckSystems.value.length > 0 && deckSystemId.value === null) {
        const rws = deckSystems.value.find((s) => s.short_name === 'RWS')
        deckSystemId.value = rws ? rws.deck_system_id : deckSystems.value[0].deck_system_id
      }
    }
  } catch {
    // Silently fail
  }
}

onMounted(fetchDeckSystems)

async function submitDeck() {
  errorMsg.value = ''

  if (!name.value.trim() || !artist.value.trim()) {
    errorMsg.value = 'Name and Artist are required.'
    return
  }

  if (!deckSystemId.value) {
    errorMsg.value = 'Please select a deck system.'
    return
  }

  submitting.value = true
  try {
    const res = await fetch('/api' + endpoints.decks.list, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: name.value.trim(),
        artist: artist.value.trim(),
        purchase_url: purchaseUrl.value.trim(),
        deck_system_id: deckSystemId.value,
        additional_cards: additionalCards.value,
      }),
    })

    if (res.ok) {
      submitted.value = true
      toasts.success('Deck submitted! It will be reviewed by an admin.')
    } else {
      const data = (await res.json().catch(() => ({}))) as { error?: string }
      errorMsg.value = data.error || 'Failed to submit deck. Please try again.'
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
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-8-desktop is-10-tablet">
          <PageHeader
            title="Submit a Deck"
            subtitle="Suggest a tarot deck to be added to TarotGen.io."
          />

          <template v-if="!isLoggedIn">
            <div class="myst-callout">
              <p>
                You must be
                <router-link :to="{ name: 'login', query: { redirect: '/submit-deck' } }"
                  >logged in</router-link
                >
                to submit a deck.
              </p>
            </div>
          </template>

          <template v-else-if="submitted">
            <div class="notification is-success">
              <p>
                <strong>Thank you!</strong> Your deck submission has been received and will be
                reviewed by an admin.
              </p>
            </div>
            <router-link :to="{ name: 'home' }" class="button is-link">
              <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['house']" /></span>
              <span>Back to Home</span>
            </router-link>
          </template>

          <template v-else>
            <div class="settings-panel">
              <div class="myst-callout">
                <p>
                  Submitted decks will be reviewed by an admin before they appear on the site.
                  Please include the deck name, artist, and the card system it uses.
                </p>
              </div>

              <div v-if="errorMsg" class="notification is-danger is-light">
                {{ errorMsg }}
              </div>

              <div class="field">
                <label class="label">Deck Name <span class="has-text-danger">*</span></label>
                <div class="control">
                  <input
                    v-model="name"
                    class="input"
                    type="text"
                    placeholder="e.g. Rider-Waite-Smith"
                  />
                </div>
              </div>

              <div class="field">
                <label class="label">Artist <span class="has-text-danger">*</span></label>
                <div class="control">
                  <input
                    v-model="artist"
                    class="input"
                    type="text"
                    placeholder="e.g. Pamela Colman Smith"
                  />
                </div>
              </div>

              <div class="field">
                <label class="label">Deck System <span class="has-text-danger">*</span></label>
                <BaseSelect
                  v-model="deckSystemId"
                  :options="deckSystemOptions"
                  aria-label="Deck System"
                />
                <p class="help">
                  The card system this deck uses. Don't see yours?
                  <router-link :to="{ name: 'submit-deck-system' }"
                    >Submit a new deck system</router-link
                  >.
                </p>
              </div>

              <div class="field">
                <label class="label"
                  >Additional Cards
                  <span class="has-text-grey is-size-7 has-text-weight-normal"
                    >(optional)</span
                  ></label
                >
                <div class="control">
                  <input
                    v-model.number="additionalCards"
                    class="input"
                    type="number"
                    min="0"
                    placeholder="0"
                  />
                </div>
                <p class="help">
                  Extra cards beyond the deck system's standard count (e.g. bonus cards).
                </p>
              </div>

              <div class="field">
                <label class="label"
                  >Purchase URL
                  <span class="has-text-grey is-size-7 has-text-weight-normal"
                    >(optional)</span
                  ></label
                >
                <div class="control">
                  <input v-model="purchaseUrl" class="input" type="url" placeholder="https://..." />
                </div>
                <p class="help">A link where this deck can be purchased, if known.</p>
              </div>

              <div class="field">
                <button
                  class="button is-primary"
                  :class="{ 'is-loading': submitting }"
                  :disabled="submitting"
                  @click="submitDeck"
                >
                  <span class="icon"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['paper-plane']"
                  /></span>
                  <span>Submit Deck</span>
                </button>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </section>
</template>
