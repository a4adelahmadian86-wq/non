import numpy as np

from server import LOCALE, SAMPLE_RATE, create_recognizer


def main() -> None:
    recognizer = create_recognizer()
    stream = recognizer.create_stream()
    stream.set_option("language", LOCALE)

    silence = np.zeros(SAMPLE_RATE, dtype=np.float32)
    for offset in range(0, silence.size, 1600):
        stream.accept_waveform(
            sample_rate=SAMPLE_RATE,
            waveform=silence[offset:offset + 1600],
        )
        while recognizer.is_ready(stream):
            recognizer.decode_stream(stream)

    stream.input_finished()
    while recognizer.is_ready(stream):
        recognizer.decode_stream(stream)

    print("Sherpa-ONNX model loaded and processed a 1-second 16 kHz PCM stream.", flush=True)


if __name__ == "__main__":
    main()
