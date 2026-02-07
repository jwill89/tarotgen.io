<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\DeckRepository;
use Tarot\Structure\Deck;

class DeckController extends AbstractController
{
    public function getDeck(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        // Initialize Deck ID if provided
        $deck_id = $this->parseParameters($args, 'deck_id', null);

        // Assume OK status
        $status = 200;

        // Setup Repository
        $repo = new DeckRepository();

        // Get deck data
        $data = $repo->get($deck_id);

        // Check for Invalid Deck Data
        if ($deck_id !== null && (!($data instanceof Deck) || is_array($data))) {
            $data = ['error' => 'InvalidDeckID'];
            $status = 404;
        }

        return $response->withJson($data, $status);
    }
}
