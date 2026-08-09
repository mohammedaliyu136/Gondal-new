#!/usr/bin/env python3
"""
Stitch a role's frames into an animated GIF, and write its caption list.

ffmpeg and ImageMagick are not installed on this machine, so Pillow does the
work directly. That fixes the output as GIF rather than MP4; the frames are kept
alongside, so a real video can be produced later without re-walking anything.

Each frame is captioned by burning a bar across the bottom, because a GIF has no
subtitle track and a recording nobody can follow is not evidence of anything.
"""
import json
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

OUT = Path("/Users/mohammed/work/Trust Data/new gondal backend/Backend code/docs/journey-recordings")
WIDTH = 1000          # scaled down from 1280; keeps the GIF a sane size
MS_PER_FRAME = 1800   # slow enough to read the caption
BAR = 46


def font(size):
    for candidate in ("/System/Library/Fonts/Helvetica.ttc",
                      "/System/Library/Fonts/Supplemental/Arial.ttf"):
        try:
            return ImageFont.truetype(candidate, size)
        except OSError:
            continue
    return ImageFont.load_default()


def build(slug):
    meta_path = OUT / f"{slug}.captions.json"
    if not meta_path.exists():
        print(f"  no captions for {slug}, skipping")
        return None

    meta = json.loads(meta_path.read_text())
    frame_dir = OUT / "frames" / slug
    captions = {f["file"]: f for f in meta["frames"]}

    files = sorted(p for p in frame_dir.glob("*.png"))
    if not files:
        print(f"  no frames for {slug}")
        return None

    small = font(17)
    tiny = font(13)
    images = []

    for path in files:
        im = Image.open(path).convert("RGB")
        ratio = WIDTH / im.width
        im = im.resize((WIDTH, int(im.height * ratio)), Image.LANCZOS)

        canvas = Image.new("RGB", (WIDTH, im.height + BAR), "#111827")
        canvas.paste(im, (0, 0))

        entry = captions.get(path.name, {})
        n = entry.get("n", "")
        text = entry.get("caption", path.stem)
        url = (entry.get("url") or "").replace("http://127.0.0.1:8008", "")

        draw = ImageDraw.Draw(canvas)
        draw.text((12, im.height + 7), f"{n:>3}  {text}", font=small, fill="#f9fafb")
        if url:
            draw.text((12, im.height + 27), url, font=tiny, fill="#9ca3af")

        images.append(canvas)

    gif = OUT / f"{slug}.gif"
    images[0].save(
        gif, save_all=True, append_images=images[1:],
        duration=MS_PER_FRAME, loop=0, optimize=True,
    )

    # The caption list, so the recording is readable without watching it.
    lines = [f"# {meta['role']} — recording\n",
             f"![recording]({slug}.gif)\n",
             f"{len(images)} frames · {MS_PER_FRAME/1000:.1f}s each\n",
             "| # | What is on screen | URL |", "| --- | --- | --- |"]
    for f in meta["frames"]:
        url = (f.get("url") or "").replace("http://127.0.0.1:8008", "")
        lines.append(f"| {f['n']} | {f['caption']} | `{url}` |")
    (OUT / f"{slug}.md").write_text("\n".join(lines) + "\n")

    size_mb = gif.stat().st_size / 1_000_000
    print(f"  {slug}.gif — {len(images)} frames, {size_mb:.1f} MB")
    return gif


if __name__ == "__main__":
    slugs = sys.argv[1:] or [p.stem.replace(".captions", "")
                             for p in OUT.glob("*.captions.json")]
    for slug in slugs:
        build(slug)
