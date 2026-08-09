#!/usr/bin/env python3
"""
Narrated MP4s of the journey walk.

The frames are already on disk, so this adds a voice track and re-times the
video around it — nothing is re-walked and the application is not touched.

HOW THE TIMING WORKS. Each frame gets its own line of narration, spoken by the
macOS `say` engine into its own audio clip. The frame is then held on screen for
exactly as long as that clip lasts plus a breath, so the picture never moves on
mid-sentence and never sits in silence waiting. The soundtrack is the clips
concatenated with matching silence, so audio and video stay locked together for
the whole run without drift.

WHAT IT SAYS. Not the caption read aloud — captions are terse labels written for
someone scanning a table. The narration explains what is being done and why it
matters, and when a check produced a verdict it says so plainly, including the
failures. A recording that only narrated the successes would be a demo, not
evidence.
"""
import json
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

OUT = Path("/Users/mohammed/work/Trust Data/new gondal backend/Backend code/docs/journey-recordings")
VOICE = "Daniel"      # en_GB; the project is written in British English
RATE = 178            # words per minute — unhurried but not sluggish
WIDTH = 1280
BAR = 56
MIN_HOLD = 2.2        # a frame never flashes past, even on a short line
BREATH = 0.7          # silence after each line

ROLE_INTRO = {
    "01-collection-agent":
        "Collection Agent. Sani Bello, scoped to a single collection point, "
        "walking the morning round: recording deliveries, hitting the cut-off, "
        "and dispatching a consignment.",
    "02-collection-officer":
        "Milk Collection Officer. Halima Yusuf, scoped to one centre. She confirms "
        "what the agents dispatched, records the quality tests, assigns a grade, "
        "and sends a batch to the factory.",
    "03-milk-supervisor":
        "Milk Collection Supervisor. Muhammad Bello, network-wide. He reconciles "
        "what the factory actually received against what was dispatched, and "
        "releases the batch.",
    "04-sales-officer":
        "Sales Officer. Hauwa Ibrahim, scoped to her own transactions. She serves "
        "the shop counter, and must not see the shop's money.",
    "05-shop-manager":
        "One-Stop Shop Manager. Amina Kabir, network-wide. She sees the money the "
        "sales officer cannot, and she is the only one who may void a sale.",
    "08-department-head":
        "Department Head, scoped to their own department. They raise requisitions "
        "and approve the ones their department sends up.",
    "09-internal-audit":
        "Internal Audit. Umar Muduru reads everything and changes nothing. The "
        "interesting half of this journey is what he is not offered.",
    "11-hr-manager":
        "HR Manager. Rahma Sule, network-wide. Staff records, departments, leave — "
        "and the approvals queue, which until this week refused her.",
    "13-system-administrator":
        "System Administrator. Sadiq Ahmed. Users, roles, scopes and permission "
        "testing — and never a password field, because administrators do not set "
        "them.",
}

VERDICT_SENTENCE = {
    "works": "{story} That works.",
    "refuses": "{story} Correctly refused.",
    "broken": "{story} This one is broken.",
    "missing": "{story} This one is missing.",
}

# The first five frames of every role are the same sign-in sequence. Scripted
# once, so the voice does not repeat itself four times over.
SIGN_IN = [
    "Signing in.",
    "Email and password.",
    "Two-factor is on for every account, so a code is required.",
    "The code is read out of the delivered message. It is never guessed — what "
    "the database stores is a hash, not the code.",
    "And we are in.",
]


def is_prose(text):
    """Is this a sentence a person wrote, or a machine string?

    The walk records some findings as key-value dumps — `modal open=true,
    presented="31"` — which are perfectly good evidence in a table and unbearable
    read aloud. Only the application's own messages get spoken.
    """
    if not text or len(text) > 130:
        return False
    if re.search(r'[=|\[\]{}]|"', text):
        return False
    return " " in text


def speakable(text):
    """Turn screen text into something worth hearing."""
    text = re.sub(r"https?://\S+", "", text)
    # References read badly digit by digit; name them instead.
    text = re.sub(r"\bDEL-\d{8}-0*(\d+)\b", r"delivery \1", text)
    text = re.sub(r"\bRCP-\d{8}-0*(\d+)\b", r"receipt \1", text)
    text = re.sub(r"\bCNS-0*(\d+)\b", r"consignment \1", text)
    text = re.sub(r"\bBATCH-0*(\d+)\b", r"batch \1", text)
    text = re.sub(r"\bDENY-0*(\d+)\b", r"denial \1", text)
    text = text.replace("₦", "naira ").replace("—", ", ").replace("·", ", ")
    text = text.replace("&", " and ").replace("→", " to ")
    text = re.sub(r"[✅❌⚠️🛡️ℹ️]", "", text)
    text = re.sub(r"\bL\b", "litres", text)
    text = re.sub(r"\s+([,.])", r"\1", text)      # " , 22 litres" -> ", 22 litres"
    text = re.sub(r"[:;]\s*$", "", text)           # a dangling colon
    text = re.sub(r"\s+", " ", text).strip()
    text = re.sub(r"\.\.+$", ".", text)
    return text


def sentence(text):
    text = speakable(text)
    if not text:
        return ""
    text = text[0].upper() + text[1:]
    return text if text.endswith((".", "?", "!")) else text + "."


