/**
 * @file main.js
 * @description Main JS file for Tarot Reading Application (Vue 3)
 * @author MathDad <https://www.mathdad.me>
 * @license MIT
 * @version 2.0.0
 */

document.addEventListener('DOMContentLoaded', function () {
    const { createApp, ref, reactive, computed, onMounted, watch } = Vue;

    createApp({
        setup() {
            // ── Reactive State ──────────────────────────────────────
            const currentPage = ref('home');
            const burgerOpen = ref(false);
            const searchReadingId = ref('');
            const alertModalActive = ref(false);
            const decks = ref([]);
            const deckLookup = ref({});
            const reading = ref(null);
            const readingInfo = ref(null);

            const form = reactive({
                deckId: null,
                numberOfCards: 1,
                reversalChance: 0,
                numberOfShuffles: 1,
                useAdditionalCards: false
            });

            const lightbox = reactive({
                active: false,
                index: -1   // -1 = card back
            });

            // ── Computed Properties ─────────────────────────────────
            const currentYear = computed(() => new Date().getFullYear());

            const selectedDeck = computed(() => {
                return deckLookup.value[form.deckId] || null;
            });

            const readingDeck = computed(() => {
                if (!readingInfo.value) return {};
                return deckLookup.value[readingInfo.value.deck_id] || {};
            });

            const cardBackUrl = computed(() => {
                if (!readingInfo.value) return '';
                return 'assets/decks/' + readingInfo.value.deck_id + '/Card_Back.png';
            });

            const readingUrl = computed(() => {
                if (!reading.value) return '';
                return 'https://tarot.mathdad.me/?rid=' + reading.value.reading_id;
            });

            const readingCards = computed(() => {
                if (!readingInfo.value || !readingInfo.value.draw) return [];
                return readingInfo.value.draw.map(card => ({
                    ...card,
                    imgUrl: 'assets/decks/' + readingInfo.value.deck_id + '/Card_' + String(card.card_id).padStart(4, '0') + '.png'
                }));
            });

            const lightboxImageSrc = computed(() => {
                if (lightbox.index === -1) return cardBackUrl.value;
                const card = readingCards.value[lightbox.index];
                return card ? card.imgUrl : '';
            });

            const lightboxImageTitle = computed(() => {
                if (lightbox.index === -1) return 'Card Back';
                const card = readingCards.value[lightbox.index];
                return card ? card.card_name : '';
            });

            // ── Watch: reset additional cards checkbox when deck changes ─
            watch(() => form.deckId, () => {
                form.useAdditionalCards = false;
            });

            // ── Methods ─────────────────────────────────────────────
            function navigateTo(page) {
                clearReading();
                currentPage.value = page;
                burgerOpen.value = false;
            }

            function viewReading() {
                const rid = searchReadingId.value.trim();
                if (rid !== '') {
                    clearReading();
                    fetchReading(rid);
                    searchReadingId.value = '';
                } else {
                    navigateTo('new_reading');
                }
            }

            function submitNewReading() {
                clearReading();
                fetchReading(null);
            }

            function resetForm() {
                form.deckId = decks.value.length > 0 ? decks.value[0].deck_id : null;
                form.useAdditionalCards = false;
                form.numberOfCards = 1;
                form.reversalChance = 0;
                form.numberOfShuffles = 1;
            }

            function clearReading() {
                reading.value = null;
                readingInfo.value = null;

                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('rid')) {
                    window.history.replaceState(null, '', window.location.pathname);
                }
            }

            async function fetchDecks() {
                try {
                    const res = await fetch('/api/deck/');
                    const data = await res.json();
                    decks.value = data;

                    const lookup = {};
                    data.forEach(deck => {
                        lookup[deck.deck_id] = deck;
                    });
                    deckLookup.value = lookup;

                    if (data.length > 0 && form.deckId === null) {
                        form.deckId = data[0].deck_id;
                    }
                } catch {
                    // Silently fail on deck load
                }
            }

            async function fetchReading(readingId) {
                try {
                    let res;
                    if (readingId !== null && readingId !== '') {
                        res = await fetch('/api/reading/' + encodeURIComponent(readingId));
                    } else {
                        const body = new URLSearchParams({
                            deck_id: form.deckId,
                            number_of_cards: form.numberOfCards,
                            reversal_chance: form.reversalChance,
                            number_of_shuffles: form.numberOfShuffles,
                            use_additional_cards: form.useAdditionalCards
                        });
                        res = await fetch('/api/reading/new/', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: body.toString()
                        });
                    }

                    if (!res.ok) {
                        alertModalActive.value = true;
                        return;
                    }

                    const data = await res.json();
                    reading.value = data;
                    readingInfo.value = JSON.parse(data.reading_info);
                    currentPage.value = 'reading';
                    burgerOpen.value = false;
                } catch {
                    alertModalActive.value = true;
                }
            }

            function checkURLForReading() {
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('rid')) {
                    fetchReading(urlParams.get('rid'));
                }
            }

            function copyReadingUrl() {
                navigator.clipboard.writeText(readingUrl.value);
            }

            // ── Lightbox Methods ────────────────────────────────────
            function openLightbox(index) {
                lightbox.index = index;
                lightbox.active = true;
                document.body.style.overflow = 'hidden';
            }

            function closeLightbox() {
                lightbox.active = false;
                document.body.style.overflow = '';
            }

            function lightboxPrev() {
                if (lightbox.index > -1) {
                    lightbox.index--;
                }
            }

            function lightboxNext() {
                if (lightbox.index < readingCards.value.length - 1) {
                    lightbox.index++;
                }
            }

            function handleLightboxKeyboard(e) {
                if (!lightbox.active) return;
                if (e.key === 'Escape') closeLightbox();
                else if (e.key === 'ArrowLeft') lightboxPrev();
                else if (e.key === 'ArrowRight') lightboxNext();
            }

            // ── Lifecycle ───────────────────────────────────────────
            onMounted(async () => {
                await fetchDecks();
                checkURLForReading();
                document.addEventListener('keydown', handleLightboxKeyboard);
            });

            // ── Expose to Template ──────────────────────────────────
            return {
                currentPage,
                burgerOpen,
                searchReadingId,
                alertModalActive,
                decks,
                reading,
                readingInfo,
                form,
                lightbox,
                currentYear,
                selectedDeck,
                readingDeck,
                cardBackUrl,
                readingUrl,
                readingCards,
                lightboxImageSrc,
                lightboxImageTitle,
                navigateTo,
                viewReading,
                submitNewReading,
                resetForm,
                copyReadingUrl,
                openLightbox,
                closeLightbox,
                lightboxPrev,
                lightboxNext
            };
        }
    }).mount('#app');
});