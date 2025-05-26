<?php

namespace Routes\Internal;

use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\CardRepository;
use Tarot\Repository\DeckRepository;
use Tarot\Repository\ReadingRepository;
use Tarot\Repository\SpecialCardRepository;
use Tarot\Structure\Reading;

class ReadingController extends AbstractController
{
    public function getReading(Request $request, Response $response, array $args)
    {
        // Initialize Reading ID if provided
        $reading_id = $this->parseParameters($args, 'reading_id', null);

        // Assume OK status
        $status = 200;

        // Default Data
        $data = [];

        // Setup Repository
        $repo = new ReadingRepository();

        // If we have a Reading ID, retrieve it.
        if ($reading_id !== null) {
            $data = $repo->get($reading_id);
        }

        // Check for Invalid Reading Data
        if ($reading_id !== null && !($data instanceof Reading)) {
            $data = ['error' => 'InvalidReadingID'];
            $status = 404;
        }

        return $response->withJson($data, $status);
    }

    public function newReading(Request $request, Response $response, array $args)
    {
        // Initialize Required Variables
        $params = $request->getParsedBody();
        $number_of_cards = (int)$this->parseParameters($params, 'number_of_cards', 1);
        $deck_id = (int)$this->parseParameters($params, 'deck_id', 1);
        $reversal_chance = (int)$this->parseParameters($params, 'reversal_chance', 0);
        $number_of_shuffles = (int)$this->parseParameters($params, 'number_of_shuffles', 1);
        $use_additional_cards = (bool)$this->parseParameters($params, 'use_additional_cards', false);

        // Assume OK status
        $status = 200;

        // Default Data
        $data = [];

        // Setup Repositories
        $reading_repo = new ReadingRepository();
        $card_repo = new CardRepository();
        $deck_repo = new DeckRepository();
        $special_card_repo = new SpecialCardRepository();

        // Get Deck Data
        $deck = $deck_repo->get($deck_id);

        // Total Cards
        $total_cards = $deck->getTotalCards();

        // If flag was sent, use additional cards. Default is 0 so mistakes won't effect outcome.
        if ($use_additional_cards) {
            $total_cards += $deck->getAdditionalCards();
        }


        // Generate Deck - Based on Deck's Data
        $deck_of_cards = range(1, $total_cards);

        // Shuffle the deck.
        for ($i = 0; $i < $number_of_shuffles; $i++) {
            shuffle($deck_of_cards);
        }

        // Get the draw
        $draw = array_slice($deck_of_cards, 0, $number_of_cards);

        // If no reversals, all false
        if ($reversal_chance === 0) {
            $reversal_values = array_fill(0, $number_of_cards, false);
        // Generate array of reversal values for each card.
        } else {
            for ($i = 0; $i < $number_of_cards; $i++) {
                $reversal_values[] = (bool)(mt_rand(1, 100) <= $reversal_chance); 
            }
        }

        // Build the Reading
        $reading = new Reading();

        // Generate Reading ID
        $reading_id = bin2hex(random_bytes(5));
        $reading->setReadingId($reading_id);

        // Setup the Reading Data
        $reading_data = [];
        $reading_data['deck_id'] = $deck_id;
        $reading_data['draw'] = [];

        foreach ($draw as $key => $card_id) {
            // Set normal properties
            $reading_data['draw'][$key]['card_id'] = $card_id;
            $reading_data['draw'][$key]['reversed'] = $reversal_values[$key];

            // Get Card from Base of Special/Nonstandard
            if ($deck->isNonStandard() || $card_id > 78) {
                $card = $special_card_repo->get($deck_id, $card_id);
            } else {
                $card = $card_repo->get($card_id);
            }

            // Set Card Name and unset card just in case
            $reading_data['draw'][$key]['card_name'] = $card->getName();
            unset($card);
        }

        // Set Reading Info
        $reading->setReadingInfo(json_encode($reading_data, true));

        // Save the Reading
        $reading = $reading_repo->save($reading);

        // Save data
        $data = $reading;

        // Check for Invalid Reading Data
        if (!($data instanceof Reading)) {
            $data = ['error' => 'ErrorGeneratingReading'];
            $status = 404;
        }

        return $response->withJson($data, $status);
    }
}
