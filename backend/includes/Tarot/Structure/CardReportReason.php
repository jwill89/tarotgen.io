<?php

namespace Tarot\Structure;

use OpenApi\Attributes as OA;

/**
 * What a reporter says is wrong with a card. The values are the API contract:
 * the site and the plugin each render their own labels for them, and stored
 * submissions keep the raw value — so add a case (and its label in both
 * clients) rather than renaming one, which would orphan existing reports.
 */
#[OA\Schema(type: 'string', description: 'What is wrong with a reported card.')]
enum CardReportReason: string
{
    /** Dust, scratches, glare, or smudges picked up by the scan. */
    case Artefact = 'artefact';

    /** Edges cut off, a tilted scan, or uneven borders. */
    case Cropping = 'cropping';

    /** Blurry, low resolution, too dark, or off-colour. */
    case Quality = 'quality';

    /** The image shows a different card than the one named. */
    case WrongCard = 'wrong_card';

    /** Anything else — the reporter has to describe it. */
    case Other = 'other';
}
