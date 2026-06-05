<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import type { ReadingCard } from '@/types'

const props = defineProps<{
    cards: ReadingCard[]
    cardBackUrl: string
    initialIndex: number
}>()

const emit = defineEmits<{ close: [] }>()

const index = ref(props.initialIndex)
const flipped = ref(false)

let touchStartX = 0

const imageSrc = computed(() => {
    if (index.value === -1) return props.cardBackUrl
    const card = props.cards[index.value]
    return card ? card.imgUrl : ''
})

const imageTitle = computed(() => {
    if (index.value === -1) return 'Card Back'
    const card = props.cards[index.value]
    return card ? card.card_name : ''
})

const isReversed = computed(() => {
    if (index.value === -1) return false
    const card = props.cards[index.value]
    return card ? card.reversed : false
})

const showReversed = computed(() => isReversed.value && !flipped.value)

function prev() {
    if (index.value > -1) {
        index.value--
        flipped.value = false
    }
}

function next() {
    if (index.value < props.cards.length - 1) {
        index.value++
        flipped.value = false
    }
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close')
    else if (e.key === 'ArrowLeft') prev()
    else if (e.key === 'ArrowRight') next()
}

function onTouchStart(e: TouchEvent) {
    touchStartX = e.changedTouches[0].screenX
}

function onTouchEnd(e: TouchEvent) {
    const diff = touchStartX - e.changedTouches[0].screenX
    if (Math.abs(diff) > 50) {
        diff > 0 ? next() : prev()
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKeydown)
    document.body.style.overflow = 'hidden'
})

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown)
    document.body.style.overflow = ''
})
</script>

<template>
    <div
        class="lightbox-overlay"
        @click.self="emit('close')"
        @touchstart="onTouchStart"
        @touchend="onTouchEnd"
    >
        <button class="lightbox-close" aria-label="Close lightbox" @click="emit('close')">&times;</button>

        <button
            class="lightbox-nav lightbox-prev"
            v-if="index > -1"
            @click="prev"
            aria-label="Previous image"
        >&#10094;</button>

        <div class="lightbox-content">
            <img :src="imageSrc" :alt="imageTitle" :class="{ reversed: showReversed }" />
            <p class="lightbox-caption" v-if="imageTitle">
                {{ imageTitle }}
                <span v-if="isReversed" class="lightbox-reversed-tag">(Reversed)</span>
            </p>
            <button
                class="button is-small is-rounded lightbox-flip-btn"
                v-if="isReversed"
                @click="flipped = !flipped"
            >
                <span class="icon is-small"><i class="fa-solid fa-rotate"></i></span>
                <span>{{ flipped ? 'View Reversed' : 'View Upright' }}</span>
            </button>
            <p class="lightbox-counter" v-if="index >= 0 && cards.length > 1">
                Image {{ index + 1 }} of {{ cards.length }}
            </p>
        </div>

        <button
            class="lightbox-nav lightbox-next"
            v-if="index < cards.length - 1"
            @click="next"
            aria-label="Next image"
        >&#10095;</button>
    </div>
</template>
