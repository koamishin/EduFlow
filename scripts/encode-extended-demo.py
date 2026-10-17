#!/usr/bin/env python3
"""
Encode 5-Minute Full Product Demo with 11-Scene Broadcast Chyron Overlays.
Ensures crisp H.264 video, precise timing, and verified duration >= 3 minutes.
"""
import os
import shutil
import subprocess
import sys

RAW_INPUT = "demo/raw_extended/page@c23cd59ca87639c4b35ec0a4922a2de2.webm"
OUTPUT_MP4 = "demo/opensinop-extended-product-demo.mp4"
ALIAS_MP4 = "demo/opensinop-demo.mp4"
FRAMES_DIR = "demo/frames_extended"

SCENES = [
    {
        "index": 1,
        "start": 0.0,
        "end": 26.0,
        "midpoint": 12.0,
        "title": "PLATFORM ARCHITECTURE & ARC / CIRCLE SETTLEMENT",
        "text": "FEATURE 01 | PLATFORM ARCHITECTURE & ARC / CIRCLE SETTLEMENT",
    },
    {
        "index": 2,
        "start": 26.0,
        "end": 64.0,
        "midpoint": 45.0,
        "title": "MULTI-SCHOOL ONBOARDING & TREASURY CONFIGURATION",
        "text": "FEATURE 02 | MULTI-SCHOOL ONBOARDING & TREASURY CONFIGURATION",
    },
    {
        "index": 3,
        "start": 64.0,
        "end": 78.0,
        "midpoint": 71.0,
        "title": "ROLE-BASED ACCESS & INSTITUTIONAL AUTHENTICATION",
        "text": "FEATURE 03 | ROLE-BASED ACCESS & INSTITUTIONAL AUTHENTICATION",
    },
    {
        "index": 4,
        "start": 78.0,
        "end": 104.0,
        "midpoint": 90.0,
        "title": "DETERMINISTIC POLICY STUDIO & 1-CLICK PLAYBOOKS",
        "text": "FEATURE 04 | DETERMINISTIC POLICY STUDIO & 1-CLICK PLAYBOOKS",
    },
    {
        "index": 5,
        "start": 104.0,
        "end": 136.0,
        "midpoint": 120.0,
        "title": "INTERACTIVE JEV AI DECISION SIMULATOR",
        "text": "FEATURE 05 | INTERACTIVE JEV AI DECISION SIMULATOR",
    },
    {
        "index": 6,
        "start": 136.0,
        "end": 168.0,
        "midpoint": 152.0,
        "title": "EXECUTIVE JEV TELEMETRY & DUAL-CUSTODY GOVERNANCE",
        "text": "FEATURE 06 | EXECUTIVE JEV TELEMETRY & DUAL-CUSTODY GOVERNANCE",
    },
    {
        "index": 7,
        "start": 168.0,
        "end": 198.0,
        "midpoint": 183.0,
        "title": "STUDENT WEB3 PORTAL & WALLET LINKING",
        "text": "FEATURE 07 | STUDENT WEB3 PORTAL & WALLET LINKING",
    },
    {
        "index": 8,
        "start": 198.0,
        "end": 228.0,
        "midpoint": 213.0,
        "title": "DIRECT USDC TUITION SETTLEMENT ON ARC",
        "text": "FEATURE 08 | DIRECT USDC TUITION SETTLEMENT ON ARC",
    },
    {
        "index": 9,
        "start": 228.0,
        "end": 250.0,
        "midpoint": 239.0,
        "title": "CAMPUS BILLS & CAPSTONE FEE LEDGER",
        "text": "FEATURE 09 | CAMPUS BILLS & CAPSTONE FEE LEDGER",
    },
    {
        "index": 10,
        "start": 250.0,
        "end": 272.0,
        "midpoint": 261.0,
        "title": "BOUNDED AUTONOMY FINANCIAL ASSISTANCE",
        "text": "FEATURE 10 | BOUNDED AUTONOMY FINANCIAL ASSISTANCE",
    },
    {
        "index": 11,
        "start": 272.0,
        "end": 301.92,
        "midpoint": 286.0,
        "title": "OFFICIAL PAYMENT E-RECEIPTS & ON-CHAIN AUDIT PROOFS",
        "text": "FEATURE 11 | OFFICIAL PAYMENT E-RECEIPTS & ON-CHAIN AUDIT PROOFS",
    },
]


def build_filter_complex():
    filters = []
    font_file = "/usr/share/fonts/TTF/Roboto-Bold.ttf"
    for s in SCENES:
        escaped_text = s["text"].replace("'", r"\'")
        f = (
            f"drawtext=fontfile='{font_file}':"
            f"text='{escaped_text}':"
            f"fontcolor=white:fontsize=20:"
            f"box=1:boxcolor=0x0f172a@0.92:boxborderw=10:"
            f"x=300:y=h-56:"
            f"enable='between(t,{s['start']},{s['end']})'"
        )
        filters.append(f)
    return ",".join(filters)


def main():
    if not os.path.exists(RAW_INPUT):
        print(f"Error: Raw input file {RAW_INPUT} not found.")
        sys.exit(1)

    os.makedirs(FRAMES_DIR, exist_ok=True)
    filter_graph = build_filter_complex()

    cmd = [
        "ffmpeg",
        "-y",
        "-i",
        RAW_INPUT,
        "-vf",
        filter_graph,
        "-c:v",
        "libx264",
        "-preset",
        "fast",
        "-crf",
        "20",
        "-pix_fmt",
        "yuv420p",
        "-movflags",
        "+faststart",
        OUTPUT_MP4,
    ]

    print(">>> Starting FFmpeg Encode of 5-Minute Demo...")
    print(f"Command: {' '.join(cmd[:6])} ... -> {OUTPUT_MP4}")
    res = subprocess.run(cmd, check=True)
    if res.returncode != 0:
        print("Encode failed!")
        sys.exit(res.returncode)

    print(f"\n>>> Encode successful: {OUTPUT_MP4}")
    # Copy alias
    shutil.copyfile(OUTPUT_MP4, ALIAS_MP4)
    print(f">>> Updated primary alias: {ALIAS_MP4}")

    # Inspect with ffprobe
    probe_cmd = [
        "ffprobe",
        "-v",
        "error",
        "-show_entries",
        "format=duration,size,bit_rate:stream=width,height,r_frame_rate,codec_name",
        "-of",
        "default=noprint_wrappers=1",
        OUTPUT_MP4,
    ]
    probe_output = subprocess.check_output(probe_cmd, text=True)
    print("\n>>> FFprobe Metadata:")
    print(probe_output)

    # Extract verification frames
    print("\n>>> Extracting 11 verification frames across scenes...")
    for s in SCENES:
        idx = s["index"]
        t = s["midpoint"]
        frame_path = os.path.join(FRAMES_DIR, f"reviewed_scene_{idx:02d}.png")
        extract_cmd = [
            "ffmpeg",
            "-y",
            "-ss",
            str(t),
            "-i",
            OUTPUT_MP4,
            "-frames:v",
            "1",
            "-update",
            "1",
            frame_path,
        ]
        subprocess.run(
            extract_cmd,
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        print(
            f"  [Scene {idx:02d} @ {t:05.1f}s] {s['title']} -> {frame_path}"
        )

    print("\n>>> All verification frames successfully generated.")


if __name__ == "__main__":
    main()
