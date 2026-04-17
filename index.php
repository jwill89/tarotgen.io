<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>MathDad's Tarot Generator</title>
    <!-- Primary Meta Tags -->
    <meta name="title" content="MathDad's Tarot Generator" />
    <meta name="description" content="Generate your own tarot readings! Select one of various decks, the number of cards you want to draw, how to handle reversals, then shuffle and draw! Bringing the magic of tarot to your browser!" />

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website" />
    <meta property="og:url" content="https://tarot.mathdad.me/" />
    <meta property="og:title" content="MathDad's Tarot Generator" />
    <meta property="og:description" content="Generate your own tarot readings! Select one of various decks, the number of cards you want to draw, how to handle reversals, then shuffle and draw! Bringing the magic of tarot to your browser!" />
    <meta property="og:image" content="https://tarot.mathdad.me/assets/share_banner.png" />

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image" />
    <meta property="twitter:url" content="https://tarot.mathdad.me/" />
    <meta property="twitter:title" content="MathDad's Tarot Generator" />
    <meta property="twitter:description" content="Generate your own tarot readings! Select one of various decks, the number of cards you want to draw, how to handle reversals, then shuffle and draw! Bringing the magic of tarot to your browser!" />
    <meta property="twitter:image" content="https://tarot.mathdad.me/assets/share_banner.png" />

    <!-- Stylesheets and JavaScript -->
    <style>[v-cloak] { display: none !important; }</style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css" />
    <link rel="stylesheet" href="css/style.css?v=<?=filemtime('css/style.css');?>" />
    <link rel="icon" type="image/x-icon" href="/assets/favicon.png">
    <script src="https://kit.fontawesome.com/f4ac720004.js" crossorigin="anonymous" defer></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js" defer></script>
    <script type="text/javascript" src="js/main.js?v=<?=filemtime('js/main.js');?>" defer></script>
</head>

