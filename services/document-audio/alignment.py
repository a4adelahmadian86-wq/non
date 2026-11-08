from __future__ import annotations

import json
import re
import struct
from typing import Iterable


def build_word_timings(text: str, alignments: Iterable[object], sample_rate: int, total_samples: int) -> list[dict[str, int | str]] | None:
    """Convert Piper phoneme sample counts into exact word intervals.

    Piper's eSpeak phoneme stream preserves spaces as phoneme entries, so each
    whitespace alignment closes one spoken-word interval. Refuse to guess when
    the phoneme groups do not map one-to-one to the source text tokens.
    """
    words = re.findall(r"\S+", text, flags=re.UNICODE)
    if not words or sample_rate <= 0 or total_samples <= 0:
        return None

    cursor = 0
    word_start: int | None = None
    groups: list[tuple[int, int]] = []

    for item in alignments:
        phoneme = str(getattr(item, "phoneme", ""))
        try:
            samples = int(getattr(item, "num_samples", 0))
        except (TypeError, ValueError, OverflowError):
            return None
        if samples < 0:
            return None

        if phoneme.isspace():
            if word_start is not None and cursor > word_start:
                groups.append((word_start, cursor))
            word_start = None
            cursor += samples
            continue

        if word_start is None:
            word_start = cursor
        cursor += samples

    if word_start is not None and cursor > word_start:
        groups.append((word_start, cursor))

    if len(groups) != len(words):
        return None

    tolerance = max(int(sample_rate * 0.1), int(total_samples * 0.02))
    if abs(cursor - total_samples) > tolerance:
        return None

    result: list[dict[str, int | str]] = []
    previous_end = 0
    for word, (start_sample, end_sample) in zip(words, groups, strict=True):
        start_ms = max(previous_end, round(start_sample * 1000 / sample_rate))
        end_ms = round(end_sample * 1000 / sample_rate)
        if end_ms <= start_ms:
            return None
        result.append({"text": word, "start_ms": start_ms, "end_ms": end_ms})
        previous_end = end_ms

    return result


def append_alignment_metadata(wav_bytes: bytes, words: list[dict[str, int | str]]) -> bytes:
    """Append a private, standards-compatible RIFF chunk carrying word timings."""
    if len(wav_bytes) < 12 or wav_bytes[:4] != b"RIFF" or wav_bytes[8:12] != b"WAVE":
        raise ValueError("invalid_wav_container")

    payload = json.dumps(
        {"version": 1, "alignment": "phoneme", "words": words},
        ensure_ascii=False,
        separators=(",", ":"),
    ).encode("utf-8")
    if len(payload) > 1_000_000:
        raise ValueError("alignment_metadata_too_large")

    chunk = b"FARA" + struct.pack("<I", len(payload)) + payload
    if len(payload) % 2:
        chunk += b"\x00"

    result = wav_bytes + chunk
    return result[:4] + struct.pack("<I", len(result) - 8) + result[8:]
