<?php

namespace Routes\Internal;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\CardReportRepository;
use Tarot\Utility\RateLimiter;

/**
 * Public endpoint for reporting a card scan that has artefacts/issues and should
 * be re-scanned. Reporting a card that already has an open report just bumps its
 * counter. Two rate limits keep this from being abused: an overall per-IP cap,
 * and a per-IP-per-card cooldown so one person can't inflate a card's count.
 */
class CardReportController extends AbstractController
{
    /** Overall abuse cap: reports per IP per window. */
    private const int REPORT_MAX_PER_WINDOW = 20;

    /** A given IP may report the same card at most once per window (no count inflation). */
    private const int PER_CARD_MAX_PER_WINDOW = 1;

    private const int WINDOW_SECONDS = 3600;

    public function __construct(private readonly CardReportRepository $reports)
    {
    }

    #[OA\Post(
        path: '/card-reports',
        summary: 'Report a card scan as needing a re-scan (rate-limited)',
        tags: ['Cards'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['deck_id', 'card_id'],
                properties: [
                    new OA\Property(property: 'deck_id', type: 'integer'),
                    new OA\Property(property: 'card_id', type: 'integer'),
                    new OA\Property(property: 'card_name', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Report recorded',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'status', type: 'string')])
            ),
            new OA\Response(
                response: 200,
                description: 'Already reported this card recently (counter not bumped)',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'status', type: 'string')])
            ),
            new OA\Response(response: 400, description: 'Missing/invalid deck or card id'),
            new OA\Response(response: 429, description: 'Too many reports from this IP'),
        ]
    )]
    public function report(Request $request, Response $response): Response|ResponseInterface
    {
        $ip = $this->clientIp($request);

        $overall = new RateLimiter('card_report_ip', self::REPORT_MAX_PER_WINDOW, self::WINDOW_SECONDS);
        if ($overall->isLimited($ip)) {
            return $response->withJson(
                ['error' => 'You have reported too many cards recently. Please try again later.'],
                429
            );
        }

        $body     = $this->parsedBody($request);
        $deckId   = (int)($body['deck_id'] ?? 0);
        $cardId   = (int)($body['card_id'] ?? -1);
        $cardName = mb_substr(trim((string)($body['card_name'] ?? '')), 0, 120);

        if ($deckId < 1 || $cardId < 0) {
            return $response->withJson(['error' => 'A valid deck and card are required.'], 400);
        }

        // Per-card cooldown: if this IP already reported this exact card in the
        // window, treat it as a friendly no-op rather than inflating the counter.
        $perCard = new RateLimiter('card_report_card', self::PER_CARD_MAX_PER_WINDOW, self::WINDOW_SECONDS);
        $cardKey = "{$ip}:{$deckId}:{$cardId}";
        if ($perCard->isLimited($cardKey)) {
            return $response->withJson(['status' => 'already_reported']);
        }

        $this->reports->report($deckId, $cardId, $cardName);

        $perCard->hit($cardKey);
        $overall->hit($ip);

        return $response->withJson(['status' => 'reported'], 201);
    }
}
