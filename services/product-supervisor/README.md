# FARAST Product Supervisor

A separate, stateless review service for the product creation workflow.

## Isolation guarantees

- This service is independent of the existing voice/AI gateway and does not call or modify it.
- It uses its own Render service, bearer token, Gemini API key, model setting, health endpoint, and deployment lifecycle.
- The service receives a minimized product snapshot only; it does not receive uploaded files, customer data, payment data, or Laravel credentials.
- It never publishes, edits, or deletes a product. It returns a review result for Laravel to store and present for human action.
- API keys are read only from environment variables and are never returned by health checks.

## Deploy

The repository root `render.yaml` defines a separate free web service named `farast-product-supervisor`. Create the service from this Blueprint after connecting the Render account. Set `GEMINI_API_KEY` in the Render environment settings. Render generates `SUPERVISOR_API_TOKEN`.

Free instances may sleep when idle and have usage/availability limits. The admin workflow must treat timeouts and unavailable service as a non-review state, never as approval.

## API

- `GET /health`: public liveness/configuration status; does not disclose secrets.
- `POST /v1/reviews/products`: authenticated product review. Requires `Authorization: Bearer <SUPERVISOR_API_TOKEN>`.

The request schema is strict and bounded. Responses contain a verdict, score, concise Persian summary, structured findings, model identifier, timestamp, and `automated_publication: false`.

## Local run

```bash
python -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
export SUPERVISOR_API_TOKEN='local-only-secret'
export GEMINI_API_KEY='your-own-key'
uvicorn main:app --reload --port 8091
```
