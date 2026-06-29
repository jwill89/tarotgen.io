<?php

namespace Tarot\Data;

/**
 * Shared sanitizer for spread position payloads. Used by both SpreadData and
 * PendingSpreadData so the official and user-submitted tables enforce the same
 * shape: clamped coordinates, normalized rotation, length-capped titles.
 */
trait NormalizesPositions
{
    /**
     * @return list<array{order:int, title:string, x:float, y:float, rotation:int}>
     */
    private function normalizePositions(mixed $positions): array
    {
        if (!is_array($positions)) {
            return [];
        }

        $out = [];
        foreach ($positions as $p) {
            if (!is_array($p)) {
                continue;
            }

            $rotation = (int)($p['rotation'] ?? 0) % 360;
            if ($rotation < 0) {
                $rotation += 360;
            }

            $out[] = [
                'order'    => (int)($p['order'] ?? 0),
                'title'    => mb_substr(trim((string)($p['title'] ?? '')), 0, 100),
                'x'        => max(0.0, min(100.0, round((float)($p['x'] ?? 50), 2))),
                'y'        => max(0.0, min(100.0, round((float)($p['y'] ?? 50), 2))),
                'rotation' => $rotation,
            ];
        }

        return $out;
    }
}
