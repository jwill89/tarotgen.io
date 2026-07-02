<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useRecentReadings, type RecentReading } from '@/composables/useRecentReadings'
import { useConfirm } from '@/composables/useConfirm'
import { useUser } from '@/composables/useUser'
import { formatDateTime } from '@/utils/datetime'
import { byPrefixAndName } from '@/fontawesome'

const router = useRouter()
const { recent, remove, clear } = useRecentReadings()
const { confirm } = useConfirm()
const { isLoggedIn } = useUser()

const readingCode = ref('')

async function removeOne(r: RecentReading) {
  const ok = await confirm({
    title: 'Remove reading',
    message: `Remove "${r.summary}" from your history? You can still open it later with its code.`,
    confirmLabel: 'Remove',
    danger: true,
  })
  if (ok) remove(r.id)
}

async function clearAll() {
  const ok = await confirm({
    title: 'Clear recent readings',
    message:
      'Remove all readings from your history on this device? The readings themselves are not deleted — you can still open them with their code.',
    confirmLabel: 'Clear all',
    danger: true,
  })
  if (ok) clear()
}

function viewReading() {
  const rid = readingCode.value.trim()
  if (rid) {
    readingCode.value = ''
    void router.push({ name: 'reading', params: { id: rid } })
  }
}
</script>

<template>
  <section class="section">
    <div class="container">
      <h1 class="title is-3 is-size-4-mobile">TarotGen.io Tarot Generator</h1>
      <p class="subtitle is-5 is-size-6-mobile">
        Start a new reading or look one up with the code you were provided.
      </p>

      <div class="columns is-multiline home-actions">
        <div class="column is-3-desktop is-6-tablet is-12-mobile">
          <router-link :to="{ name: 'new-reading' }" class="box home-action has-text-centered">
            <span class="icon is-large has-text-primary">
              <FontAwesomeLayers class="fa-2x">
                <FontAwesomeIcon :icon="byPrefixAndName.fad['cards-blank']" />
                <FontAwesomeIcon
                  :icon="byPrefixAndName.fas['sword']"
                  transform="shrink-9 rotate--63 left-2 up-1.1"
                  style="color: #3b1363"
                />
              </FontAwesomeLayers>
            </span>
            <p class="title is-5 mt-3">New Draw</p>
            <p class="subtitle is-6">
              Draw a fresh spread of cards. Does not include detailed interpretation.
            </p>
          </router-link>
        </div>

        <div class="column is-3-desktop is-6-tablet is-12-mobile">
          <router-link :to="{ name: 'custom-reading' }" class="box home-action has-text-centered">
            <span class="icon is-large has-text-success">
              <FontAwesomeIcon :icon="byPrefixAndName.fad['hand-holding-magic']" size="2x" />
            </span>
            <p class="title is-5 mt-3">Recreate Draw</p>
            <p class="subtitle is-6">
              Recreate a real spread by placing specific cards in the positions you want.
            </p>
          </router-link>
        </div>

        <div class="column is-3-desktop is-6-tablet is-12-mobile">
          <router-link :to="{ name: 'submit-spread' }" class="box home-action has-text-centered">
            <span class="icon is-large has-text-link">
              <FontAwesomeIcon :icon="byPrefixAndName.fad['grid-2-plus']" size="2x" />
            </span>
            <p class="title is-5 mt-3">Create a Spread</p>
            <p class="subtitle is-6">Design a custom spread to use or share with others.</p>
          </router-link>
        </div>

        <div v-if="isLoggedIn" class="column is-3-desktop is-6-tablet is-12-mobile">
          <router-link :to="{ name: 'submit-deck' }" class="box home-action has-text-centered">
            <span class="icon is-large has-text-warning">
              <FontAwesomeIcon :icon="byPrefixAndName.fad['cards-blank']" size="2x" />
            </span>
            <p class="title is-5 mt-3">Submit a Deck</p>
            <p class="subtitle is-6">Suggest a tarot deck to be added to the site.</p>
          </router-link>
        </div>

        <div class="column is-3-desktop is-6-tablet is-12-mobile">
          <div class="box home-action home-action--static has-text-centered">
            <span class="icon is-large has-text-info">
              <FontAwesomeIcon :icon="byPrefixAndName.fad['crystal-ball']" size="2x" />
            </span>
            <p class="title is-5 mt-3">View a Reading</p>
            <div class="field has-addons mt-2">
              <p class="control is-expanded">
                <input
                  v-model="readingCode"
                  class="input"
                  type="text"
                  placeholder="Reading Code"
                  aria-label="Reading Code"
                  @keyup.enter="viewReading"
                />
              </p>
              <p class="control">
                <button class="button is-info" @click="viewReading">
                  <span class="icon"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-right']"
                  /></span>
                </button>
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent readings (stored locally on this device) -->
      <div v-if="recent.length > 0" class="recent-readings">
        <div class="is-flex is-align-items-center is-justify-content-space-between mb-1">
          <h2 class="title is-5 mb-0">
            <span class="icon-text">
              <span class="icon has-text-grey-light"
                ><FontAwesomeIcon :icon="byPrefixAndName.fad['clock-rotate-left']"
              /></span>
              <span>Your Recent Readings</span>
            </span>
          </h2>
          <button class="button is-small is-ghost" @click="clearAll">Clear all</button>
        </div>
        <p class="help has-text-grey mb-3">
          Saved only on this device. Anyone with a reading's code can view it.
        </p>
        <div class="recent-list">
          <div v-for="r in recent" :key="r.id" class="recent-item">
            <router-link class="recent-link" :to="{ name: 'reading', params: { id: r.id } }">
              <span class="icon has-text-primary"
                ><FontAwesomeIcon :icon="byPrefixAndName.fad['scroll-old']"
              /></span>
              <span class="recent-text">
                <span class="recent-summary">{{ r.summary }}</span>
                <span class="recent-meta">{{ r.deckName }}</span>
                <span class="recent-meta recent-date">{{ formatDateTime(r.at) }}</span>
              </span>
            </router-link>
            <button
              class="recent-remove"
              aria-label="Remove from history"
              @click.stop="removeOne(r)"
            >
              <FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" />
            </button>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* The "View a Reading" tile holds a form, not a link — so no hover lift, and a
   slightly different surface to set it apart from the navigational tiles. */
.home-action--static {
  background-color: var(--myst-surface-3);
  cursor: default;
}

.home-action--static:hover {
  transform: none;
  box-shadow: none;
  border-color: var(--myst-border);
}

.recent-readings {
  margin-top: 2.5rem;
}

.recent-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 0.6rem;
}

.recent-item {
  display: flex;
  align-items: stretch;
  background-color: var(--myst-panel);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 10px;
  overflow: hidden;
  transition:
    border-color 0.15s ease,
    transform 0.15s ease;
}

.recent-item:hover {
  border-color: rgba(255, 255, 255, 0.28);
  transform: translateY(-2px);
}

.recent-link {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.7rem 0.85rem;
  flex: 1 1 auto;
  color: inherit;
  min-width: 0;
}

.recent-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.recent-summary {
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.recent-meta {
  font-size: 0.78rem;
  opacity: 0.7;
}

.recent-remove {
  flex: none;
  background: none;
  border: none;
  border-left: 1px solid rgba(255, 255, 255, 0.12);
  color: inherit;
  opacity: 0.5;
  cursor: pointer;
  padding: 0 0.85rem;
  transition:
    opacity 0.15s ease,
    background-color 0.15s ease;
}

.recent-remove:hover {
  opacity: 1;
  background-color: rgba(255, 255, 255, 0.08);
}
</style>
