FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    SHERPA_MODEL_DIR=/models \
    SHERPA_HOST=0.0.0.0 \
    SHERPA_PORT=6006

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY server.py .

EXPOSE 6006
CMD ["python", "server.py"]
