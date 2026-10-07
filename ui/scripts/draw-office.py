#!/usr/bin/env python3
"""Draw the original Holder office tiles and tilemap.

Pixel art in the same ink-and-paper family as the clerk. Nothing here is
copied from the Smallville / generative_agents tiles.
"""

from __future__ import annotations

import json
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1] / "src" / "assets" / "floor"
PREVIEW = Path("/tmp/grok-goal-c639799a0f7b/implementer")
OFFICE_W, HEIGHT, TILE = 40, 24, 32
WIDTH = OFFICE_W + 8
COLUMNS = 8

INK = (42, 38, 34, 255)
CLEAR = (0, 0, 0, 0)
PLASTER = (244, 236, 224, 255)
PLASTER_ALT = (232, 220, 204, 255)
CAP = (74, 58, 46, 255)
CAP_HI = (132, 106, 82, 255)
BOARD = (108, 82, 62, 255)
BOARD_HI = (176, 144, 112, 255)


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


def plaster(im: Image.Image) -> None:
    rect(im, 0, 0, TILE, TILE, PLASTER)
    for y in range(TILE):
        for x in range(TILE):
            if (x * 7 + y * 13) % 29 == 0:
                im.putpixel((x, y), PLASTER_ALT)


def wall(outer: set[str]) -> Image.Image:
    im = blank()
    plaster(im)
    opposite = {"n": "s", "s": "n", "e": "w", "w": "e"}
    inner = {opposite[side] for side in outer}
    if "s" in inner:
        rect(im, 0, 27, 32, 5, BOARD)
        rect(im, 0, 27, 32, 1, BOARD_HI)
    if "n" in inner:
        rect(im, 0, 0, 32, 5, BOARD)
        rect(im, 0, 4, 32, 1, BOARD_HI)
    if "e" in inner:
        rect(im, 27, 0, 5, 32, BOARD)
        rect(im, 27, 0, 1, 32, BOARD_HI)
    if "w" in inner:
        rect(im, 0, 0, 5, 32, BOARD)
        rect(im, 4, 0, 1, 32, BOARD_HI)
    if "n" in outer:
        rect(im, 0, 0, 32, 5, CAP)
        rect(im, 0, 5, 32, 1, CAP_HI)
    if "s" in outer:
        rect(im, 0, 26, 32, 6, CAP)
        rect(im, 0, 26, 32, 1, CAP_HI)
    if "w" in outer:
        rect(im, 0, 0, 6, 32, CAP)
        rect(im, 5, 0, 1, 32, CAP_HI)
    if "e" in outer:
        rect(im, 26, 0, 6, 32, CAP)
        rect(im, 26, 0, 1, 32, CAP_HI)
    return im


def window_on(outer: set[str]) -> Image.Image:
    im = wall(outer)
    framed(im, 6, 8, 20, 12, (150, 210, 222, 255))
    rect(im, 15, 9, 2, 10, INK)
    rect(im, 7, 13, 18, 2, INK)
    rect(im, 8, 10, 6, 2, (220, 244, 248, 255))
    rect(im, 18, 10, 6, 2, (220, 244, 248, 255))
    rect(im, 5, 20, 22, 2, (132, 98, 70, 255))
    rect(im, 5, 20, 22, 1, (186, 150, 112, 255))
    return im


def wood(variant: int, knot: bool = False) -> Image.Image:
    """Vertical oak boards. Board edges stay on x % 8 so neighboring tiles connect."""
    im = blank()
    boards = [(214, 168, 104), (228, 184, 118), (200, 152, 92), (222, 176, 112)]
    gap = (118, 78, 44, 255)
    hi = (244, 214, 168, 255)
    joint = (102, 68, 38, 255)
    for y in range(TILE):
        for x in range(TILE):
            board = x // 8
            red, green, blue = boards[board % 4]
            if x % 8 == 7:
                im.putpixel((x, y), gap)
            elif x % 8 == 0:
                im.putpixel((x, y), hi)
            else:
                grain = -10 if (y + board * 3) % 6 == 0 else ((x * 3 + y * 5) % 5) - 2
                im.putpixel((x, y), (red + grain, green + grain, blue + grain, 255))
    for board in range(4):
        yj = (4 + board * 9 + variant * 6) % 28 + 2
        for x in range(board * 8 + 1, board * 8 + 7):
            im.putpixel((x, yj), joint)
            if yj + 1 < TILE:
                im.putpixel((x, yj + 1), hi)
    if knot:
        rect(im, 11, 14, 4, 4, (132, 86, 48, 255))
        im.putpixel((12, 15), (86, 52, 30, 255))
        im.putpixel((13, 15), (86, 52, 30, 255))
        im.putpixel((12, 16), (168, 120, 72, 255))
    return im


