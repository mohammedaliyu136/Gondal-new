#!/usr/bin/env python3
"""
Encode each role's frames into an MP4.

The frames are already on disk from the walk, so this re-encodes rather than
re-walks: nothing is driven, nothing changes in the application, and the output
is the same evidence in a scrubbable format.

Captions are burned into a bar under each frame — same as the GIF — because an
MP4 has no subtitle track here and a recording nobody can follow proves nothing.
"""
import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

OUT = Path("/Users/mohammed/work/Trust Data/new gondal backend/Backend code/docs/journey-recordings")
WIDTH = 1280
BAR = 56
SECONDS_PER_FRAME = 2.5   # slower than the GIF: you can pause, so favour reading


def font(size):
    for candidate in ("/System/Library/Fonts/Helvetica.ttc",
                      "/System/Library/Fonts/Supplemental/Arial.ttf"):
        try:
            return ImageFont.truetype(candidate, size)
        except OSError:
            continue
    return ImageFont.load_default()


def encode(slug):
    meta_path = OUT / f"{slug}.captions.json"
    if not meta_path.exists():
        print(f"  no captions for {slug}")
        return

    meta = json.loads(meta_path.read_text())
    captions = {f["file"]: f for f in meta["frames"]}
    frames = sorted((OUT / "frames" / slug).glob("*.png"))
    if not frames:
        print(f"  no frames for {slug}")
        return

    title = font(21)
    small = font(15)
    stage = Path(tempfile.mkdtemp(prefix=f"gondal-{slug}-"))

    try:
        for i, path in enumerate(frames, start=1):
            im = Image.open(path).convert("RGB")
            if im.width != WIDTH:
                im = im.resize((WIDTH, round(im.height * WIDTH / im.width)), Image.LANCZOS)

            # H.264 needs even dimensions.
            height = im.height + BAR
            height += height % 2

            canvas = Image.new("RGB", (WIDTH, height), "#111827")
            canvas.paste(im, (0, 0))

            entry = captions.get(path.name, {})
            draw = ImageDraw.Draw(canvas)
            draw.text((16, im.height + 9),
                      f"{entry.get('n', i):>3}  {entry.get('caption', path.stem)}",
                      font=title, fill="#f9fafb")
            url = (entry.get("url") or "").replace("http://127.0.0.1:8008", "")
            if url:
                draw.text((16, im.height + 34), url, font=small, fill="#9ca3af")

            canvas.save(stage / f"{i:04d}.png")

        target = OUT / f"{slug}.mp4"
        subprocess.run([
            "ffmpeg", "-y", "-loglevel", "error",
            "-framerate", f"1/{SECONDS_PER_FRAME}",
            "-i", str(stage / "%04d.png"),
            # Hold the last frame briefly so the final state is readable.
            "-vf", "format=yuv420p,tpad=stop_mode=clone:stop_duration=2",
            "-c:v", "libx264", "-preset", "slow", "-crf", "20",
            "-movflags", "+faststart",
            "-r", "30",
            str(target),
        ], check=True)

        mb = target.stat().st_size / 1_000_000
        secs = len(frames) * SECONDS_PER_FRAME + 2
        print(f"  {slug}.mp4 — {len(frames)} frames, {secs:.0f}s, {mb:.1f} MB")
    finally:
        shutil.rmtree(stage, ignore_errors=True)


if __name__ == "__main__":
    slugs = sys.argv[1:] or sorted(
        p.name.replace(".captions.json", "") for p in OUT.glob("*.captions.json"))
    for slug in slugs:
        encode(slug)
