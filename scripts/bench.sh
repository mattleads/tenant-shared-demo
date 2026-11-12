#!/usr/bin/env bash
# The shared deployment under load, four ways: php-fpm behind nginx, FrankenPHP classic, FrankenPHP worker mode, and
# worker mode keeping one connection per tenant database (DATABASE_URL ...&tenantConnections=16). Every request names
# one of three tenants in turn (scripts/bench/tenants.lua). Needs Docker, wrk and the tenants of scripts/demo.sh.
#
#   scripts/bench.sh            # build, warm up, run, write bench/results-<date>.md
#   DURATION=30s CONNECTIONS=32 THREADS=4 scripts/bench.sh
#   PG_SSLMODE=disable scripts/bench.sh   # no SSL negotiation on connect (libpq serializes it across threads)
set -euo pipefail
cd "$(dirname "$0")/.."

DURATION=${DURATION:-20s}
CONNECTIONS=${CONNECTIONS:-32}
THREADS=${THREADS:-4}
NOTES=${NOTES:-20}
COMPOSE="docker compose -f compose.yaml -f compose.bench.yaml --profile bench"
A=11111111-1111-4111-8111-111111111111
B=22222222-2222-4222-8222-222222222222
C=33333333-3333-4333-8333-333333333333
PG_SSLMODE=${PG_SSLMODE:-prefer}
export PG_SSLMODE
OUT=bench/results-$(date +%Y-%m-%d)-sslmode-$PG_SSLMODE.md

NAMES="fpm-nginx frankenphp frankenphp-worker frankenphp-worker-keep"
# bash 3 on macOS has no associative arrays
port_of() { case $1 in fpm-nginx) echo 8780;; frankenphp) echo 8781;; frankenphp-worker) echo 8782;; frankenphp-worker-keep) echo 8783;; esac; }
container_of() { case $1 in fpm-nginx) echo tenant-shared-demo-fpm-1;; *) echo "tenant-shared-demo-$1-1";; esac; }

step() { printf '\n\033[1;36m== %s\033[0m\n' "$*"; }
# Connections opened to the tenant databases so far, counted by Postgres itself (pg_stat_database.sessions).
sessions() { docker exec tenant-shared-demo-database-1 psql -U app -d app -tAc "select coalesce(sum(sessions),0) from pg_stat_database where datname like 'tenant_%'"; }

step "1. Build and start the four deployments on the same Postgres and Redis"
$COMPOSE up -d --build --wait
# nginx resolves the fpm upstream once at start; a rebuilt fpm container has a new address
$COMPOSE restart nginx >/dev/null
for name in $NAMES; do
  for _ in $(seq 1 30); do curl -sf "http://127.0.0.1:$(port_of "$name")/health" >/dev/null && break; sleep 1; done
done

step "2. Every tenant has $NOTES notes"
for u in $A $B $C; do
  have=$(curl -s "http://127.0.0.1:8781/tenant_$u/notes" | php -r 'echo count(json_decode(stream_get_contents(STDIN), true) ?: []);')
  for i in $(seq "$have" $((NOTES - 1))); do
    curl -s -o /dev/null -X POST -H 'Content-Type: application/json' -d "{\"body\":\"note $i\"}" "http://127.0.0.1:8781/tenant_$u/notes"
  done
done

{
  echo "# tenant-shared-demo under load, $(date -u +'%Y-%m-%d %H:%M UTC')"
  echo
  echo "GET /tenant_<uuid>/notes, three tenants in turn, $NOTES notes each. wrk -t$THREADS -c$CONNECTIONS -d$DURATION, 10 s warm-up before each run."
  echo "Each app container: 4 CPUs, 8 PHP threads or processes, PHP $(docker run --rm dunglas/frankenphp:1-php8.4 php -r 'echo PHP_VERSION;'), opcache on, APP_ENV=prod, DATABASE_URL sslmode=$PG_SSLMODE. Docker Desktop on $(sysctl -n machdep.cpu.brand_string 2>/dev/null || uname -m)."
  echo
  echo "| deployment | requests/s | avg latency | p50 | p99 | max | errors | DB connections opened | RSS after |"
  echo "|---|---|---|---|---|---|---|---|---|"
} > "$OUT"

for name in $NAMES; do
  port=$(port_of "$name")
  step "3. $name on :$port"
  wrk -t"$THREADS" -c"$CONNECTIONS" -d10s -s scripts/bench/tenants.lua "http://127.0.0.1:$port" >/dev/null
  before=$(sessions)
  result=$(wrk -t"$THREADS" -c"$CONNECTIONS" -d"$DURATION" --latency -s scripts/bench/tenants.lua "http://127.0.0.1:$port")
  opened=$(( $(sessions) - before ))
  echo "$result" | tee "bench/$name.txt"
  rps=$(echo "$result" | awk '/Requests\/sec/ {print $2}')
  avg=$(echo "$result" | awk '/^ +Latency +[0-9]/ {print $2}')
  p50=$(echo "$result" | awk '/ 50%/ {print $2}')
  p99=$(echo "$result" | awk '/ 99%/ {print $2}')
  max=$(echo "$result" | awk '/^ +Latency +[0-9]/ {print $4}')
  errors=$(echo "$result" | awk '/Socket errors|Non-2xx/ {found=1; printf "%s ", $0} END {if (!found) print "0"}')
  rss=$(docker stats --no-stream --format '{{.MemUsage}}' "$(container_of "$name")" | cut -d/ -f1 | tr -d ' ')
  echo "| $name | $rps | $avg | $p50 | $p99 | $max | $errors | $opened | $rss |" >> "$OUT"
  echo "database connections opened during the run: $opened"
  echo "whoami after the run:"; curl -s "http://127.0.0.1:$port/tenant_$A/whoami"; echo
done

step "4. Results in $OUT"
cat "$OUT"
