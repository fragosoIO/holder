#!/usr/bin/env python3
"""Draw the office dog in the same 32px ink-and-paper atlas layout as the clerk."""

from __future__ import annotations

import json
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1] / "src" / "assets" / "floor"
TILE = 32
INK = (42, 38, 34, 255)
TAN = (214, 164, 92, 255)
TAN_DARK = (176, 124, 64, 255)
EAR = (140, 86, 48, 255)
CREAM = (244, 228, 196, 255)
NOSE = (42, 38, 34, 255)
EYE = (42, 38, 34, 255)
TONGUE = (196, 92, 92, 255)
COLLAR = (92, 168, 164, 255)
CLEAR = (0, 0, 0, 0)


def blank() -> Image.Image:
    return Image.new("RGBA", (TILE, TILE), CLEAR)


def rect(im: Image.Image, x: int, y: int, w: int, h: int, color: tuple[int, int, int, int]) -> None:
    if w <= 0 or h <= 0:
        return
    for yy in range(max(0, y), min(TILE, y + h)):
        for xx in range(max(0, x), min(TILE, x + w)):
            im.putpixel((xx, yy), color)


def framed(im: Image.Image, x: int, y: int, w: int, h: int, color: tuple[int, int, int, int]) -> None:
    rect(im, x, y, w, h, INK)
    rect(im, x + 1, y + 1, max(0, w - 2), max(0, h - 2), color)


def legs(im: Image.Image, pairs: list[tuple[int, int]], step: int) -> None:
    shifts = (0, -2, 0, 2)
    for index, (x, y) in enumerate(pairs):
        shift = shifts[(step + index) % 4]
        framed(im, x + shift, y, 3, 6, TAN_DARK)


def dog_down(step: int) -> Image.Image:
    im = blank()
    bob = -1 if step % 2 else 0
    framed(im, 7, 7 + bob, 6, 8, EAR)
    framed(im, 19, 7 + bob, 6, 8, EAR)
    framed(im, 8, 9 + bob, 16, 12, TAN)
    rect(im, 11, 13 + bob, 2, 2, EYE)
    rect(im, 19, 13 + bob, 2, 2, EYE)
    rect(im, 14, 16 + bob, 4, 3, CREAM)
    rect(im, 15, 16 + bob, 2, 2, NOSE)
    if step % 2:
        rect(im, 15, 18 + bob, 2, 2, TONGUE)
    framed(im, 9, 20 + bob, 14, 7, TAN)
    rect(im, 11, 21 + bob, 10, 2, COLLAR)
    legs(im, [(10, 26), (14, 26), (18, 26), (21, 26)], step)
    return im


def dog_side(step: int) -> Image.Image:
    im = blank()
    bob = -1 if step % 2 else 0
    tail = (12, 10, 14, 12)[step] + bob
    rect(im, 22, tail, 2, 2, INK)
    rect(im, 23, tail + 1, 4, 2, TAN_DARK)
    rect(im, 26, tail + 2, 2, 2, TAN)
    framed(im, 10, 14 + bob, 14, 9, TAN)
    rect(im, 12, 16 + bob, 8, 2, CREAM)
    framed(im, 4, 12 + bob, 10, 10, TAN)
    framed(im, 6, 8 + bob, 5, 6, EAR)
    rect(im, 6, 16 + bob, 2, 2, EYE)
    framed(im, 2, 16 + bob, 5, 5, CREAM)
    rect(im, 2, 18 + bob, 2, 2, NOSE)
    if step % 2:
        rect(im, 3, 20 + bob, 2, 2, TONGUE)
    rect(im, 10, 18 + bob, 3, 2, COLLAR)
    legs(im, [(12, 22), (18, 22)], step)
    return im


def dog_up(step: int) -> Image.Image:
    im = blank()
    bob = -1 if step % 2 else 0
    tail = (8, 6, 10, 12)[step]
    rect(im, 15, tail + bob, 2, 6, TAN_DARK)
    rect(im, 14, tail + bob, 4, 2, INK)
    framed(im, 8, 10 + bob, 16, 10, TAN)
    framed(im, 9, 6 + bob, 5, 6, EAR)
    framed(im, 18, 6 + bob, 5, 6, EAR)
    framed(im, 10, 18 + bob, 12, 8, TAN)
    rect(im, 12, 19 + bob, 8, 2, COLLAR)
    legs(im, [(11, 25), (15, 25), (19, 25)], step)
    return im


def frames() -> list[tuple[str, Image.Image]]:
    order: list[tuple[str, Image.Image]] = []
    for name, drawer in (("down", dog_down), ("left", dog_side), ("up", dog_up)):
        order.append((name, drawer(0)))
        for step in range(4):
            order.append((f"{name}-walk.{step:03d}", drawer(step)))
    right = []
    for name, image in list(order):
        if name.startswith("left"):
            flipped = name.replace("left", "right", 1)
            right.append((flipped, image.transpose(Image.Transpose.FLIP_LEFT_RIGHT)))
    # Sheet order matches the clerk: one direction per row, idle then four walks.
    rows = {
        "down": [item for item in order if item[0].startswith("down")],
        "left": [item for item in order if item[0].startswith("left")],
        "right": right,
        "up": [item for item in order if item[0].startswith("up")],
    }
    sheet: list[tuple[str, Image.Image]] = []
    for direction in ("down", "left", "right", "up"):
        sheet.extend(rows[direction])
    return sheet


def main() -> None:
    drawn = frames()
    sheet = Image.new("RGBA", (5 * TILE, 4 * TILE), CLEAR)
    atlas: dict[str, object] = {"frames": {}, "meta": {"app": "holder", "image": "dog.png", "size": {"w": 160, "h": 128}, "scale": "1"}}
    frames_out: dict[str, object] = {}
    for index, (name, image) in enumerate(drawn):
        column = index % 5
        row = index // 5
        sheet.paste(image, (column * TILE, row * TILE))
        frames_out[name] = {
            "frame": {"x": column * TILE, "y": row * TILE, "w": TILE, "h": TILE},
            "rotated": False,
            "trimmed": False,
            "spriteSourceSize": {"x": 0, "y": 0, "w": TILE, "h": TILE},
            "sourceSize": {"w": TILE, "h": TILE},
        }
    atlas["frames"] = frames_out
    ROOT.mkdir(parents=True, exist_ok=True)
    sheet.save(ROOT / "dog.png")
    (ROOT / "dog.json").write_text(json.dumps(atlas, separators=(",", ":")) + "\n")
    preview = Image.new("RGBA", sheet.size, (40, 36, 32, 255))
    preview.alpha_composite(sheet)
    preview.resize((sheet.width * 4, sheet.height * 4), Image.Resampling.NEAREST).save("/tmp/dog-sheet.png")
    print(f"frames {len(drawn)}")


if __name__ == "__main__":
    main()
