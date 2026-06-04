#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Clean scanned tarot card images: trim the white/shadowed scanner halo around
each card, erase the remaining background to transparency, optionally deskew,
and optionally normalise every card in a deck to a uniform size — without ever
touching the originals.

How it detects the card (and why it keeps the card's OWN border):
    The scanner bed shows up as a near-uniform background with a faint shadow
    ring where the card sits on it. We build a "not background" mask by measuring
    each pixel's colour distance from the sampled background colour, then close it
    so the shadow ring forms a solid outline of the physical card. The largest
    contour of that mask is the card — including any white/cream border the card
    design has, because the shadow encloses the whole card, not just the art. So
    we crop to the card edge rather than to "where the white starts".

Background erasure:
    After cropping, any lingering scanner-bed colour at the edges (especially
    around rounded corners) is made transparent using a feathered colour-distance
    mask, giving clean edges that composit well on any background.

Originals are never modified: output goes to a separate folder, and the script
refuses to run if the output path is the same as (or inside) the input.

USAGE
    pip install -r scripts/requirements.txt        # opencv-python, numpy

    # One deck:
    python scripts/clean_deck_images.py --input assets/decks/1 --output cleaned/1

    # Every deck under a root (mirrors <id>/ subfolders into the output):
    python scripts/clean_deck_images.py --input assets/decks --output cleaned --per-deck

    # Normalise all cards to a uniform 500x860 (transparent padding, no crop):
    python scripts/clean_deck_images.py --input assets/decks/1 --output cleaned/1 --size 500x860

    # Keep the background opaque (skip erasure):
    python scripts/clean_deck_images.py --input assets/decks/1 --output cleaned/1 --no-erase-bg

    # Tune detection without writing card files (only the contact sheet):
    python scripts/clean_deck_images.py --input assets/decks/1 --output cleaned/1 --dry-run

KEY OPTIONS
    --fuzz N        Background tolerance %, higher = more aggressive trim (default 6).
    --pad N         Keep N px around the detected card; negative shaves inward (default 0).
    --deskew        Straighten rotated scans (perspective warp). Off by default.
    --max-rotation  Max degrees to deskew; larger angles are left alone (default 8).
    --corner-radius N  Rounded corner radius in px. Auto-detected from the scan if omitted.
                       Set to 0 to disable corner rounding entirely.
    --no-erase-bg   Skip background erasure; output will have an opaque background.
    --size WxH      Pad/scale each output to exactly WxH (uniform deck). Omit to keep native crop.
    --mode          'contour' (default, edge-aware) or 'trim' (simple fuzz trim, full-bleed decks).
    --no-back       Skip Card_Back.png.
    --overwrite     Allow writing into a non-empty output folder.
"""

from __future__ import annotations

import argparse
import gc
import io
import sys
import traceback
from pathlib import Path

# Ensure Unicode output works on Windows consoles.
if sys.stdout.encoding != "utf-8":
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
if sys.stderr.encoding != "utf-8":
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding="utf-8", errors="replace")

try:
    import cv2
    import numpy as np
except ImportError:
    sys.exit("Missing dependencies. Run:  pip install -r scripts/requirements.txt")


# ── Image helpers ────────────────────────────────────────────────────────────

def load_bgra(path: Path):
    """Load an image as BGRA (always 4 channels)."""
    img = cv2.imread(str(path), cv2.IMREAD_UNCHANGED)
    if img is None:
        return None
    if img.ndim == 2:  # grayscale
        img = cv2.cvtColor(img, cv2.COLOR_GRAY2BGRA)
    elif img.shape[2] == 3:
        img = cv2.cvtColor(img, cv2.COLOR_BGR2BGRA)
    return img


def estimate_bg(bgr) -> np.ndarray:
    """Median colour of a thin frame around the edges = the scanner bed colour."""
    h, w = bgr.shape[:2]
    t = max(2, int(min(h, w) * 0.02))
    frame = np.concatenate([
        bgr[:t, :].reshape(-1, 3),
        bgr[-t:, :].reshape(-1, 3),
        bgr[:, :t].reshape(-1, 3),
        bgr[:, -t:].reshape(-1, 3),
    ])
    return np.median(frame, axis=0)


def foreground_mask(bgra, fuzz: float) -> np.ndarray:
    """
    Mask of pixels that differ from the background. Uses the alpha channel
    directly when the image already has transparency; otherwise colour distance
    from the sampled background. The result is morphologically closed so the
    shadow ring becomes a solid card outline.
    """
    h, w = bgra.shape[:2]
    alpha = bgra[:, :, 3]

    if alpha.min() < 250:  # already has real transparency
        mask = (alpha > 16).astype(np.uint8) * 255
    else:
        bgr = bgra[:, :, :3].astype(np.int16)
        bg = estimate_bg(bgra[:, :, :3])
        dist = np.abs(bgr - bg.astype(np.int16)).sum(axis=2)
        threshold = (fuzz / 100.0) * (255 * 3)
        mask = (dist > threshold).astype(np.uint8) * 255

    # Close gaps so the faint shadow ring connects into one card-shaped blob.
    k = max(3, (int(min(h, w) * 0.012) | 1))
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k, k))
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel, iterations=2)
    return mask


def largest_card_contour(mask):
    """Largest external contour that's a plausible fraction of the frame, else None."""
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not contours:
        return None
    biggest = max(contours, key=cv2.contourArea)
    frame_area = mask.shape[0] * mask.shape[1]
    # Reject noise / accept anything covering at least ~15% of the image.
    if cv2.contourArea(biggest) < 0.15 * frame_area:
        return None
    return biggest


def order_quad(pts: np.ndarray) -> np.ndarray:
    """Order 4 points as top-left, top-right, bottom-right, bottom-left."""
    s = pts.sum(axis=1)
    d = np.diff(pts, axis=1).ravel()
    return np.array([
        pts[np.argmin(s)],   # tl
        pts[np.argmin(d)],   # tr
        pts[np.argmax(s)],   # br
        pts[np.argmax(d)],   # bl
    ], dtype=np.float32)


def estimate_corner_radius(contour, rect_w: int, rect_h: int) -> int:
    """
    Estimate the corner radius of a card from its contour by measuring how far
    the contour deviates from the bounding rectangle at the corners. Returns an
    approximate pixel radius, or 0 if corners appear sharp.

    The radius returned is the value needed for apply_rounded_mask() — the radius
    of the inscribed circle at each corner of the rounded rectangle.
    """
    x, y, bw, bh = cv2.boundingRect(contour)
    # Sample a candidate radius proportional to the shorter side.
    max_r = int(min(bw, bh) * 0.12)
    if max_r < 4:
        return 0

    # For each corner region, measure how far inward the contour actually starts.
    # The distance from the bounding-rect corner to the nearest contour point is
    # approximately r * sqrt(2) for a perfect rounded corner (the diagonal gap).
    pts = contour.reshape(-1, 2).astype(np.float64)

    corners = [
        (x, y),                  # top-left
        (x + bw, y),             # top-right
        (x + bw, y + bh),       # bottom-right
        (x, y + bh),            # bottom-left
    ]
    radii = []
    for cx, cy in corners:
        # Find the closest contour point to this corner.
        dists = np.sqrt((pts[:, 0] - cx) ** 2 + (pts[:, 1] - cy) ** 2)
        min_dist = dists.min()
        # The diagonal gap = r * sqrt(2), so r = gap / sqrt(2).
        # But we want the mask radius which IS r, not the gap.
        r = int(min_dist / 1.414)
        radii.append(min(r, max_r))

    median_r = int(np.median(radii))
    return median_r if median_r >= 3 else 0


def estimate_corner_radius_from_pixels(bgra) -> int:
    """
    Estimate the corner radius by examining actual pixel colours in the corner
    regions of an already-cropped card image. This works even when the contour
    detection can't distinguish rounded corners (e.g. tight crops where the
    morphological close fills the corner gap).

    Walks diagonally inward from each corner and looks for a sharp colour edge
    (gradient spike) indicating where the scanner bed meets the card. The diagonal
    distance to that edge gives us the radius via: r = diagonal / (sqrt(2) - 1).
    """
    h, w = bgra.shape[:2]
    bgr = bgra[:, :, :3].astype(np.float64)

    # Sample the corner colour (median of the 4 corner 3x3 patches).
    corner_samples = np.concatenate([
        bgr[0:3, 0:3].reshape(-1, 3),
        bgr[0:3, w-3:w].reshape(-1, 3),
        bgr[h-3:h, 0:3].reshape(-1, 3),
        bgr[h-3:h, w-3:w].reshape(-1, 3),
    ])
    corner_colour = np.median(corner_samples, axis=0)

    # Sample the centre of each edge to get the card border colour.
    mid_h, mid_w = h // 2, w // 2
    edge_strip = max(5, int(min(h, w) * 0.01))
    card_edge_samples = np.concatenate([
        bgr[0:edge_strip, mid_w-5:mid_w+5].reshape(-1, 3),       # top edge centre
        bgr[h-edge_strip:h, mid_w-5:mid_w+5].reshape(-1, 3),     # bottom edge centre
        bgr[mid_h-5:mid_h+5, 0:edge_strip].reshape(-1, 3),       # left edge centre
        bgr[mid_h-5:mid_h+5, w-edge_strip:w].reshape(-1, 3),     # right edge centre
    ])
    card_edge_colour = np.median(card_edge_samples, axis=0)

    # If corner colour is the same as the card edge, there's no rounding.
    colour_diff = np.abs(corner_colour - card_edge_colour).sum()
    if colour_diff < 40:
        return 0

    # Walk diagonally inward from each corner. Look for where we reach a colour
    # that's close to the card edge colour (i.e., we've left the scanner bed).
    max_walk = min(int(min(h, w) * 0.10), 150)
    walk_results = []

    for dy, dx in [(1, 1), (1, -1), (-1, 1), (-1, -1)]:
        sy = 0 if dy > 0 else h - 1
        sx = 0 if dx > 0 else w - 1

        transition_dist = 0
        for step in range(2, max_walk):
            py = sy + dy * step
            px = sx + dx * step
            if py < 0 or py >= h or px < 0 or px >= w:
                break
            pixel = bgr[py, px]
            # Distance from card edge colour (target) — when close, we've arrived.
            dist_to_card = np.abs(pixel - card_edge_colour).sum()
            if dist_to_card < colour_diff * 0.4:
                transition_dist = step
                break

        if transition_dist > 2:
            walk_results.append(transition_dist)

    if len(walk_results) < 2:
        return 0

    # Use the two largest values (most reliable — small values may be noise).
    walk_results.sort(reverse=True)
    median_diag = np.median(walk_results[:2])

    # The diagonal transition distance relates to radius:
    # On a rounded rect, the diagonal distance from corner to the curve is
    # r * (sqrt(2) - 1) ~ r * 0.414. So r = diagonal / 0.414.
    radius = int(median_diag / 0.414)

    # Clamp to reasonable bounds.
    max_r = int(min(h, w) * 0.12)
    return min(max(radius, 3), max_r) if radius >= 3 else 0


def apply_rounded_mask(bgra, radius: int):
    """
    Apply a rounded-rectangle alpha mask to a cropped card image with smooth
    anti-aliased edges. Draws the mask at 4× resolution and downscales to get
    sub-pixel smoothness on the rounded corners.
    """
    if radius < 2:
        return bgra
    h, w = bgra.shape[:2]
    scale = 4  # supersampling factor for anti-aliasing
    sw, sh, sr = w * scale, h * scale, radius * scale

    # Draw the rounded rect at high resolution.
    hi_mask = np.zeros((sh, sw), dtype=np.uint8)
    cv2.rectangle(hi_mask, (sr, 0), (sw - sr - 1, sh - 1), 255, -1)
    cv2.rectangle(hi_mask, (0, sr), (sw - 1, sh - sr - 1), 255, -1)
    cv2.ellipse(hi_mask, (sr, sr), (sr, sr), 180, 0, 90, 255, -1)
    cv2.ellipse(hi_mask, (sw - sr - 1, sr), (sr, sr), 270, 0, 90, 255, -1)
    cv2.ellipse(hi_mask, (sw - sr - 1, sh - sr - 1), (sr, sr), 0, 0, 90, 255, -1)
    cv2.ellipse(hi_mask, (sr, sh - sr - 1), (sr, sr), 90, 0, 90, 255, -1)

    # Downscale with INTER_AREA for proper anti-aliasing.
    mask = cv2.resize(hi_mask, (w, h), interpolation=cv2.INTER_AREA)
    del hi_mask

    # Blend the mask into the alpha channel.
    bgra = bgra.copy()
    bgra[:, :, 3] = np.minimum(bgra[:, :, 3], mask)
    return bgra


def crop_card(bgra, contour, pad: int, deskew: bool, max_rotation: float,
              corner_radius: int = 0, erase_bg: bool = True, fuzz: float = 6.0):
    """Return the cropped (and optionally deskewed) card, BGRA."""
    h, w = bgra.shape[:2]
    r = corner_radius

    if deskew:
        rect = cv2.minAreaRect(contour)
        angle = rect[2]
        # Normalise minAreaRect angle into [-45, 45].
        if angle < -45:
            angle += 90
        if abs(angle) <= max_rotation and abs(angle) > 0.3:
            box = order_quad(cv2.boxPoints(rect))
            (tl, tr, br, bl) = box
            out_w = int(round(max(np.linalg.norm(tr - tl), np.linalg.norm(br - bl))))
            out_h = int(round(max(np.linalg.norm(bl - tl), np.linalg.norm(br - tr))))
            if out_w > 4 and out_h > 4:
                dst = np.array([[0, 0], [out_w - 1, 0], [out_w - 1, out_h - 1], [0, out_h - 1]], np.float32)
                m = cv2.getPerspectiveTransform(box, dst)
                warped = cv2.warpPerspective(bgra, m, (out_w, out_h), flags=cv2.INTER_CUBIC,
                                             borderMode=cv2.BORDER_CONSTANT, borderValue=(0, 0, 0, 0))
                if erase_bg or r > 0:
                    warped = apply_rounded_mask(warped, r)
                return warped

    # Axis-aligned bounding box of the detected card contour.
    x, y, bw, bh = cv2.boundingRect(contour)
    x0 = max(0, x - pad)
    y0 = max(0, y - pad)
    x1 = min(w, x + bw + pad)
    y1 = min(h, y + bh + pad)
    cropped = bgra[y0:y1, x0:x1].copy()

    # Apply the rounded-rect mask: makes corners transparent AND removes any
    # scanner-bed background that falls outside the card's rounded shape.
    if erase_bg or r > 0:
        cropped = apply_rounded_mask(cropped, r)

    return cropped


def fuzz_trim(bgra, fuzz: float):
    """Simple inward trim for full-bleed decks (no card border to preserve)."""
    mask = foreground_mask(bgra, fuzz)
    ys, xs = np.where(mask > 0)
    if len(xs) == 0:
        return bgra
    return bgra[ys.min():ys.max() + 1, xs.min():xs.max() + 1]


def normalize_size(bgra, target_w: int, target_h: int):
    """Scale to fit within target box (no crop) and centre on a transparent canvas."""
    h, w = bgra.shape[:2]
    scale = min(target_w / w, target_h / h)
    new_w, new_h = max(1, int(round(w * scale))), max(1, int(round(h * scale)))
    resized = cv2.resize(bgra, (new_w, new_h), interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC)
    canvas = np.zeros((target_h, target_w, 4), dtype=np.uint8)
    ox, oy = (target_w - new_w) // 2, (target_h - new_h) // 2
    canvas[oy:oy + new_h, ox:ox + new_w] = resized
    return canvas


def process_one(bgra, args):
    """Returns (cleaned_bgra, ok: bool, info: str). Falls back to the original on detection failure."""
    if args.mode == "trim":
        cleaned = fuzz_trim(bgra, args.fuzz)
        ok = cleaned.shape[:2] != bgra.shape[:2]
        if args.size:
            cleaned = normalize_size(cleaned, args.size[0], args.size[1])
        return cleaned, ok, ""
    else:
        mask = foreground_mask(bgra, args.fuzz)
        contour = largest_card_contour(mask)
        if contour is None:
            return bgra, False, ""
        else:
            # Estimate corner radius from the raw contour BEFORE hulling,
            # since the convex hull removes the rounded-corner geometry.
            if args.corner_radius is not None:
                corner_radius = args.corner_radius
                radius_src = "manual"
            else:
                _, _, bw, bh = cv2.boundingRect(contour)
                detected_r = estimate_corner_radius(contour, bw, bh)
                # The contour method is unreliable for small values — the
                # morphological close distorts the contour near corners. Only
                # trust it if it's at least 1.5% of the shorter side.
                min_plausible = int(min(bw, bh) * 0.015)
                if detected_r >= max(3, min_plausible):
                    corner_radius = detected_r
                    radius_src = "contour"
                else:
                    # Fall back to pixel-based corner colour analysis on the
                    # cropped region (not full image — which may have large borders).
                    hull = cv2.convexHull(contour)
                    x, y, cw, ch = cv2.boundingRect(hull)
                    x0 = max(0, x - args.pad)
                    y0 = max(0, y - args.pad)
                    x1 = min(bgra.shape[1], x + cw + args.pad)
                    y1 = min(bgra.shape[0], y + ch + args.pad)
                    crop_for_estimate = bgra[y0:y1, x0:x1]
                    pixel_r = estimate_corner_radius_from_pixels(crop_for_estimate)
                    if pixel_r >= 3:
                        corner_radius = pixel_r
                        radius_src = "pixels"
                    else:
                        corner_radius = 0
                        radius_src = "none"

            # Use the convex hull for a more stable bounding shape — prevents
            # irregular contour edges from causing over-cropping.
            hull = cv2.convexHull(contour)
            cleaned = crop_card(bgra, hull, args.pad, args.deskew, args.max_rotation,
                                corner_radius, erase_bg=not args.no_erase_bg, fuzz=args.fuzz)

            info = f"r={corner_radius}px ({radius_src})"
            if args.size:
                cleaned = normalize_size(cleaned, args.size[0], args.size[1])
            return cleaned, True, info



# ── Contact sheet (before / after review) ────────────────────────────────────

def thumb(bgra, height: int):
    h, w = bgra.shape[:2]
    scale = height / h
    img = cv2.resize(bgra, (max(1, int(w * scale)), height), interpolation=cv2.INTER_AREA)
    # Composite over a mid-grey so transparent padding is visible in the review sheet.
    bg = np.full((img.shape[0], img.shape[1], 3), 90, np.uint8)
    a = img[:, :, 3:4].astype(np.float32) / 255.0
    return (img[:, :, :3] * a + bg * (1 - a)).astype(np.uint8)


def build_contact_sheet(rows, out_path: Path, cols: int = 4, cell_h: int = 220):
    """rows: list of (label, orig_thumb_bgr, clean_thumb_bgr)."""
    if not rows:
        return
    pad = 8
    pairs = []
    for label, a, b in rows:
        gap = np.full((cell_h, 6, 3), 30, np.uint8)
        cell = np.hstack([a, gap, b])
        pairs.append((label, cell))

    cell_w = max(c.shape[1] for _, c in pairs)
    label_h = 22
    canvas_cols = min(cols, len(pairs))
    canvas_rows = (len(pairs) + canvas_cols - 1) // canvas_cols
    W = canvas_cols * (cell_w + pad) + pad
    H = canvas_rows * (cell_h + label_h + pad) + pad
    sheet = np.full((H, W, 3), 20, np.uint8)

    for i, (label, cell) in enumerate(pairs):
        r, c = divmod(i, canvas_cols)
        x = pad + c * (cell_w + pad)
        y = pad + r * (cell_h + label_h + pad)
        sheet[y:y + cell_h, x:x + cell.shape[1]] = cell
        cv2.putText(sheet, label, (x, y + cell_h + 16), cv2.FONT_HERSHEY_SIMPLEX,
                    0.45, (210, 210, 210), 1, cv2.LINE_AA)

    out_path.parent.mkdir(parents=True, exist_ok=True)
    cv2.imwrite(str(out_path), sheet)
    print(f"  contact sheet -> {out_path}")


# ── Driver ───────────────────────────────────────────────────────────────────

def gather_decks(input_dir: Path, per_deck: bool):
    """Yield (source_dir, relative_label) pairs to process."""
    if per_deck:
        for sub in sorted(p for p in input_dir.iterdir() if p.is_dir()):
            yield sub, sub.name
    else:
        yield input_dir, input_dir.name


def detect_deck_corner_radius(pngs: list, args) -> int | None:
    """
    Sample a few cards from the deck to determine a consistent corner radius.
    Returns the median detected radius, or None if detection is inconclusive.
    This ensures all cards in a deck get the same rounding applied.
    """
    if args.corner_radius is not None:
        return None  # User specified manually, don't override.

    sample_size = min(8, len(pngs))
    # Sample evenly across the deck to account for variation.
    indices = [int(i * len(pngs) / sample_size) for i in range(sample_size)]
    radii = []

    for idx in indices:
        bgra = load_bgra(pngs[idx])
        if bgra is None:
            continue
        mask = foreground_mask(bgra, args.fuzz)
        contour = largest_card_contour(mask)
        if contour is None:
            continue
        _, _, bw, bh = cv2.boundingRect(contour)
        # Try contour-based first.
        r = estimate_corner_radius(contour, bw, bh)
        min_plausible = int(min(bw, bh) * 0.015)
        if r < max(3, min_plausible):
            # Fall back to pixel-based on the cropped region.
            hull = cv2.convexHull(contour)
            x, y, cw, ch = cv2.boundingRect(hull)
            crop = bgra[y:y+ch, x:x+cw]
            r = estimate_corner_radius_from_pixels(crop)
        if r >= 3:
            radii.append(r)
        del bgra

    if not radii:
        return None

    # Exclude outliers: compute the median first, then discard any values that
    # differ from it by more than 30% (physical cards in a deck should have
    # nearly identical corner radii — large deviations are detection errors).
    preliminary_median = np.median(radii)
    tolerance = max(5, preliminary_median * 0.15)
    filtered = [r for r in radii if abs(r - preliminary_median) <= tolerance]

    if not filtered:
        return None

    median_r = int(np.median(filtered))
    return median_r if median_r >= 3 else None


def process_deck(src_dir: Path, out_dir: Path, args) -> tuple[int, int]:
    pngs = sorted(src_dir.glob(args.pattern))
    if args.no_back:
        pngs = [p for p in pngs if p.name.lower() != "card_back.png"]
    if not pngs:
        print(f"  (no images matching '{args.pattern}' in {src_dir})")
        return 0, 0

    if not args.dry_run:
        out_dir.mkdir(parents=True, exist_ok=True)

    # Pre-scan: determine a consistent corner radius for the whole deck.
    deck_radius = detect_deck_corner_radius(pngs, args)
    if deck_radius is not None:
        print(f"  deck corner radius: {deck_radius}px (from sampling)")
        # Override the args for this deck so all cards use the same radius.
        args = argparse.Namespace(**vars(args))
        args.corner_radius = deck_radius

    review_rows, processed, failures = [], 0, 0
    cell_h = 220  # thumbnail height for contact sheet
    for png in pngs:
        try:
            bgra = load_bgra(png)
            if bgra is None:
                print(f"  \033[31m✗ {png.name}: could not read file (corrupt or unsupported format)\033[0m")
                failures += 1
                continue
            print(f"  processing {png.name} ({bgra.shape[1]}×{bgra.shape[0]})…", end="", flush=True)
            cleaned, ok, info = process_one(bgra, args)
            if not ok:
                failures += 1
                print(f"\r  \033[33m? {png.name}: card edge not found — copied as-is (review needed)\033[0m")
            else:
                print(f"\r  \033[32m✓ {png.name}\033[0m {info}" + " " * 20)
            if not args.dry_run:
                success = cv2.imwrite(str(out_dir / png.name), cleaned)
                if not success:
                    print(f"  \033[31m✗ {png.name}: failed to write output file to {out_dir / png.name}\033[0m")
                    failures += 1
            # Store only small thumbnails for the contact sheet — not full-res images.
            if len(review_rows) < args.review_limit:
                orig_thumb = thumb(bgra, cell_h)
                clean_thumb = thumb(cleaned, cell_h)
                review_rows.append((png.name + ("" if ok else "  [!]"), orig_thumb, clean_thumb))
                del orig_thumb, clean_thumb
            # Release full-resolution images and collect garbage every card.
            del bgra, cleaned
            gc.collect()
            processed += 1
        except MemoryError:
            print(f"\n  \033[31m✗ {png.name}: OUT OF MEMORY — image too large or system low on RAM\033[0m")
            print(f"    Image path: {png}")
            print(f"    Try closing other applications or processing fewer cards at once.")
            failures += 1
            gc.collect()
        except Exception as e:
            print(f"\n  \033[31m✗ {png.name}: unexpected error — {type(e).__name__}: {e}\033[0m")
            traceback.print_exc(file=sys.stderr)
            failures += 1
            gc.collect()

    sheet_path = (out_dir if not args.dry_run else src_dir.parent / "_review") / f"_review/{src_dir.name}_contact_sheet.png"
    try:
        build_contact_sheet(review_rows, sheet_path, cols=args.contact_cols)
    except Exception as e:
        print(f"  \033[33m⚠ Could not generate contact sheet: {type(e).__name__}: {e}\033[0m")
    return processed, failures


def parse_size(s: str):
    try:
        w, h = s.lower().split("x")
        return (int(w), int(h))
    except Exception:
        raise argparse.ArgumentTypeError("size must look like 500x860")


def main() -> int:
    ap = argparse.ArgumentParser(description="Clean scanned tarot card images (non-destructive).")
    ap.add_argument("--input", required=True, type=Path, help="Deck folder, or parent folder with --per-deck.")
    ap.add_argument("--output", required=True, type=Path, help="Destination (must NOT be the input).")
    ap.add_argument("--per-deck", action="store_true", help="Treat --input as a parent of deck subfolders.")
    ap.add_argument("--pattern", default="Card_*.png", help="Glob for card files (default Card_*.png).")
    ap.add_argument("--no-back", action="store_true", help="Skip Card_Back.png.")
    ap.add_argument("--mode", choices=["contour", "trim"], default="contour")
    ap.add_argument("--fuzz", type=float, default=6.0, help="Background tolerance %% (default 6).")
    ap.add_argument("--pad", type=int, default=0, help="Px to keep around the card; negative shaves inward.")
    ap.add_argument("--deskew", action="store_true", help="Straighten rotated scans.")
    ap.add_argument("--max-rotation", type=float, default=8.0, help="Max deskew angle in degrees.")
    ap.add_argument("--corner-radius", type=int, default=None,
                    help="Rounded corner radius in px (auto-detected if omitted, 0 to disable).")
    ap.add_argument("--no-erase-bg", action="store_true",
                    help="Skip background erasure (keep opaque background in the output).")
    ap.add_argument("--size", type=parse_size, default=None, help="Uniform output size, e.g. 500x860.")
    ap.add_argument("--overwrite", action="store_true", help="Allow a non-empty output folder.")
    ap.add_argument("--dry-run", action="store_true", help="Only write contact sheets, no card files.")
    ap.add_argument("--review-limit", type=int, default=80, help="Max cards per contact sheet.")
    ap.add_argument("--contact-cols", type=int, default=4, help="Contact-sheet columns.")
    args = ap.parse_args()

    input_dir = args.input.resolve()
    output_dir = args.output.resolve()

    if not input_dir.is_dir():
        print(f"\033[31mError: Input folder not found: {input_dir}\033[0m")
        return 1

    # Hard safety: never write into the source tree.
    if output_dir == input_dir or input_dir in output_dir.parents:
        print("\033[31mError: --output must be a separate folder, not the input (originals are never modified).\033[0m")
        return 1
    if not args.dry_run and output_dir.exists() and any(output_dir.iterdir()) and not args.overwrite:
        print(f"\033[31mError: Output folder {output_dir} is not empty. Use --overwrite to proceed.\033[0m")
        return 1

    print(f"Input:  {input_dir}")
    print(f"Output: {output_dir}")
    print(f"Mode: {args.mode} | Fuzz: {args.fuzz}% | Pad: {args.pad}px | "
          f"Deskew: {'yes' if args.deskew else 'no'} | Erase BG: {'no' if args.no_erase_bg else 'yes'}")
    if args.corner_radius is not None:
        print(f"Corner radius: {args.corner_radius}px" + (" (disabled)" if args.corner_radius == 0 else ""))
    else:
        print("Corner radius: auto-detect")
    if args.size:
        print(f"Normalise to: {args.size[0]}×{args.size[1]}")
    print()

    total, total_fail = 0, 0
    for src, label in gather_decks(input_dir, args.per_deck):
        dst = output_dir / label if args.per_deck else output_dir
        print(f"━━━ Deck '{label}' ━━━")
        try:
            n, f = process_deck(src, dst, args)
            total += n
            total_fail += f
        except MemoryError:
            print(f"\033[31m  ✗ FATAL: Out of memory while processing deck '{label}'.\033[0m")
            print(f"    Processed {total} card(s) before failure.")
            print(f"    Try running with fewer cards or closing other applications.")
            return 1
        except Exception as e:
            print(f"\033[31m  ✗ FATAL error in deck '{label}': {type(e).__name__}: {e}\033[0m")
            traceback.print_exc(file=sys.stderr)
            total_fail += 1

    print()
    if total_fail > 0:
        print(f"\033[33m⚠ Done. Processed {total} image(s); {total_fail} had issues (see above).\033[0m")
    else:
        print(f"\033[32m✓ Done. Processed {total} image(s) successfully.\033[0m")
    print("Originals were not modified. Review the contact sheet(s) before using the output.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
