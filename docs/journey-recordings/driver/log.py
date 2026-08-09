#!/usr/bin/env python3
"""
Build docs/JOURNEY-LOG.md from the per-role capture files.

Generated rather than hand-written so the log cannot drift from what the walk
actually observed: every line here came out of a real browser session.
"""
import json
from pathlib import Path

OUT = Path("/Users/mohammed/work/Trust Data/new gondal backend/Backend code/docs")
REC = OUT / "journey-recordings"

MARK = {"works": "✅ works", "broken": "❌ broken",
        "missing": "⚠️ missing", "refuses": "🛡️ refuses correctly"}

HEADER = """# Journey log — end-to-end walk of the Gondal ERP

Every line below was produced by driving the real application in Chrome against
the seeded database, not by reading code. Each role has a recording beside it;
the frame number in the last column points into that recording.

**Recording method.** ffmpeg and ImageMagick are not installed on this machine,
so the frames are stitched into an animated GIF with Pillow instead of an MP4.
The PNG frames are kept under `journey-recordings/frames/<role>/`, so a video can
be produced later without walking anything again.

**Signing in.** Demo accounts use `GondalDemo!2026`, pilot accounts
`GondalPilot!2026`. Two-factor is ON for all 46 accounts — `migrate:fresh --seed`
turns it back on. The code is never stored in plaintext (`login_codes.code_hash`
is a hash), so the walk reads the delivered message out of `storage/logs/laravel.log`,
which is where `MAIL_MAILER=log` puts it. It is never guessed.

**Verdicts.** ✅ works · ❌ broken · ⚠️ missing · 🛡️ refuses correctly.
A 🛡️ is a pass: it means a "cannot" story genuinely could not.

"""


def build():
    files = sorted(REC.glob("*.captions.json"))
    roles = [json.loads(f.read_text()) for f in files]

    # Summary first — a reader wants the state of things before the detail.
    counts = {"works": 0, "broken": 0, "missing": 0, "refuses": 0}
    for r in roles:
        for f in r["findings"]:
            counts[f["verdict"]] = counts.get(f["verdict"], 0) + 1

    lines = [HEADER, "## Summary\n",
             "| Verdict | Count |", "| --- | --- |"]
    for key in ("works", "refuses", "missing", "broken"):
        lines.append(f"| {MARK[key]} | {counts.get(key, 0)} |")
    lines.append(f"\n{len(roles)} roles walked, "
                 f"{sum(len(r['findings']) for r in roles)} checks.\n")

    outstanding = [(r["role"], f) for r in roles for f in r["findings"]
                   if f["verdict"] in ("broken", "missing")]
    if outstanding:
        lines += ["\n## Still outstanding\n",
                  "| Role | Step | What happened |", "| --- | --- | --- |"]
        for role, f in outstanding:
            lines.append(f"| {role} | {f['step']} {f['story']} | {f['happened']} |")
    else:
        lines.append("\nNothing outstanding: every check either passed or refused correctly.\n")

    for r in roles:
        slug = r["role"].lower().replace(" ", "-")
        lines += [f"\n---\n\n## {r['role']}\n",
                  f"Recording: [`{slug}.gif`](journey-recordings/{slug}.gif) · "
                  f"captions: [`{slug}.md`](journey-recordings/{slug}.md)\n",
                  "| # | Story | What was done | What happened | Verdict | Frame |",
                  "| --- | --- | --- | --- | --- | --- |"]
        for f in r["findings"]:
            happened = f["happened"].replace("|", "\\|").replace("\n", " ")
            lines.append(
                f"| {f['step']} | {f['story']} | {f['did']} | {happened} | "
                f"{MARK.get(f['verdict'], f['verdict'])} | {f['frame']} |"
            )
        if r.get("console"):
            lines.append(f"\nBrowser console errors: `{r['console'][:3]}`\n")

    (OUT / "JOURNEY-LOG.md").write_text("\n".join(lines) + "\n")
    print(f"JOURNEY-LOG.md — {len(roles)} roles, "
          f"{sum(len(r['findings']) for r in roles)} checks, "
          f"{counts.get('broken',0)} broken, {counts.get('missing',0)} missing")


if __name__ == "__main__":
    build()
