from __future__ import annotations

import json
import struct
import unittest
from types import SimpleNamespace

from alignment import append_alignment_metadata, build_word_timings


class PiperWordAlignmentTests(unittest.TestCase):
    def test_maps_space_delimited_phonemes_to_exact_word_sample_ranges(self) -> None:
        alignments = [
            SimpleNamespace(phoneme="^", num_samples=0),
            SimpleNamespace(phoneme="a", num_samples=100),
            SimpleNamespace(phoneme=" ", num_samples=50),
            SimpleNamespace(phoneme="b", num_samples=200),
            SimpleNamespace(phoneme="$", num_samples=0),
        ]

        result = build_word_timings("اول دوم", alignments, sample_rate=1000, total_samples=350)

        self.assertEqual(
            [
                {"text": "اول", "start_ms": 0, "end_ms": 100},
                {"text": "دوم", "start_ms": 150, "end_ms": 350},
            ],
            result,
        )

    def test_refuses_alignment_when_phoneme_word_count_does_not_match_text(self) -> None:
        alignments = [
            SimpleNamespace(phoneme="a", num_samples=100),
            SimpleNamespace(phoneme="$", num_samples=0),
        ]

        self.assertIsNone(build_word_timings("دو واژه", alignments, 1000, 100))

    def test_refuses_alignment_when_sample_total_is_inconsistent(self) -> None:
        alignments = [SimpleNamespace(phoneme="a", num_samples=100)]

        self.assertIsNone(build_word_timings("واژه", alignments, 1000, 500))

    def test_appends_valid_riff_alignment_chunk_without_corrupting_wav_size(self) -> None:
        pcm = b"\x00\x00" * 20
        fmt = struct.pack("<HHIIHH", 1, 1, 16000, 32000, 2, 16)
        wav = (
            b"RIFF" + struct.pack("<I", 36 + len(pcm)) + b"WAVE"
            + b"fmt " + struct.pack("<I", 16) + fmt
            + b"data" + struct.pack("<I", len(pcm)) + pcm
        )
        words = [{"text": "واژه", "start_ms": 0, "end_ms": 2}]

        result = append_alignment_metadata(wav, words)

        self.assertEqual(b"RIFF", result[:4])
        self.assertEqual(len(result) - 8, struct.unpack("<I", result[4:8])[0])
        offset = result.index(b"FARA")
        size = struct.unpack("<I", result[offset + 4:offset + 8])[0]
        metadata = json.loads(result[offset + 8:offset + 8 + size].decode("utf-8"))
        self.assertEqual("phoneme", metadata["alignment"])
        self.assertEqual(words, metadata["words"])


if __name__ == "__main__":
    unittest.main()
