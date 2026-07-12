<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { useToasts } from '@/composables/useToasts'

// The self-hosted custom Dalamud repository users paste into XIVLauncher.
// (Served by the backend at /plugin/, not by this SPA — see the router note.)
const REPO_URL = 'https://tarotgen.io/plugin/repo.json'

const { success: toastSuccess, error: toastError } = useToasts()

// Things the plugin can do in-game, mirroring the plugin's in-scope feature set.
const features = [
  {
    icon: byPrefixAndName.fas['cards'],
    title: 'Draw a reading',
    body: 'Pick a public spread or do a free draw (with reversals) without leaving the game.',
  },
  {
    icon: byPrefixAndName.fas['crystal-ball'],
    title: 'View by share code',
    body: 'Open any reading — including password-protected ones — straight from its code.',
  },
  {
    icon: byPrefixAndName.fas['link'],
    title: 'Link your account',
    body: 'Optional: lock readings, sort pickers by your favorites, and track your history.',
  },
  {
    icon: byPrefixAndName.fas['paper-plane'],
    title: 'Send to another player',
    body: 'Push a reading to another plugin user with a passive, chatless in-game prompt.',
  },
  {
    icon: byPrefixAndName.fas['triangle-exclamation'],
    title: 'Report a card scan',
    body: 'Flag a card image with artefacts from the lightbox so it can be re-scanned.',
  },
] as const

async function copyRepoUrl(): Promise<void> {
  try {
    await navigator.clipboard.writeText(REPO_URL)
    toastSuccess('Repository URL copied to your clipboard.')
  } catch {
    toastError('Could not copy — select the URL and copy it manually.')
  }
}
</script>

<template>
  <section class="section">
    <div class="container plugin-page">
      <!-- Hero -->
      <div class="has-text-centered mb-5">
        <span class="icon is-large has-text-link">
          <FontAwesomeIcon :icon="byPrefixAndName.fad['gamepad']" size="3x" />
        </span>
        <h1 class="title is-3 is-size-4-mobile mt-3">TarotGen FFXIV Plugin</h1>
        <p class="subtitle is-5 is-size-6-mobile">
          Draw and view TarotGen.io tarot readings without leaving Final Fantasy XIV.
        </p>
      </div>

      <!-- Requirements -->
      <div class="notification is-info is-light">
        <span class="icon-text">
          <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['circle-info']" /></span>
          <span>
            The plugin runs through <strong>Dalamud</strong>, provided by
            <a href="https://goatcorp.github.io/" target="_blank" rel="noopener noreferrer"
              >XIVLauncher</a
            >. You'll need Final Fantasy XIV launched via XIVLauncher on Windows. It's free and open
            source.
          </span>
        </span>
      </div>

      <!-- Install -->
      <h2 class="title is-4 mt-6">
        <span class="icon-text">
          <span class="icon has-text-link"
            ><FontAwesomeIcon :icon="byPrefixAndName.fad['gear']"
          /></span>
          <span>Install it</span>
        </span>
      </h2>
      <p class="mb-4">
        The plugin lives in a self-hosted <strong>custom Dalamud repository</strong>. Add it once,
        then install from the plugin list — all in-game:
      </p>

      <ol class="steps">
        <li class="box step">
          <p class="step-title">Open the custom repositories list</p>
          <p>
            Type <code>/xlsettings</code> in chat → <strong>Experimental</strong> tab →
            <strong>Custom Plugin Repositories</strong>.
          </p>
        </li>
        <li class="box step">
          <p class="step-title">Add the TarotGen repository</p>
          <p class="mb-2">Paste this URL into a new row, then click <strong>Save</strong>:</p>
          <div class="repo-url">
            <code class="repo-url-code">{{ REPO_URL }}</code>
            <button
              type="button"
              class="button is-small is-link is-light"
              aria-label="Copy repository URL"
              @click="copyRepoUrl"
            >
              <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['copy']" /></span>
              <span>Copy</span>
            </button>
          </div>
        </li>
        <li class="box step">
          <p class="step-title">Install &amp; open</p>
          <p>
            Type <code>/xlplugins</code> → find <strong>TarotGen</strong> →
            <strong>Install</strong>. Then run <code>/tarot</code> to open it.
          </p>
        </li>
      </ol>

      <!-- What it does -->
      <h2 class="title is-4 mt-6">
        <span class="icon-text">
          <span class="icon has-text-link"
            ><FontAwesomeIcon :icon="byPrefixAndName.fad['sparkles']"
          /></span>
          <span>What you can do</span>
        </span>
      </h2>
      <div class="columns is-multiline mt-1">
        <div v-for="f in features" :key="f.title" class="column is-half">
          <div class="box feature">
            <span class="icon is-medium has-text-primary feature-icon">
              <FontAwesomeIcon :icon="f.icon" size="lg" />
            </span>
            <div>
              <p class="feature-title">{{ f.title }}</p>
              <p class="feature-body">{{ f.body }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Account linking -->
      <div class="notification mt-4">
        <span class="icon-text is-align-items-flex-start">
          <span class="icon has-text-link mt-1"
            ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock']"
          /></span>
          <span>
            <strong>Linking an account is optional.</strong> Guests can draw, view, and share
            readings. Link a TarotGen account from the plugin's settings to lock readings, sort by
            favorites, and track them in
            <router-link :to="{ name: 'account-readings' }">My Readings</router-link>. Linking opens
            a browser consent page and can be revoked any time from
            <router-link :to="{ name: 'account-settings' }">Account Settings</router-link>.
          </span>
        </span>
      </div>

      <p class="has-text-centered has-text-grey mt-6">
        Trouble getting set up?
        <router-link :to="{ name: 'contact' }">Contact us</router-link> and we'll help.
      </p>
    </div>
  </section>
</template>

<style scoped>
.plugin-page {
  max-width: 52rem;
}

/* Numbered install steps. */
.steps {
  list-style: none;
  margin: 0;
  counter-reset: step;
}

.step {
  position: relative;
  padding-left: 3.75rem;
  margin-bottom: 1rem;
}

.step::before {
  counter-increment: step;
  content: counter(step);
  position: absolute;
  left: 1rem;
  top: 1.15rem;
  width: 1.9rem;
  height: 1.9rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background-color: var(--myst-surface-3);
  border: 1px solid var(--myst-border-strong);
  color: var(--myst-gold);
  font-weight: 700;
}

.step-title {
  font-weight: 600;
  margin-bottom: 0.35rem;
}

.repo-url {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.repo-url-code {
  flex: 1 1 auto;
  min-width: 0;
  overflow-x: auto;
  white-space: nowrap;
  padding: 0.4rem 0.6rem;
}

/* Feature cards: icon beside a short blurb. */
.feature {
  display: flex;
  align-items: flex-start;
  gap: 0.9rem;
  height: 100%;
}

.feature-icon {
  flex: none;
}

.feature-title {
  font-weight: 600;
  margin-bottom: 0.15rem;
}

.feature-body {
  font-size: 0.9rem;
  opacity: 0.8;
}
</style>
