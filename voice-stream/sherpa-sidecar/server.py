import asyncio
import json
import os
from pathlib import Path

import numpy as np
import sherpa_onnx
import websockets

MODEL_DIR = Path(os.getenv("SHERPA_MODEL_DIR", "/models"))
HOST = os.getenv("SHERPA_HOST", "127.0.0.1")
PORT = int(os.getenv("SHERPA_PORT", "6006"))
NUM_THREADS = max(1, int(os.getenv("SHERPA_NUM_THREADS", "4")))
SAMPLE_RATE = 16000
LOCALE = "fa-IR"


def required_file(name: str) -> str:
    path = MODEL_DIR / name
    if not path.is_file():
        raise RuntimeError(f"model_file_missing:{path}")
    return str(path)


def create_recognizer():
    return sherpa_onnx.OnlineRecognizer.from_transducer(
        tokens=required_file("tokens.txt"),
        encoder=required_file("encoder.int8.onnx"),
        decoder=required_file("decoder.int8.onnx"),
        joiner=required_file("joiner.int8.onnx"),
        num_threads=NUM_THREADS,
        feature_dim=128,
    )


async def decode_ready(recognizer, stream):
    while recognizer.is_ready(stream):
        recognizer.decode_stream(stream)


async def send_result(socket, text: str, segment: int, is_final: bool):
    text = (text or "").strip()
    if text:
        await socket.send(json.dumps({
            "text": text,
            "segment": segment,
            "is_final": is_final,
        }, ensure_ascii=False))


async def handle_connection(socket, recognizer):
    stream = recognizer.create_stream()
    stream.set_option("language", LOCALE)
    segment = 0

    try:
        async for message in socket:
            if isinstance(message, str):
                if message == "Done":
                    break
                raise ValueError("unexpected_text_frame")

            if len(message) % 4:
                raise ValueError("invalid_float32_frame")

            samples = np.frombuffer(message, dtype='<f4')
            if samples.size == 0:
                continue

            stream.accept_waveform(sample_rate=SAMPLE_RATE, waveform=samples)
            await decode_ready(recognizer, stream)
            result = recognizer.get_result(stream)

            if recognizer.is_endpoint(stream):
                await send_result(socket, result, segment, True)
                recognizer.reset(stream)
                segment += 1
            else:
                await send_result(socket, result, segment, False)

        tail = np.zeros(int(SAMPLE_RATE * 0.3), dtype=np.float32)
        stream.accept_waveform(sample_rate=SAMPLE_RATE, waveform=tail)
        stream.input_finished()
        await decode_ready(recognizer, stream)
        await send_result(socket, recognizer.get_result(stream), segment, True)
    except websockets.ConnectionClosed:
        return


async def main():
    recognizer = create_recognizer()
    print(
        f"Sherpa-ONNX Persian streaming ASR listening on ws://{HOST}:{PORT} "
        f"(threads={NUM_THREADS}, locale={LOCALE})",
        flush=True,
    )
    async with websockets.serve(
        lambda socket: handle_connection(socket, recognizer),
        HOST,
        PORT,
        max_size=1024 * 1024,
        max_queue=8,
        compression=None,
    ):
        await asyncio.Future()


if __name__ == "__main__":
    asyncio.run(main())