<body class="has-navbar-fixed-top">
    <div id="app" v-cloak class="app-wrapper">
        <nav class="navbar is-fixed-top" role="navigation" aria-label="main navigation">
            <div class="navbar-brand">
                <span class="navbar-item has-text-weight-bold is-size-5 is-hidden-touch">🔮 Tarot</span>
                <a class="navbar-burger" role="button" aria-label="menu"
                   :aria-expanded="burgerOpen ? 'true' : 'false'"
                   :class="{ 'is-active': burgerOpen }"
                   @click="burgerOpen = !burgerOpen">
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                </a>
            </div>
            <div class="navbar-menu" :class="{ 'is-active': burgerOpen }">
                <div class="navbar-start">
                    <a class="navbar-item" :class="{ 'is-active': currentPage === 'home' }"
                       @click="navigateTo('home')">
                        <span class="icon"><i class="fa-solid fa-house"></i></span>
                        <span>Home</span>
                    </a>
                    <a class="navbar-item" :class="{ 'is-active': currentPage === 'new_reading' }"
                       @click="navigateTo('new_reading')">
                        <span class="icon"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                        <span>New Reading</span>
                    </a>
                </div>
                <div class="navbar-end">
                    <div class="navbar-item">
                        <div class="field has-addons">
                            <p class="control is-expanded">
                                <input class="input" type="text" v-model="searchReadingId"
                                       placeholder="Reading Code"
                                       @keyup.enter="viewReading"
                                       aria-label="Reading Code" />
                            </p>
                            <p class="control">
                                <button class="button is-link" @click="viewReading">
                                    <span class="icon is-hidden-mobile"><i class="fa-solid fa-magnifying-glass"></i></span>
                                    <span>View</span>
                                </button>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Home Section -->
        <section class="section" v-if="currentPage === 'home'">
            <div class="container">
                <h1 class="title is-3 is-size-4-mobile">MathDad's Tarot Generator</h1>
                <p class="subtitle is-5 is-size-6-mobile">
                    Start a new reading using the link or search for your reading using
                    the code you were provided. News and updates about the generator below!
                </p>
                <div class="content">
                    <h5>2026-02-07 - New Decks and Updates</h5>
                    <p>
                        My previous two news entries, which were just new deck additions, were erased as I was updating the
                        the code and adding some stuff, so here is a combined update. New decks have been added, and the
                        alerts for different deck types (including Thoth decks) have been added and made bolder so you know
                        when you are using a deck that deviates from Rider-Waite or has extra cards. Hopefully I will
                        motivate myself to start adding basic info for meanings and advice on these cards to help people who
                        are new to tarot get some readings. Anyhow, enjoy the update.
                    </p>
                    <h5>2025-04-08 - Non-Standard &amp; Special Cards - First Pass</h5>
                    <p>
                        I've added the data for non-standard decks and the special extra cards in some decks so they
                        will display in the LightBox popups now! Hopefully this will allow these to be used better,
                        though without a book detailing the meaning it might be difficult, so that is the next step!
                    </p>
                    <h5>2025-03-18 - Small Update</h5>
                    <p>
                        I made a small update. First, non-standard decks (meaning decks that do not follow the typical
                        78-card formula) are now labeled as such in the deck list and allow you to use all the cards
                        they contain by default, but their names will simply be "Non-Standard Deck" when viewed in the
                        LightBox. Also, decks that contain special cards <strong>in addition to</strong> the standard
                        deck will show a checkbox, allowing you to use them when drawing the cards, but will not show
                        their titles when viewed in LightBox either. The next update will be adding this data. Finally,
                        I've added a special URL you can give instead of just the reading id to access readings to make
                        it easier to share! I hope you look forward to the next update.
                    </p>
                    <h5>2025-03-16 - Release!</h5>
                    <p>
                        It's finally here! I got off my lazy butt and got a working on the front end and finished it.
                        The generator is working and good enough for public use. I have some planned improvements I
                        want to implement, but they can mostly be handled on the back-end and don't require much in
                        the way of front-end changes, which is my least-favorite thing to do. Anyhow, enjoy!
                    </p>
                </div>
            </div>
        </section>

        <!-- New Reading Section -->
        <section class="section" v-if="currentPage === 'new_reading'">
            <div class="container">
                <div class="columns is-centered">
                    <div class="column is-8-desktop is-10-tablet">
                        <h1 class="title is-3 is-size-4-mobile">New Reading</h1>
                        <p class="subtitle is-5 is-size-6-mobile">Choose the settings for your reading.</p>
                        <div class="field">
                            <label class="label" for="deck_id">Deck</label>
                            <div class="control has-icons-left">
                                <div class="select is-fullwidth">
                                    <select name="deck_id" id="deck_id" v-model.number="form.deckId" autocomplete="off">
                                        <option v-for="deck in decks" :key="deck.deck_id" :value="deck.deck_id">
                                            {{ deck.name }}, Art by {{ deck.artist }}{{ deck.non_standard ? ' (Non-Standard Deck)' : '' }}{{ deck.is_thoth ? ' (Thoth Deck)' : '' }}
                                        </option>
                                    </select>
                                </div>
                                <span class="icon is-small is-left">
                                    <i class="fa-solid fa-book"></i>
                                </span>
                            </div>
                        </div>
                        <div class="notification is-warning" v-if="selectedDeck && selectedDeck.has_extras">
                            <div class="control">
                                <label class="checkbox">
                                    <input type="checkbox" v-model="form.useAdditionalCards">
                                    <strong>This deck has extra non-standard cards.</strong>
                                    Check this box to allow them to be drawn in your reading.
                                </label>
                            </div>
                        </div>
                        <div class="notification is-info is-light" v-if="selectedDeck && selectedDeck.non_standard">
                            This deck is a unique <strong>non-standard</strong> deck, meaning it does not follow the traditional
                            Rider-Waite 78-card tarot deck structure. All cards in this deck will be available for drawing.
                        </div>
                        <div class="notification is-info is-light" v-if="selectedDeck && selectedDeck.is_thoth">
                            This deck is a <strong>Thoth</strong> deck. Please note that the card names and structure will
                            differ from traditional Rider-Waite decks.
                        </div>
                        <div class="columns is-multiline">
                            <div class="column is-4-desktop is-6-tablet">
                                <div class="field">
                                    <label class="label" for="number_of_cards">Number of Cards</label>
                                    <div class="control has-icons-left">
                                        <input class="input" type="number" id="number_of_cards" v-model.number="form.numberOfCards" min="1" max="78" autocomplete="off" />
                                        <span class="icon is-small is-left">
                                            <i class="fa-solid fa-diamond"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="column is-4-desktop is-6-tablet">
                                <div class="field">
                                    <label class="label" for="reversal_chance">Reversal Chance (%)</label>
                                    <div class="control has-icons-left">
                                        <input class="input" type="number" id="reversal_chance" v-model.number="form.reversalChance" min="0" max="50" autocomplete="off" />
                                        <span class="icon is-small is-left">
                                            <i class="fa-solid fa-percent"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="column is-4-desktop is-6-tablet">
                                <div class="field">
                                    <label class="label" for="number_of_shuffles">Number of Shuffles</label>
                                    <div class="control has-icons-left">
                                        <input class="input" type="number" id="number_of_shuffles" v-model.number="form.numberOfShuffles" min="1" autocomplete="off" />
                                        <span class="icon is-small is-left">
                                            <i class="fa-solid fa-shuffle"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="field">
                            <div class="buttons">
                                <button class="button is-primary is-medium" @click="submitNewReading">
                                    <span class="icon"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
                                    <span>Generate New Reading</span>
                                </button>
                                <button class="button is-medium" @click="resetForm">
                                    <span class="icon"><i class="fa-solid fa-rotate-left"></i></span>
                                    <span>Reset</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Reading Section -->
        <section class="section" v-if="currentPage === 'reading' && reading">
            <div class="container">
                <div class="columns is-multiline">
                    <div class="column is-narrow has-text-centered-mobile">
                        <figure class="image card-back mx-auto">
                            <a :href="cardBackUrl" @click.prevent="openLightbox(-1)" style="cursor:pointer">
                                <img :src="cardBackUrl" alt="Card Back" title="Card Back" />
                            </a>
                        </figure>
                    </div>
                    <div class="column">
                        <div class="content">
                            <h1 class="title is-3 is-size-4-mobile">Your Reading</h1>
                            <ul class="reading-info-list">
                                <li><strong>Reading ID</strong>: <code>{{ reading.reading_id }}</code></li>
                                <li class="reading-url-row">
                                    <strong>Reading URL</strong>:
                                    <span class="is-family-code reading-url-text">{{ readingUrl }}</span>
                                    <button class="button is-small is-info is-rounded ml-2" @click="copyReadingUrl" title="Copy Reading URL">
                                        <span class="icon is-small"><i class="fa-solid fa-copy"></i></span>
                                    </button>
                                </li>
                                <li><strong>Reading Date</strong>: {{ reading.reading_time }}</li>
                                <li><strong>Deck</strong>: {{ readingDeck.name }}</li>
                                <li><strong>Artist</strong>: {{ readingDeck.artist }}</li>
                                <li>
                                    <strong>Purchase URL</strong>:
                                    <a :href="readingDeck.purchase_url" target="_blank" rel="noopener noreferrer">Purchase Deck</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="fixed-grid has-3-cols-desktop has-2-cols-tablet has-1-cols-mobile">
                    <div class="grid">
                        <div class="cell" v-for="(card, index) in readingCards" :key="index">
                            <figure class="image card-image mx-auto">
                                <a :href="card.imgUrl" @click.prevent="openLightbox(index)" style="cursor:pointer">
                                    <img :src="card.imgUrl" :class="{ reversed: card.reversed }" :alt="card.card_name" :title="card.card_name" loading="lazy" />
                                </a>
                                <figcaption class="has-text-centered mt-2 is-size-7">{{ card.card_name }}<span v-if="card.reversed" class="has-text-warning"> (Reversed)</span></figcaption>
                            </figure>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Loading Overlay -->
        <div class="loading-overlay" v-if="isLoading">
            <div class="has-text-centered">
                <span class="icon is-large has-text-white">
                    <i class="fa-solid fa-spinner fa-spin fa-3x"></i>
                </span>
                <p class="has-text-white mt-3 is-size-5">Drawing your cards...</p>
            </div>
        </div>

        <footer class="footer mt-auto">
            <div class="content has-text-centered">
                <p>
                    Coded by <a href="https://www.mathdad.me"><strong>MathDad</strong></a>. Copyright &copy; {{ currentYear }}. Please Use Responsibly!<br/>
                    Want to support the project? Donate on <a href="https://ko-fi.com/mathdad" target="_blank" rel="noopener noreferrer">Ko-Fi</a> for server costs!
                    Want to see a deck added to the list? Gift it on <a href="https://throne.com/mathdad" target="_blank" rel="noopener noreferrer">Throne</a>!
                </p>
            </div>
        </footer>

        <!-- Alert Modal -->
        <div class="modal" :class="{ 'is-active': alertModalActive }">
            <div class="modal-background" @click="alertModalActive = false"></div>
            <div class="modal-content">
                <article class="message is-danger">
                    <div class="message-header">
                        <p>Alert</p>
                        <button class="delete" aria-label="delete" @click="alertModalActive = false"></button>
                    </div>
                    <div class="message-body">
                        There was an error getting the reading.
                    </div>
                </article>
            </div>
            <button class="modal-close is-large" aria-label="close" @click="alertModalActive = false"></button>
        </div>

        <!-- Lightbox Overlay -->
        <div class="lightbox-overlay" v-if="lightbox.active" @click.self="closeLightbox"
             @touchstart="onTouchStart" @touchend="onTouchEnd">
            <button class="lightbox-close" aria-label="Close lightbox" @click="closeLightbox">&times;</button>
            <button class="lightbox-nav lightbox-prev" v-if="lightbox.index > -1" @click="lightboxPrev" aria-label="Previous image">&#10094;</button>
            <div class="lightbox-content">
                <img :src="lightboxImageSrc" :alt="lightboxImageTitle" :class="{ reversed: lightboxShowReversed }" />
                <p class="lightbox-caption" v-if="lightboxImageTitle">
                    {{ lightboxImageTitle }}
                    <span v-if="lightboxIsReversed" class="lightbox-reversed-tag">(Reversed)</span>
                </p>
                <button class="button is-small is-rounded lightbox-flip-btn" v-if="lightboxIsReversed" @click="toggleLightboxFlip">
                    <span class="icon is-small"><i class="fa-solid fa-rotate"></i></span>
                    <span>{{ lightbox.flipped ? 'View Reversed' : 'View Upright' }}</span>
                </button>
                <p class="lightbox-counter" v-if="lightbox.index >= 0 && readingCards.length > 1">
                    Image {{ lightbox.index + 1 }} of {{ readingCards.length }}
                </p>
            </div>
            <button class="lightbox-nav lightbox-next" v-if="lightbox.index < readingCards.length - 1" @click="lightboxNext" aria-label="Next image">&#10095;</button>
        </div>
    </div>
</body>

</html>