def carpet() -> Image.Image:
    im = blank()
    weave = (84, 116, 102, 255)
    dark = (68, 98, 86, 255)
    light = (112, 146, 128, 255)
    for y in range(TILE):
        for x in range(TILE):
            if (x + y * 2) % 8 == 0:
                im.putpixel((x, y), light)
            elif ((x // 2) + (y // 2)) % 2 == 0:
                im.putpixel((x, y), weave)
            else:
                im.putpixel((x, y), dark)
    return im


def rug_color(x: int, y: int) -> tuple[int, int, int, int]:
    field = (176, 84, 68, 255)
    motif = (146, 58, 48, 255)
    edge = (214, 176, 146, 255)
    local_x, local_y = x % 16, y % 16
    distance = abs(local_x - 8) + abs(local_y - 8)
    if distance <= 2:
        return motif
    if distance == 3:
        return edge
    return field


def rug(sides: set[str]) -> Image.Image:
    im = blank()
    for y in range(TILE):
        for x in range(TILE):
            im.putpixel((x, y), rug_color(x, y))
    cream = (236, 220, 184, 255)
    line = (110, 52, 44, 255)
    if "n" in sides:
        rect(im, 0, 0, 32, 3, cream)
        rect(im, 0, 3, 32, 1, line)
    if "s" in sides:
        rect(im, 0, 29, 32, 3, cream)
        rect(im, 0, 28, 32, 1, line)
    if "w" in sides:
        rect(im, 0, 0, 3, 32, cream)
        rect(im, 3, 0, 1, 32, line)
    if "e" in sides:
        rect(im, 29, 0, 3, 32, cream)
        rect(im, 28, 0, 1, 32, line)
    return im


def chair() -> Image.Image:
    im = blank()
    rect(im, 9, 27, 2, 5, INK)
    rect(im, 21, 27, 2, 5, INK)
    rect(im, 12, 29, 2, 3, INK)
    rect(im, 18, 29, 2, 3, INK)
    framed(im, 7, 20, 18, 8, (124, 142, 172, 255))
    rect(im, 8, 21, 16, 2, (176, 190, 210, 255))
    framed(im, 8, 8, 16, 12, (58, 74, 104, 255))
    rect(im, 10, 10, 2, 8, (36, 48, 70, 255))
    rect(im, 20, 10, 2, 8, (36, 48, 70, 255))
    return im


def desk(mug_on_right: bool, screen: tuple[int, int, int, int]) -> Image.Image:
    im = blank()
    rect(im, 3, 26, 3, 6, (96, 62, 40, 255))
    rect(im, 26, 26, 3, 6, (96, 62, 40, 255))
    rect(im, 2, 22, 28, 6, (128, 82, 48, 255))
    rect(im, 2, 22, 28, 1, (176, 122, 76, 255))
    framed(im, 1, 14, 30, 9, (198, 144, 90, 255))
    rect(im, 2, 15, 28, 2, (228, 184, 128, 255))
    rect(im, 8, 18, 12, 3, (54, 50, 46, 255))
    for index in range(5):
        im.putpixel((9 + index * 2, 19), (186, 182, 176, 255))
    mug_x = 23 if mug_on_right else 3
    framed(im, mug_x, 16, 5, 5, (186, 84, 68, 255))
    framed(im, 9, 0, 14, 12, (36, 40, 44, 255))
    rect(im, 11, 2, 10, 8, screen)
    rect(im, 12, 3, 4, 3, (232, 248, 252, 255))
    rect(im, 14, 12, 4, 2, (36, 40, 44, 255))
    return im


def plant(tall: bool) -> Image.Image:
    im = blank()
    if tall:
        framed(im, 14, 3, 4, 16, (48, 122, 66, 255))
        framed(im, 9, 7, 4, 12, (78, 156, 86, 255))
        framed(im, 19, 6, 4, 13, (36, 96, 54, 255))
        rect(im, 15, 6, 2, 4, (166, 214, 120, 255))
    else:
        framed(im, 6, 12, 8, 7, (40, 112, 58, 255))
        framed(im, 16, 10, 9, 8, (64, 150, 76, 255))
        framed(im, 11, 6, 8, 8, (36, 96, 52, 255))
        rect(im, 13, 8, 3, 3, (150, 198, 96, 255))
    rect(im, 10, 18, 12, 3, (198, 112, 78, 255))
    rect(im, 10, 18, 12, 1, (226, 160, 118, 255))
    framed(im, 11, 20, 10, 10, (176, 86, 58, 255))
    rect(im, 14, 23, 4, 4, (150, 70, 48, 255))
    return im


def monitor() -> Image.Image:
    im = blank()
    rect(im, 6, 27, 3, 4, (100, 68, 42, 255))
    rect(im, 23, 27, 3, 4, (100, 68, 42, 255))
    framed(im, 4, 21, 24, 7, (176, 124, 78, 255))
    rect(im, 5, 22, 22, 2, (214, 168, 112, 255))
    rect(im, 14, 18, 4, 3, INK)
    framed(im, 6, 3, 20, 15, (32, 36, 40, 255))
    rect(im, 8, 5, 16, 11, (116, 206, 164, 255))
    rect(im, 10, 7, 9, 1, (226, 255, 236, 255))
    rect(im, 10, 9, 12, 1, (226, 255, 236, 255))
    rect(im, 10, 11, 7, 1, (226, 255, 236, 255))
    return im


def shelf() -> Image.Image:
    im = blank()
    framed(im, 3, 3, 26, 26, (154, 114, 74, 255))
    rect(im, 4, 4, 24, 24, (186, 146, 98, 255))
    for y in (11, 18, 25):
        rect(im, 3, y, 26, 2, (112, 78, 48, 255))
    books = [
        (64, 96, 150, 255),
        (176, 72, 60, 255),
        (64, 132, 78, 255),
        (196, 156, 64, 255),
        (112, 78, 140, 255),
    ]
    x = 6
    for color in books:
        rect(im, x, 6, 3, 5, color)
        x += 4
    x = 7
    for color in books[:4]:
        rect(im, x, 13, 3, 5, color)
        x += 5
    framed(im, 8, 20, 8, 5, (210, 176, 122, 255))
    rect(im, 18, 20, 6, 5, (90, 122, 96, 255))
    return im


def lamp() -> Image.Image:
    im = blank()
    framed(im, 11, 26, 10, 5, (96, 74, 52, 255))
    rect(im, 15, 14, 2, 12, INK)
    framed(im, 8, 5, 16, 9, (244, 206, 102, 255))
    rect(im, 10, 7, 12, 3, (255, 236, 176, 255))
    im.putpixel((22, 14), INK)
    im.putpixel((22, 15), INK)
    im.putpixel((22, 16), (244, 206, 102, 255))
    return im


def whiteboard() -> Image.Image:
    im = blank()
    framed(im, 2, 3, 28, 22, (248, 246, 240, 255))
    rect(im, 4, 24, 24, 3, (72, 68, 64, 255))
    rect(im, 6, 7, 16, 1, (64, 112, 176, 255))
    rect(im, 6, 10, 20, 1, (176, 72, 64, 255))
    rect(im, 6, 13, 12, 1, (64, 112, 176, 255))
    rect(im, 6, 16, 18, 1, (88, 88, 88, 255))
    rect(im, 7, 25, 5, 2, (64, 112, 176, 255))
    rect(im, 14, 25, 5, 2, (176, 72, 64, 255))
    return im


def stone(variant: int) -> Image.Image:
    im = blank()
    gap = (118, 114, 108, 255)
    light = (176, 172, 164, 255)
    dark = (150, 146, 138, 255)
    for y in range(TILE):
        for x in range(TILE):
            if x % 16 == 0 or y % 16 == 0:
                im.putpixel((x, y), gap)
            elif (x // 16 + y // 16 + variant) % 2 == 0:
                im.putpixel((x, y), light)
            else:
                im.putpixel((x, y), dark)
    return im


def railing_tile(sides: set[str]) -> Image.Image:
    im = stone(0)
    rail = (86, 94, 102, 255)
    hi = (186, 196, 204, 255)
    if "n" in sides:
        rect(im, 0, 3, 32, 4, rail)
        rect(im, 0, 3, 32, 1, hi)
        for x in (6, 16, 26):
            rect(im, x, 0, 2, 8, rail)
    if "s" in sides:
        rect(im, 0, 25, 32, 4, rail)
        rect(im, 0, 25, 32, 1, hi)
        for x in (6, 16, 26):
            rect(im, x, 24, 2, 8, rail)
    if "e" in sides:
        rect(im, 25, 0, 4, 32, rail)
        rect(im, 25, 0, 1, 32, hi)
        for y in (6, 16, 26):
            rect(im, 22, y, 8, 2, rail)
    return im


def coffee_machine(mug_on_right: bool) -> Image.Image:
    im = blank()
    rect(im, 4, 22, 24, 8, (96, 64, 44, 255))
    rect(im, 4, 22, 24, 2, (150, 104, 70, 255))
    framed(im, 7, 6, 18, 16, (58, 62, 68, 255))
    rect(im, 9, 8, 14, 5, (28, 30, 34, 255))
    rect(im, 10, 9, 6, 2, (120, 210, 196, 255))
    rect(im, 18, 9, 3, 2, (196, 64, 52, 255))
    mug_x = 18 if mug_on_right else 8
    framed(im, mug_x, 16, 6, 5, (236, 232, 224, 255))
    rect(im, mug_x + 1, 17, 4, 2, (92, 56, 40, 255))
    im.putpixel((mug_x + 2, 4), (214, 214, 210, 255))
    im.putpixel((mug_x + 3, 2), (230, 230, 226, 255))
    im.putpixel((mug_x + 2, 3), (200, 200, 196, 255))
    return im


def coffee_counter(cup_on_right: bool) -> Image.Image:
    im = blank()
    rect(im, 2, 20, 28, 8, (198, 144, 90, 255))
    rect(im, 2, 20, 28, 2, (228, 184, 128, 255))
    rect(im, 4, 26, 3, 6, (96, 62, 40, 255))
    rect(im, 25, 26, 3, 6, (96, 62, 40, 255))
    cup_x = 18 if cup_on_right else 6
    framed(im, cup_x, 14, 7, 6, (236, 232, 224, 255))
    rect(im, cup_x + 1, 15, 5, 2, (92, 56, 40, 255))
    framed(im, 12, 8, 8, 10, (176, 86, 58, 255))
    rect(im, 14, 10, 4, 4, (120, 64, 44, 255))
    rect(im, 15, 6, 2, 3, (96, 64, 44, 255))
    return im


def cabinet() -> Image.Image:
    im = blank()
    framed(im, 6, 4, 20, 24, (154, 116, 74, 255))
    rect(im, 7, 12, 18, 1, INK)
    rect(im, 7, 19, 18, 1, INK)
    for y in (8, 15, 22):
        rect(im, 14, y, 4, 2, (226, 196, 146, 255))
    return im


def build_tiles() -> tuple[Image.Image, list[tuple[str, str]]]:
    specs: list[tuple[str, str, Image.Image]] = [
        ("floor_a", "floor", wood(0)),
        ("floor_b", "floor", wood(1)),
        ("floor_c", "floor", wood(2, knot=True)),
        ("floor_d", "floor", wood(3)),
        ("carpet", "floor", carpet()),
        ("wall_n", "wall", wall({"n"})),
        ("wall_s", "wall", wall({"s"})),
        ("wall_w", "wall", wall({"w"})),
        ("wall_e", "wall", wall({"e"})),
        ("corner_nw", "wall", wall({"n", "w"})),
        ("corner_ne", "wall", wall({"n", "e"})),
        ("corner_sw", "wall", wall({"s", "w"})),
        ("corner_se", "wall", wall({"s", "e"})),
        ("window_n", "window", window_on({"n"})),
        ("window_s", "window", window_on({"s"})),
        ("desk", "desk", desk(True, (126, 198, 224, 255))),
        ("desk_b", "desk", desk(False, (244, 196, 120, 255))),
        ("chair", "chair", chair()),
        ("plant", "plant", plant(False)),
        ("plant_b", "plant", plant(True)),
        ("monitor", "monitor", monitor()),
        ("shelf", "shelf", shelf()),
        ("lamp", "lamp", lamp()),
        ("whiteboard", "whiteboard", whiteboard()),
        ("cabinet", "cabinet", cabinet()),
        ("stone", "floor", stone(0)),
        ("stone_b", "floor", stone(1)),
        ("railing_n", "railing", railing_tile({"n"})),
        ("railing_s", "railing", railing_tile({"s"})),
        ("railing_e", "railing", railing_tile({"e"})),
        ("railing_ne", "railing", railing_tile({"n", "e"})),
        ("railing_se", "railing", railing_tile({"s", "e"})),
        ("coffee", "coffee", coffee_machine(False)),
        ("coffee_b", "coffee", coffee_machine(True)),
        ("coffee_counter", "coffee", coffee_counter(False)),
        ("coffee_counter_b", "coffee", coffee_counter(True)),
    ]
    for sides in (
        set(),
        {"n"},
        {"s"},
        {"e"},
        {"w"},
        {"n", "w"},
        {"n", "e"},
        {"s", "w"},
        {"s", "e"},
    ):
        suffix = "".join(sorted(sides)) or "fill"
        specs.append((f"rug_{suffix}", "rug", rug(sides)))
    rows = (len(specs) + COLUMNS - 1) // COLUMNS
    sheet = Image.new("RGBA", (COLUMNS * TILE, rows * TILE), CLEAR)
    meta: list[tuple[str, str]] = []
    for index, (name, kind, image) in enumerate(specs):
        sheet.paste(image, ((index % COLUMNS) * TILE, (index // COLUMNS) * TILE))
        meta.append((name, kind))
    return sheet, meta


def gid_of(meta: list[tuple[str, str]]) -> dict[str, int]:
    return {name: index + 1 for index, (name, _kind) in enumerate(meta)}


def rug_name(col: int, row: int) -> str | None:
    left, right, top, bottom = 12, 27, 10, 13
    if not (left <= col <= right and top <= row <= bottom):
        return None
    sides: set[str] = set()
    if row == top:
        sides.add("n")
    if row == bottom:
        sides.add("s")
    if col == left:
        sides.add("w")
    if col == right:
        sides.add("e")
    return "rug_" + ("".join(sorted(sides)) or "fill")


def balcony_name(col: int, row: int) -> str:
    east = col == WIDTH - 1
    north = row == 0
    south = row == HEIGHT - 1
    if north and east:
        return "railing_ne"
    if south and east:
        return "railing_se"
    if north:
        return "railing_n"
    if south:
        return "railing_s"
    if east:
        return "railing_e"
    return "stone" if (col + row) % 2 == 0 else "stone_b"


def ground_name(col: int, row: int) -> str:
    if col >= OFFICE_W:
        return balcony_name(col, row)
    if row == 0 and col == 0:
        return "corner_nw"
    if row == 0 and col == OFFICE_W - 1:
        return "corner_ne"
    if row == HEIGHT - 1 and col == 0:
        return "corner_sw"
    if row == HEIGHT - 1 and col == OFFICE_W - 1:
        return "corner_se"
    if row == 0:
        return "window_n" if col % 3 == 1 else "wall_n"
    if row == HEIGHT - 1:
        return "window_s" if col in {8, 16, 24, 32} else "wall_s"
    if col == 0:
        return "wall_w"
    if col == OFFICE_W - 1:
        return "floor_a"
    rug_tile = rug_name(col, row)
    if rug_tile:
        return rug_tile
    if row in {8, 15}:
        return "carpet"
    return ("floor_a", "floor_b", "floor_c", "floor_d")[(col * 3 + row * 5) % 4]


def stand_cells(spots: list[dict[str, object]]) -> dict[tuple[int, int], str]:
    cells: dict[tuple[int, int], str] = {}
    for spot in spots:
        name = str(spot.get("name") or "")
        col = int(spot["x"]) // TILE
        row = (int(spot["y"]) - 1) // TILE
        cells[(col, row)] = name
    return cells


def place(props: list[int], gids: dict[str, int], col: int, row: int, name: str) -> None:
    if not (0 <= col < WIDTH and 0 <= row < HEIGHT):
        raise SystemExit(f"{name} falls outside the map at {col},{row}")
    index = row * WIDTH + col
    if props[index] != 0:
        raise SystemExit(f"prop overlap at {col},{row}")
    props[index] = gids[name]


def spot_at(name: str, col: int, row: int) -> dict[str, object]:
    return {
        "name": name,
        "type": "",
        "x": col * TILE + TILE // 2,
        "y": (row + 1) * TILE,
        "width": 0,
        "height": 0,
        "point": True,
        "visible": True,
    }


def with_places(spots: list[dict[str, object]]) -> list[dict[str, object]]:
    kept = [
        item
        for item in spots
        if not str(item.get("name") or "").startswith(("coffee-", "balcony-"))
    ]
    extra: list[dict[str, object]] = []
    ring: list[tuple[int, int]] = []
    for row in range(16, 20):
        for col in range(36, 40):
            if 37 <= col <= 38 and 17 <= row <= 18:
                continue
            ring.append((col, row))
    for index, (col, row) in enumerate(ring, start=1):
        extra.append(spot_at(f"coffee-{index}", col, row))
    for index, row in enumerate((2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 3, 7), start=1):
        extra.append(spot_at(f"balcony-{index}", 45, row))
    return kept + extra


def build_map(meta: list[tuple[str, str]]) -> dict[str, object]:
    gids = gid_of(meta)
    existing = json.loads((ROOT / "office.json").read_text())
    spots = with_places(next(layer["objects"] for layer in existing["layers"] if layer["name"] == "spots"))
    stands = stand_cells(spots)
    ground = [gids[ground_name(col, row)] for row in range(HEIGHT) for col in range(WIDTH)]
    props = [0] * (WIDTH * HEIGHT)

    desk_cols = sorted({col for (col, row), name in stands.items() if name.startswith("desk-")})
    for index, col in enumerate(desk_cols):
        for _name, row in ((name, cell_row) for (cell_col, cell_row), name in stands.items() if cell_col == col):
            place(props, gids, col, row - 1, "chair")
            place(props, gids, col, row + 1, "desk" if index % 2 == 0 else "desk_b")
            place(props, gids, col + 1, row, "plant" if index % 2 == 0 else "plant_b")
            if col > 1:
                place(props, gids, col - 1, row, "monitor" if index % 2 == 0 else "lamp")

    for col in range(2, OFFICE_W - 2, 3):
        place(props, gids, col, 1, "shelf")
        place(props, gids, col, 22, "shelf")
    for col, row in ((11, 9), (28, 9), (11, 14), (28, 14)):
        place(props, gids, col, row, "lamp")
    for row in (10, 11, 12):
        place(props, gids, 38, row, "whiteboard")
    place(props, gids, 2, 21, "whiteboard")
    place(props, gids, 3, 21, "whiteboard")
    for col, row, name in (
        (2, 2, "plant"),
        (37, 2, "plant_b"),
        (37, 21, "plant"),
        (36, 20, "cabinet"),
        (37, 20, "cabinet"),
        (3, 20, "cabinet"),
        (18, 11, "desk"),
        (19, 11, "desk_b"),
        (20, 11, "desk"),
        (18, 10, "chair"),
        (19, 10, "chair"),
        (20, 10, "chair"),
        (18, 12, "chair"),
        (19, 12, "chair"),
        (20, 12, "chair"),
        (17, 11, "plant"),
        (21, 11, "plant_b"),
        (37, 17, "coffee"),
        (38, 17, "coffee_b"),
        (37, 18, "coffee_counter"),
        (38, 18, "coffee_counter_b"),
        (42, 6, "plant"),
        (43, 16, "plant_b"),
    ):
        place(props, gids, col, row, name)

    for (col, row), name in stands.items():
        index = row * WIDTH + col
        if props[index] != 0:
            raise SystemExit(f"{name} stand is covered")
        if meta[ground[index] - 1][1] not in {"floor", "rug"}:
            raise SystemExit(f"{name} does not stand on a floor tile")

    rows = (len(meta) + COLUMNS - 1) // COLUMNS
    tileset_tiles = [
        {"id": index, "properties": [{"name": "kind", "type": "string", "value": kind}]}
        for index, (_name, kind) in enumerate(meta)
    ]
    return {
        "compressionlevel": -1,
        "width": WIDTH,
        "height": HEIGHT,
        "tilewidth": TILE,
        "tileheight": TILE,
        "infinite": False,
        "orientation": "orthogonal",
        "renderorder": "right-down",
        "type": "map",
        "nextlayerid": 4,
        "nextobjectid": 25,
        "tilesets": [
            {
                "columns": COLUMNS,
                "firstgid": 1,
                "image": "office.png",
                "imageheight": rows * TILE,
                "imagewidth": COLUMNS * TILE,
                "margin": 0,
                "name": "office",
                "spacing": 0,
                "tilecount": len(meta),
                "tilewidth": TILE,
                "tileheight": TILE,
                "tiles": tileset_tiles,
            }
        ],
        "layers": [
            {
                "id": 1,
                "name": "ground",
                "type": "tilelayer",
                "visible": True,
                "opacity": 1,
                "x": 0,
                "y": 0,
                "width": WIDTH,
                "height": HEIGHT,
                "data": ground,
            },
            {
                "id": 2,
                "name": "props",
                "type": "tilelayer",
                "visible": True,
                "opacity": 1,
                "x": 0,
                "y": 0,
                "width": WIDTH,
                "height": HEIGHT,
                "data": props,
            },
            {
                "id": 3,
                "name": "spots",
                "type": "objectgroup",
                "visible": True,
                "opacity": 1,
                "x": 0,
                "y": 0,
                "objects": spots,
            },
        ],
    }


def composite(sheet: Image.Image, office: dict[str, object]) -> Image.Image:
    image = Image.new("RGBA", (WIDTH * TILE, HEIGHT * TILE), (28, 24, 22, 255))
    tilesets = office["tilesets"]
    assert isinstance(tilesets, list)
    columns = int(tilesets[0]["columns"])
    for layer in office["layers"]:
        assert isinstance(layer, dict)
        if layer["type"] != "tilelayer":
            continue
        data = layer["data"]
        assert isinstance(data, list)
        for index, gid in enumerate(data):
            if not gid:
                continue
            tile_index = int(gid) - 1
            tile = sheet.crop(
                (
                    (tile_index % columns) * TILE,
                    (tile_index // columns) * TILE,
                    (tile_index % columns) * TILE + TILE,
                    (tile_index // columns) * TILE + TILE,
                )
            )
            image.alpha_composite(tile, ((index % WIDTH) * TILE, (index // WIDTH) * TILE))
    return image


def main() -> None:
    sheet, meta = build_tiles()
    office = build_map(meta)
    ROOT.mkdir(parents=True, exist_ok=True)
    sheet.save(ROOT / "office.png")
    (ROOT / "office.json").write_text(json.dumps(office, separators=(",", ":")) + "\n")
    PREVIEW.mkdir(parents=True, exist_ok=True)
    preview = composite(sheet, office)
    preview.save(PREVIEW / "office-preview.png")
    preview.crop((0, 0, 8 * TILE, 8 * TILE)).resize((8 * TILE * 3, 8 * TILE * 3), Image.Resampling.NEAREST).save(
        PREVIEW / "desk-band.png"
    )
    preview.crop((10 * TILE, 8 * TILE, 30 * TILE, 16 * TILE)).resize((20 * TILE * 2, 8 * TILE * 2), Image.Resampling.NEAREST).save(
        PREVIEW / "center-band.png"
    )
    preview.crop((0, 15 * TILE, 10 * TILE, 24 * TILE)).resize((10 * TILE * 3, 9 * TILE * 3), Image.Resampling.NEAREST).save(
        PREVIEW / "work-band.png"
    )
    sheet.resize((sheet.width * 3, sheet.height * 3), Image.Resampling.NEAREST).save(PREVIEW / "tileset.png")
    counts: dict[str, int] = {}
    for layer in office["layers"]:
        assert isinstance(layer, dict)
        if layer["type"] != "tilelayer":
            continue
        for gid in layer["data"]:
            if gid:
                kind = meta[int(gid) - 1][1]
                counts[kind] = counts.get(kind, 0) + 1
    ground = next(layer["data"] for layer in office["layers"] if layer["name"] == "ground")
    assert isinstance(ground, list)
    tally: dict[int, int] = {}
    for gid in ground:
        tally[int(gid)] = tally.get(int(gid), 0) + 1
    top = max(tally.values())
    print(f"tiles {len(meta)} dominant {top}/{len(ground)} = {top / len(ground):.3f}")
    print("kinds", dict(sorted(counts.items())))


if __name__ == "__main__":
    main()
