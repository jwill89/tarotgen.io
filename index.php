<!DOCTYPE html>
<html>

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

    <!-- Stylesheets and Javascript -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.2/css/bulma.min.css" />
    <link rel="stylesheet" href="css/lightbox.css" />
    <link rel="stylesheet" href="css/style.css?v=<?=time();?>" />
    <link rel="icon" type="image/x-icon" href="/assets/favicon.png">
    <script src="https://kit.fontawesome.com/f4ac720004.js" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script type="text/javascript" src="js/lightbox.min.js"></script>
    <script type="text/javascript" src="js/main.js?v=<?=time();?>"></script>
</head>

<body class="sticky-footer has-navbar-fixed-top">
    <nav class="navbar is-fixed-top" role="navigation" aria-label="main navigation">
        <div iv="navbar-brand" class="navbar-brand">
            <a class="navbar-burger" id="nav_burger" role="button" aria-label="menu" aria-expanded="false">
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
            </a>
        </div>
        <div id="navbar" class="navbar-menu">
            <div class="navbar-start">
                <div class="buttons" style="margin-left: 10px">
                    <a class="button is-link is-active" id="nav_home">Home</a>
                    <a class="button is-link" id="nav_new_reading">New Reading</a>
                </div>
            </div>
            <div class="navbar-end">
                <div class="navbar-item">
                    <div class="field has-addons">
                        <p class="control">
                            <input class="input" type="text" name="reading_id" id="nav_search_reading_id_input"
                            placeholder="Reading Code" />
                          </p>
                          <p class="control">
                            <button class="button" id="nav_view_reading">View Reading</button>
                          </p>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    <section class="section" id="home">
        <div class="container">
            <h1 class="title">MathDad's Tarot Generator</h1>
            <p class="subtitle">
                Start a new reading using the link or search for your reading using
                the code you were provided. News and updates about the generator below!
            </p>
            <div class=content>
                <h5>Non-Standard & Special Cards - First Pass</h5>
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
    <section class="section is-hidden" id="new_reading">
        <div class="container">
            <h1 class="title">New Reading</h1>
            <p class="subtitle">Choose the settings for your reading.</p>
            <div class="field">
                <label class="label">Deck</label>
                <div class="control has-icons-left">
                    <div class="select">
                        <select name="deck_id" id="deck_id" autocomplete="off">
                        </select>
                    </div>
                    <span class="icon is-small is-left">
                        <i class="fa-solid fa-book"></i>
                    </span>
                </div>
            </div>
            <div class="field is-hidden" id="form_use_additional_cards">
                <div class="control">
                    <label class="checkbox">
                    <input type="checkbox" name="use_additional_cards" id="use_additional_cards" value="true">
                    This deck has extra non-standard cards. Check this box to allow them to be drawn in your reading.
                    </label>
                </div>
            </div>
            <div class="field">
                <label class="label">Number of Cards</label>
                <div class="control has-icons-left">
                    <input class="input" type="number" name="number_of_cards" id="number_of_cards" min="1" max="78" value="1" autocomplete="off" />
                    <span class="icon is-small is-left">
                        <i class="fa-solid fa-diamond"></i>
                    </span>
                </div>
            </div>
            <div class="field">
                <label class="label">Percent Chance of Reversals</label>
                <div class="control has-icons-left">
                    <input class="input" type="number" name="reversal_chance" id="reversal_chance" min="0" max="50" value="0" autocomplete="off" />
                    <span class="icon is-small is-left">
                        <i class="fa-solid fa-percent"></i>
                    </span>
                </div>
            </div>
            <div class="field">
                <label class="label">Number of Shuffles</label>
                <div class="control has-icons-left">
                    <input class="input" type="number" name="number_of_shuffles" id="number_of_shuffles" min="1" value="1" autocomplete="off" />
                    <span class="icon is-small is-left">
                        <i class="fa-solid fa-shuffle"></i>
                    </span>
                </div>
            </div>
            <div class="field">
                <div class="buttons">
                    <button class="button is-primary" name="submit_new_reading" id="submit_new_reading">
                        Generate New Reading
                    </button>
                    <button class="button" name="reset_new_reading" id="reset_new_reading">
                        Reset
                    </button>
                </div>
            </div>
        </div>
    </section>
    <section class="section is-hidden" id="reading">
        <div class="container">
            <div class="content">
                <h1 class="title">Your Reading</h1>
                <p>
                    <ul id="reading_data">
                    </ul>
                </p>
            </div>
            <div class="grid" id="reading_cards">
            </div>
        </div>
    </section>
    <footer class="footer is-flex-align-items-flex-end mt-auto">
        <div class="content has-text-centered">
            <p>
                Coded by <a href="https://www.mathdad.me"><strong>MathDad</strong></a>. Copyright &copy; <script>document.write(new Date().getFullYear())</script>. Please Use Responsibly!<br/>
                Want to support the project? Donate on <a href="https://ko-fi.com/mathdad" target="_blank">Ko-Fi</a> for server costs! Want to see a deck added to the list? Gift it on <a href="https://throne.com/mathdad" target="_blank">Throne</a>!
            </p>
        </div>
    </footer>
    <div class="modal" id="alert_modal">
        <div class="modal-background"></div>
        <div class="modal-content">
            <article class="message is-danger">
                <div class="message-header">
                    <p>Alert</p>
                    <button class="delete" aria-label="delete"></button>
                </div>
                <div class="message-body" id="alert_modal_message">
                    There was an error getting the reading.
                </div>
            </article>
        </div>
        <button class="modal-close is-large" aria-label="close"></button>
      </div>
</body>

</html>