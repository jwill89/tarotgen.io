<script setup lang="ts">
import { ref, onMounted } from 'vue'

const STORAGE_KEY = 'cookie_notice_dismissed'
const visible = ref(false)

onMounted(() => {
    if (!localStorage.getItem(STORAGE_KEY)) {
        visible.value = true
    }
})

function dismiss() {
    localStorage.setItem(STORAGE_KEY, '1')
    visible.value = false
}
</script>

<template>
    <Transition name="cookie-banner">
        <div v-if="visible" class="cookie-banner">
            <div class="cookie-banner__content">
                <span class="cookie-banner__text">
                    <i class="fa-solid fa-cookie-bite"></i>
                    This site uses essential cookies to keep you logged in. No tracking or advertising cookies are used.
                </span>
                <button class="button is-small is-primary" @click="dismiss">Got it</button>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.cookie-banner {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    background: var(--myst-surface-2);
    border-top: 1px solid var(--myst-border-strong);
    padding: 0.75rem 1.25rem;
    box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.4);
}

.cookie-banner__content {
    max-width: 960px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    justify-content: center;
}

.cookie-banner__text {
    color: var(--myst-text);
    font-size: 0.875rem;
}

.cookie-banner__text i {
    color: var(--myst-gold);
    margin-right: 0.4em;
}
</style>

<style>
.cookie-banner-enter-active,
.cookie-banner-leave-active {
    transition: transform 0.3s ease, opacity 0.3s ease;
}

.cookie-banner-enter-from,
.cookie-banner-leave-to {
    transform: translateY(100%);
    opacity: 0;
}
</style>



