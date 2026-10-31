FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIPER_MODEL_DIR=/models \
    PIPER_HOST=0.0.0.0 \
    PIPER_PORT=7007

WORKDIR /app
RUN apt-get update \
    && apt-get install -y --no-install-recommends libespeak-ng1 espeak-ng-data \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY download_model.py server.py ./

EXPOSE 7007
CMD ["sh", "-c", "python download_model.py && exec python server.py"]
