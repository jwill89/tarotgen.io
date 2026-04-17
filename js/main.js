/**
 * @file main.js
 * @description Main JS file for Tarot Reading Application
 * @author MathDad <https://www.mathdad.me>
 * @license MIT
 * @version 1.0.1
 */
let deckData = [];

$(document).ready(function() {
    // Set the current year in the footer
    $('#copyright_year').text(new Date().getFullYear());

    // Get Deck Data
    getDeckData();

    // Navbar Mobile Burger Menu Toggle
    $('#nav_burger').on('click', function() {
        $('#nav_burger').toggleClass('is-active');
        $(".navbar-menu").toggleClass("is-active");
    });

    // Navbar - Home
    $('#nav_home').on('click', function() {
        // Set Home Active
        $('#nav_home').addClass('is-active');
        $('#home').removeClass('is-hidden');

        // Set New Reading Inactive
        $('#nav_new_reading').removeClass('is-active');
        $('#new_reading').addClass('is-hidden');

        // Clear Existing Reading
        clearReadingPage();
    });

    // Navbar - New Reading
    $('#nav_new_reading').on('click', function() {
        // Set Home Inactive
        $('#nav_home').removeClass('is-active');
        $('#home').addClass('is-hidden');

        // Set New Reading Active
        $('#nav_new_reading').addClass('is-active');
        $('#new_reading').removeClass('is-hidden');

        // Clear Existing Reading
        clearReadingPage();
    });

    // Navbar - View Reading (Search)
    $('#nav_view_reading').on('click', function() {
        // Get Reading Code
        let readingId = $('#nav_search_reading_id_input').val();

        // Check for Blank Reading ID
        if (readingId !== '') {
            // Clear Existing Reading
            clearReadingPage();

            // Get Reading Data
            getReadingData(readingId);

            // Clear Reading Value
            $('#nav_search_reading_id_input').val('')
        } else {
            // New Reading
            $('#nav_new_reading').trigger('click');
        }
    });

    // Form - Get New Reading
    $('#submit_new_reading').on('click', function() {
        // Clear Existing Reading
        clearReadingPage();
        
        // Get New Reading
        getReadingData();
    });

    // Form - Show Additional Card Checkbox
    $('#deck_id').on('change', function() {
        // Clear existing checked status
        $('#use_additional_cards').prop('checked', false);

        // If deck has extras, show the option to use them.
        if (deckData[this.value].has_extras) {
            $('#form_use_additional_cards').removeClass('is-hidden');
        } else {
            $('#form_use_additional_cards').addClass('is-hidden');
        }

        // If the deck is non-standard, show the notification
        if (deckData[this.value].non_standard) {
            $('#form_non_standard_deck').removeClass('is-hidden');
        } else {
            $('#form_non_standard_deck').addClass('is-hidden');
        }

        // If the deck is a Thoth deck, show the notification
        if (deckData[this.value].is_thoth) {
            $('#form_thoth_deck').removeClass('is-hidden');
        } else {
            $('#form_thoth_deck').addClass('is-hidden');
        }
    });

    // Form - Reset New Reading
    $('#reset_new_reading').on('click', function() {
        $('#deck_id').val(1);
        $('#use_additional_cards').prop('checked', false);
        $('#form_use_additional_cards').addClass('is-hidden');
        $('#number_of_cards').val(1);
        $('#reversal_chance').val(0);
        $('#number_of_shuffles').val(1);
    });

    // Modal - Close When Clicked Places
    $('.modal-background, .modal-close, .message-header .delete, .modal-card-foot .button').on('click', function() {
        $('#alert_modal').removeClass('is-active');
    });

    // Get Deck Data
    function getDeckData() {
        $.ajax({
            url: '/api/deck/',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                let options = '';
                $.each(data, function(index, deck) {
                    options += '<option value="' + deck.deck_id + '">' + deck.name + ', Art by ' + deck.artist + (deck.non_standard ? ' (Non-Standard Deck)' : '') + (deck.is_thoth ? ' (Thoth Deck)' : '') + '</option>';
                    deckData[deck.deck_id] = {name: deck.name, artist: deck.artist, purchase_url: deck.purchase_url, is_thoth: deck.is_thoth, non_standard: deck.non_standard, has_extras: deck.has_extras, total_cards: deck.total_cards, additional_cards: deck.additional_cards};
                });
                $('#deck_id').append(options);
            },
            complete: function() {
                // Check URL for Reading ID after deck data loaded
                checkURLForReading();
            }
        });
    }

    // Check URL Data
    function checkURLForReading() {
        // Get Search Parameters
        let urlParams = new URLSearchParams(window.location.search);

        // Check for Reading
        if(urlParams.has('rid')) {
            let readingID = urlParams.get('rid');

            // Get the Reading Data
            getReadingData(readingID);
        }
    }

    // Get Reading Data
    function getReadingData(readingId = null) {
        // Default is for New Reading
        let callType = 'POST';
        let callURL = '/api/reading/new/';
        let callData = {
            deck_id: $('#deck_id').val(),
            number_of_cards: $('#number_of_cards').val(),
            reversal_chance: $('#reversal_chance').val(),
            number_of_shuffles: $('#number_of_shuffles').val(),
            use_additional_cards: $('#use_additional_cards').is(':checked')
        };

        // Set Data Based On Reading ID
        if (readingId !== '' && readingId !== null) {
            callType = 'GET';
            callURL = '/api/reading/' + readingId;
            callData = {};
        }
        $.ajax({
            url: callURL,
            type: callType,
            data: callData,
            dataType: 'json',
            success: function(data) {
                // Set Home Inactive
                $('#nav_home').removeClass('is-active');
                $('#home').addClass('is-hidden');

                // Set New Reading Active
                $('#nav_new_reading').removeClass('is-active');
                $('#new_reading').addClass('is-hidden');

                // Set Reading Active
                $('#reading').removeClass('is-hidden');

                // Add Reading & Deck Info
                let readingInfo = JSON.parse(data.reading_info);
                let readingHtml = '';
                readingHtml += '<li><strong>Reading ID</strong>: ' + data.reading_id + '</li>';
                readingHtml += '<li><strong>Reading URL</strong>: <span class="is-family-code" id="reading_url">https://tarot.mathdad.me/?rid=' + data.reading_id + '</span> <button class="button is-small is-responsive is-info is-rounded" id="copy_reading_url" title="Copy Reading URL"><span class="icon is-small"><i class="fa-solid fa-copy"></i></span></button></li>';
                readingHtml += '<li><strong>Reading Date</strong>: ' + data.reading_time + '</li>';
                readingHtml += '<li><strong>Deck</strong>: ' + deckData[readingInfo.deck_id].name + '</li>';
                readingHtml += '<li><strong>Artist</strong>: ' + deckData[readingInfo.deck_id].artist + '</li>';
                readingHtml += '<li><strong>Purchase URL</strong>: <a href="' + deckData[readingInfo.deck_id].purchase_url + '" target="_blank">Purchase Deck</a></li>';
                $('#reading_data').append(readingHtml);

                // Add Card Back
                let imgURLCardBack = "assets/decks/" + readingInfo.deck_id + "/Card_Back.png";
                $('#card_back').attr("src", imgURLCardBack)
                $('#card_back_url').attr("href", imgURLCardBack);

                // Build all card HTML at once
                let cardsHtml = '';
                $.each(readingInfo.draw, function(index, card) {
                    let imgURLCard = "assets/decks/" + readingInfo.deck_id + "/Card_" + ((card.card_id + '').padStart(4, '0')) + ".png"
                    cardsHtml += "<div class='cell'><figure class='image card-image'><a href='" + imgURLCard + "' data-lightbox='Reading' title='" + card.card_name + "'><img src='" + imgURLCard + "'" + (card.reversed ? " class='reversed'" : "") + " loading='lazy' /></a></figure></div>";
                });
                $('#reading_cards').append(cardsHtml);

                // Copy Reading URL Listener
                $('#copy_reading_url').on('click', function() {
                    navigator.clipboard.writeText($('#reading_url').text());
                });
            },
            error: function() {
                $('#alert_modal').addClass('is-active');
            }
        });
    }

    // Clear Reading Page
    function clearReadingPage() {
        $('#reading').addClass('is-hidden');
        $('#reading_data').empty();
        $('#reading_cards').empty();

        // If we have a reading ID, clear it.
        let urlParams = new URLSearchParams(window.location.search);

        // Check for Reading
        if(urlParams.has('rid')) {
            window.history.replaceState(null, '', window.location.pathname);
        }
    }
});