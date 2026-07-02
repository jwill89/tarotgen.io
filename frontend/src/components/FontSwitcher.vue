<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
/**
 * Floating heading-font switcher.
 *
 * A small, dismissible control for auditioning the display fonts used across
 * the app's headings. Pick a font and it applies live (and is remembered);
 * collapse it out of the way when you're done. Intended as an evaluation tool
 * while we settle on the final heading font.
 */
import { ref } from 'vue'
import { useHeadingFont } from '@/composables/useHeadingFont'

const { fonts, currentFontId, setHeadingFont } = useHeadingFont()
const open = ref(false)
</script>

<template>
  <div class="font-switcher" :class="{ 'is-open': open }">
    <button
      type="button"
      class="font-switcher-toggle"
      :aria-expanded="open ? 'true' : 'false'"
      aria-label="Switch heading font"
      title="Switch heading font"
      @click="open = !open"
    >
      <FontAwesomeIcon :icon="byPrefixAndName.fas['font']" aria-hidden="true" />
    </button>

    <div v-if="open" class="font-switcher-panel" role="group" aria-label="Heading font">
      <p class="font-switcher-heading">Heading font</p>
      <button
        v-for="font in fonts"
        :key="font.id"
        type="button"
        class="font-switcher-option"
        :class="{ 'is-active': font.id === currentFontId }"
        :style="{ fontFamily: font.stack }"
        @click="setHeadingFont(font.id)"
      >
        {{ font.label }}
        <FontAwesomeIcon
          v-if="font.id === currentFontId"
          :icon="byPrefixAndName.fas['check']"
          class="font-switcher-check"
          aria-hidden="true"
        />
      </button>
    </div>
  </div>
</template>

<style scoped>
.font-switcher {
  position: fixed;
  right: 1rem;
  bottom: 1rem;
  z-index: 60;
  display: flex;
  flex-direction: column-reverse;
  align-items: flex-end;
  gap: 0.6rem;
}

.font-switcher-toggle {
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 50%;
  border: 1px solid var(--myst-border-strong);
  background-color: var(--myst-surface);
  color: var(--myst-gold);
  cursor: pointer;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.4);
  transition:
    transform 0.12s ease,
    color 0.15s ease,
    border-color 0.15s ease;
}

.font-switcher-toggle:hover {
  transform: translateY(-1px);
  color: var(--myst-gold-bright);
  border-color: var(--myst-gold);
}

.font-switcher-panel {
  width: 14rem;
  padding: 0.75rem;
  border-radius: 0.7rem;
  border: 1px solid var(--myst-border-strong);
  background-color: var(--myst-surface);
  box-shadow: 0 14px 40px rgba(0, 0, 0, 0.5);
}

.font-switcher-heading {
  margin: 0 0 0.5rem;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--myst-text-muted);
}

.font-switcher-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0.5rem 0.6rem;
  margin-bottom: 0.35rem;
  border: 1px solid var(--myst-border);
  border-radius: 0.5rem;
  background-color: var(--myst-surface-2);
  color: var(--myst-text-strong);
  font-size: 1.05rem;
  /* Preview each option exactly as headings render them (see tokens.css). */
  font-variant-caps: small-caps;
  text-align: left;
  cursor: pointer;
  transition:
    background-color 0.12s ease,
    border-color 0.12s ease;
}

.font-switcher-option:last-child {
  margin-bottom: 0;
}

.font-switcher-option:hover {
  background-color: var(--myst-surface-3);
  border-color: var(--myst-border-strong);
}

.font-switcher-option.is-active {
  border-color: var(--myst-gold);
}

.font-switcher-check {
  font-size: 0.8rem;
  color: var(--myst-gold);
}
</style>
