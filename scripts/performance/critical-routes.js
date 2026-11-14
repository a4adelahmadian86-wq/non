import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || '').replace(/\/+$/, '');
const SESSION_COOKIE_NAME = __ENV.SESSION_COOKIE_NAME || 'farast_session';
const AUTH_COOKIE = __ENV.AUTH_COOKIE || '';
const PUBLIC_PATHS = parsePaths(__ENV.PUBLIC_PATHS || '/,/store,/login');
const AUTH_PATHS = parsePaths(__ENV.AUTH_PATHS || '');
const VUS = positiveInteger(__ENV.VUS || '20', 'VUS');
const P95_MS = positiveNumber(__ENV.SLO_P95_MS, 'SLO_P95_MS');
const P99_MS = positiveNumber(__ENV.SLO_P99_MS, 'SLO_P99_MS');
const ERROR_RATE = ratio(__ENV.SLO_ERROR_RATE, 'SLO_ERROR_RATE');
const routeCheckSuccess = new Rate('route_check_success');

if (!BASE_URL || !/^https?:\/\//i.test(BASE_URL)) {
  throw new Error('BASE_URL must be an absolute http(s) URL for an approved test environment.');
}
if (AUTH_PATHS.length > 0 && !AUTH_COOKIE) {
  throw new Error('AUTH_COOKIE is required when AUTH_PATHS are configured. Use a dedicated, low-privilege staging account.');
}
if (P99_MS < P95_MS) {
  throw new Error('SLO_P99_MS must be greater than or equal to SLO_P95_MS.');
}

const routeThresholds = {
  http_req_duration: [`p(95)<${P95_MS}`, `p(99)<${P99_MS}`],
  http_req_failed: [`rate<=${ERROR_RATE}`],
  checks: [`rate>=${1 - ERROR_RATE}`],
  route_check_success: [`rate>=${1 - ERROR_RATE}`],
};

for (const route of new Set([...PUBLIC_PATHS, ...AUTH_PATHS].map(routeTag))) {
  const selector = `{route:${route}}`;
  routeThresholds[`http_req_duration${selector}`] = [`p(95)<${P95_MS}`, `p(99)<${P99_MS}`];
  routeThresholds[`http_req_failed${selector}`] = [`rate<=${ERROR_RATE}`];
  routeThresholds[`route_check_success${selector}`] = [`rate>=${1 - ERROR_RATE}`];
}

export const options = {
  stages: [
    { duration: __ENV.WARMUP_DURATION || '1m', target: Math.max(1, Math.ceil(VUS / 4)) },
    { duration: __ENV.RAMP_DURATION || '2m', target: VUS },
    { duration: __ENV.HOLD_DURATION || '3m', target: VUS },
    { duration: __ENV.COOLDOWN_DURATION || '1m', target: 0 },
  ],
  thresholds: routeThresholds,
  discardResponseBodies: true,
  userAgent: 'FARAST-k6-performance-check/1.0',
};

const cookieJar = http.cookieJar();
if (AUTH_COOKIE) {
  cookieJar.set(BASE_URL, SESSION_COOKIE_NAME, AUTH_COOKIE, { path: '/' });
}

export default function () {
  group('public critical routes', () => {
    for (const path of PUBLIC_PATHS) {
      const route = routeTag(path);
      const response = http.get(`${BASE_URL}${path}`, {
        tags: { route, access: 'public' },
        timeout: __ENV.REQUEST_TIMEOUT || '15s',
      });
      const successful = response.status >= 200 && response.status < 400;
      routeCheckSuccess.add(successful, { route, access: 'public' });
      check(response, {
        'public route returns a successful response': () => successful,
      });
    }
  });

  if (AUTH_PATHS.length > 0) {
    group('authenticated critical routes', () => {
      for (const path of AUTH_PATHS) {
        const route = routeTag(path);
        const response = http.get(`${BASE_URL}${path}`, {
          tags: { route, access: 'authenticated' },
          timeout: __ENV.REQUEST_TIMEOUT || '15s',
        });
        const successful = response.status >= 200 && response.status < 400
          && !/\/login(?:[/?#]|$)/i.test(response.url);
        routeCheckSuccess.add(successful, { route, access: 'authenticated' });
        check(response, {
          'authenticated route succeeds without login redirect': () => successful,
        });
      }
    });
  }

  sleep(Number(__ENV.THINK_TIME_SECONDS || '1'));
}

function parsePaths(value) {
  if (!value.trim()) return [];
  const paths = value.split(',').map((path) => path.trim()).filter(Boolean);
  for (const path of paths) {
    if (!path.startsWith('/') || path.startsWith('//') || /^https?:\/\//i.test(path)) {
      throw new Error(`Invalid route path "${path}". Supply relative paths beginning with one slash.`);
    }
  }
  return paths;
}

function positiveInteger(value, name) {
  const parsed = Number(value);
  if (!Number.isInteger(parsed) || parsed < 1) {
    throw new Error(`${name} must be a positive integer.`);
  }
  return parsed;
}

function positiveNumber(value, name) {
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    throw new Error(`${name} must be a positive number and must be explicitly configured.`);
  }
  return parsed;
}

function ratio(value, name) {
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed < 0 || parsed > 1) {
    throw new Error(`${name} must be a ratio between 0 and 1 (for example 0.01 for 1%).`);
  }
  return parsed;
}

function routeTag(path) {
  return path.split('?')[0].replace(/\/{2,}/g, '/').slice(0, 120) || '/';
}