def script_for(slug, meta):
    """One spoken line per frame."""
    by_frame = {}
    for f in meta["findings"]:
        by_frame.setdefault(f["frame"], []).append(f)

    lines = []
    for i, frame in enumerate(meta["frames"]):
        parts = []

        if i == 0 and slug in ROLE_INTRO:
            parts.append(ROLE_INTRO[slug])

        # The sign-in run is scripted; everything after it follows the captions.
        if i < len(SIGN_IN) and re.search(
                r"sign-in page|credentials|two-factor|after submitting the two",
                frame["caption"], re.I):
            parts.append(SIGN_IN[i])
        else:
            parts.append(sentence(frame["caption"]))

        for finding in by_frame.get(frame["n"], []):
            # The scripted sign-in already says whether we got in.
            if re.match(r"^sign in", finding["story"], re.I):
                continue

            story = sentence(finding["story"])
            said = VERDICT_SENTENCE.get(finding["verdict"], "{story}").format(story=story)
            parts.append(said)

            # Quote the application only when it spoke in sentences.
            detail = speakable(finding["happened"])
            detail = re.sub(r"^(refused|refuses):\s*", "", detail, flags=re.I)
            if is_prose(detail) and finding["verdict"] in ("works", "refuses", "broken"):
                if not re.match(r"^(the |a |no |every |none|status)", detail, re.I):
                    parts.append(sentence(detail))

        line = " ".join(p for p in parts if p).strip()
        lines.append(re.sub(r"\s+", " ", line) or "Next.")
    return lines


def font(size):
    for candidate in ("/System/Library/Fonts/Helvetica.ttc",
                      "/System/Library/Fonts/Supplemental/Arial.ttf"):
        try:
            return ImageFont.truetype(candidate, size)
        except OSError:
            continue
    return ImageFont.load_default()


def duration_of(path):
    out = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration",
         "-of", "default=noprint_wrappers=1:nokey=1", str(path)],
        capture_output=True, text=True, check=True)
    return float(out.stdout.strip())


def build(slug):
    meta_path = OUT / f"{slug}.captions.json"
    if not meta_path.exists():
        print(f"  no captions for {slug}")
        return

    meta = json.loads(meta_path.read_text())
    frames = sorted((OUT / "frames" / slug).glob("*.png"))
    if not frames:
        print(f"  no frames for {slug}")
        return

    lines = script_for(slug, meta)
    captions = {f["file"]: f for f in meta["frames"]}
    title, small = font(21), font(15)
    stage = Path(tempfile.mkdtemp(prefix=f"narr-{slug}-"))

    try:
        holds, audio_parts = [], []

        for i, path in enumerate(frames):
            line = lines[i] if i < len(lines) else "Next."

            # Speak it, then let the clip decide how long the frame stays up.
            spoken = stage / f"{i:04d}.aiff"
            subprocess.run(["say", "-v", VOICE, "-r", str(RATE),
                            "-o", str(spoken), line], check=True)
            hold = max(MIN_HOLD, duration_of(spoken) + BREATH)
            holds.append(hold)

            padded = stage / f"{i:04d}.wav"
            subprocess.run(
                ["ffmpeg", "-y", "-loglevel", "error", "-i", str(spoken),
                 "-af", f"apad=whole_dur={hold:.3f}", "-ar", "44100", "-ac", "1",
                 str(padded)], check=True)
            audio_parts.append(padded)

            # Caption bar, same as the silent cut.
            im = Image.open(path).convert("RGB")
            if im.width != WIDTH:
                im = im.resize((WIDTH, round(im.height * WIDTH / im.width)), Image.LANCZOS)
            height = im.height + BAR
            height += height % 2
            canvas = Image.new("RGB", (WIDTH, height), "#111827")
            canvas.paste(im, (0, 0))
            entry = captions.get(path.name, {})
            draw = ImageDraw.Draw(canvas)
            draw.text((16, im.height + 9),
                      f"{entry.get('n', i + 1):>3}  {entry.get('caption', path.stem)}",
                      font=title, fill="#f9fafb")
            url = (entry.get("url") or "").replace("http://127.0.0.1:8008", "")
            if url:
                draw.text((16, im.height + 34), url, font=small, fill="#9ca3af")
            canvas.save(stage / f"{i:04d}.png")

        # Video: each image held for exactly its narration's length.
        listing = stage / "frames.txt"
        with listing.open("w") as fh:
            for i, hold in enumerate(holds):
                fh.write(f"file '{stage}/{i:04d}.png'\nduration {hold:.3f}\n")
            fh.write(f"file '{stage}/{len(holds) - 1:04d}.png'\n")  # concat needs the last twice

        # Audio: the same clips, in the same order, already padded to match.
        audio_list = stage / "audio.txt"
        audio_list.write_text("".join(f"file '{p}'\n" for p in audio_parts))
        soundtrack = stage / "voice.wav"
        subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-f", "concat",
                        "-safe", "0", "-i", str(audio_list), "-c", "copy",
                        str(soundtrack)], check=True)

        target = OUT / f"{slug}-narrated.mp4"
        subprocess.run([
            "ffmpeg", "-y", "-loglevel", "error",
            "-f", "concat", "-safe", "0", "-i", str(listing),
            "-i", str(soundtrack),
            "-vf", "format=yuv420p",
            "-c:v", "libx264", "-preset", "slow", "-crf", "20", "-r", "30",
            "-c:a", "aac", "-b:a", "96k",
            "-shortest", "-movflags", "+faststart",
            str(target),
        ], check=True)

        mb = target.stat().st_size / 1_000_000
        print(f"  {slug}-narrated.mp4 — {len(frames)} frames, "
              f"{sum(holds):.0f}s, {mb:.1f} MB")
    finally:
        shutil.rmtree(stage, ignore_errors=True)


if __name__ == "__main__":
    slugs = sys.argv[1:] or sorted(
        p.name.replace(".captions.json", "") for p in OUT.glob("*.captions.json"))
    for slug in slugs:
        build(slug)
