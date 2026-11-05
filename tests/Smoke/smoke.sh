#!/usr/bin/env bash
# Black-box check of the core inside a real FrankenPHP worker (2 workers, MAX_REQUESTS=500).
# Run from the repo root after `composer install`: tests/Smoke/smoke.sh
set -euo pipefail

BASE=http://127.0.0.1:8080
NAME=slimfony-smoke
IMAGE=slimfony-smoke:local
METRICS_TOKEN=smoke-metrics-token-0123456789

fail() { echo "SMOKE FAIL: $*" >&2; docker logs "$NAME" 2>&1 | tail -40 >&2 || true; exit 1; }

# build-time step: compile container + router once; the workers only require() the result
rm -rf tests/Smoke/cache
php bin/slimfony warmup tests/Smoke/services.php tests/Smoke/routes.php tests/Smoke/cache

docker build -q -t "$IMAGE" tests/Smoke >/dev/null # the official image plus the apcu extension
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" -p 8080:8080 -v "$PWD":/app:ro "$IMAGE" \
  frankenphp run --config /app/tests/Smoke/Caddyfile >/dev/null

for _ in $(seq 1 40); do curl -fs "$BASE/ping" >/dev/null 2>&1 && break; sleep 0.5; done
[ "$(curl -s "$BASE/ping")" = "pong" ] || fail "/ping did not answer pong"

echo "== headers, errors, request id hardening"
headers=$(curl -si "$BASE/ping")
grep -qi '^x-content-type-options: nosniff' <<<"$headers" || fail "security headers missing"
grep -qi '^x-request-id: ' <<<"$headers" || fail "request id missing"
notfound=$(curl -si "$BASE/nope")
grep -q '^HTTP/.* 404' <<<"$notfound" || fail "expected 404"
grep -qi '^content-type: application/problem+json' <<<"$notfound" || fail "404 is not problem+json"
forged=$(curl -si -H 'X-Request-Id: bad id' "$BASE/ping" | grep -i '^x-request-id:' | tr -d '\r' | cut -d' ' -f2)
[ "$forged" != "bad id" ] && [ -n "$forged" ] || fail "forged request id was not replaced"

echo "== structured logs on real stdout"
curl -s -H 'X-Request-Id: smoke-log-0001' "$BASE/log" >/dev/null
logline=$(docker logs "$NAME" 2>&1 | grep '"message":"smoke log line"' | head -1)
[ -n "$logline" ] || fail "no JSON log line on stdout"
grep -q '"request_id":"smoke-log-0001"' <<<"$logline" || fail "log line is not tagged with the request id"
if grep -q 'must-not-appear' <<<"$logline"; then fail "a secret reached the log"; fi

echo "== state isolation under concurrency (3000 requests, 20 parallel)"
# one printf per request: curl writes body and -w output separately, parallel curls would interleave lines
req() { printf '%s
' "$(curl -s -H "X-Request-Id: smoke-req-$1" -w ' %{http_code} %header{x-request-id}' "$BASE/count")"; }
export -f req
export BASE
out=$(seq 1 3000 | xargs -P 20 -I{} bash -c 'req {}')
badlines=$(grep -v '^1 200 smoke-req-[0-9]*$' <<<"$out" || true)
[ -z "$badlines" ] || { echo "$badlines" | head -5 >&2; fail "counter state or status leaked between requests"; }
[ "$(sort -u <<<"$out" | wc -l)" = 3000 ] || fail "request ids crossed between requests"

echo "== health and metrics (shared across worker threads, surviving worker restarts)"
[ "$(curl -s "$BASE/healthz")" = "ok" ] || fail "/healthz is not ok"
[ "$(curl -s "$BASE/readyz")" = '{"status":"ok"}' ] || fail "/readyz is not ok"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/metrics")" = 401 ] || fail "/metrics must reject a request without the token"
metrics=$(curl -s -H "Authorization: Bearer $METRICS_TOKEN" "$BASE/metrics")
grep -q '^# TYPE http_requests_total counter' <<<"$metrics" || fail "no http_requests_total in /metrics"
# 3000 concurrent /count requests were served by 2 workers that restarted every 500 requests: no increment may be lost
grep -qF 'http_requests_total{method="GET",route="count",status="200"} 3000' <<<"$metrics" || { grep 'route="count"' <<<"$metrics" >&2; fail "request counter is not exactly 3000"; }
grep -qF 'http_request_duration_seconds_count{method="GET",route="count"} 3000' <<<"$metrics" || fail "histogram count is not exactly 3000"
grep -qF 'http_request_duration_seconds_bucket{method="GET",route="count",le="+Inf"} 3000' <<<"$metrics" || fail "histogram +Inf bucket is not exactly 3000"
! grep -q 'smoke-req-' <<<"$metrics" || fail "a raw request value leaked into a metric label"

echo "== memory plateau and worker restarts (5000 more requests)"
mem() { for _ in $(seq 1 30); do curl -s "$BASE/info"; echo; done | sort -n | tail -1; }
before=$(mem)
bad=$(seq 1 5000 | xargs -P 20 -I{} curl -s -o /dev/null -w '%{http_code}\n' "$BASE/ping" | grep -vc '^200$' || true)
[ "$bad" = 0 ] || fail "$bad non-200 responses while workers were restarting"
after=$(mem)
echo "max worker memory: before=$before after=$after"
[ $((after - before)) -le 4194304 ] || fail "memory grew by more than 4 MiB"

echo "== logs and graceful stop"
if docker logs "$NAME" 2>&1 | grep -E '"level":"(warning|error|critical)"|PHP (Fatal|Warning)|Stack trace|"level":"(error|critical)"'; then
  fail "errors in container logs"
fi
docker stop -t 20 "$NAME" >/dev/null
[ "$(docker inspect -f '{{.State.ExitCode}}' "$NAME")" = 0 ] || fail "container did not stop cleanly"
docker rm "$NAME" >/dev/null

echo "SMOKE OK"
