FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIPER_MODEL_PATH=/models/fa_IR-amir-medium.onnx \
    PIPER_NUM_THREADS=2 \
    FARAST_TTS_HOST=0.0.0.0 \
    FARAST_TTS_PORT=6007

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libespeak-ng1 libgomp1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY server.py download_model.py ./

EXPOSE 6007
CMD ["sh", "-c", "python download_model.py && exec uvicorn server:app --host 0.0.0.0 --port 6007"]
