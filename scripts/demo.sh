#!/usr/bin/env bash
# The whole scenario on a fresh checkout: three tenants on one shared deployment, one dedicated deployment,
# interleaved requests and messages, fair use, and where failures land. Needs Docker and PHP 8.4.
set -euo pipefail
cd "$(dirname "$0")/.."

A=11111111-1111-4111-8111-111111111111
B=22222222-2222-4222-8222-222222222222
C=33333333-3333-4333-8333-333333333333
D=44444444-4444-4444-8444-444444444444   # the paying tenant, on its own deployment
SHARED="TENANT_MODE=multi MESSENGER_TRANSPORT_DSN=tenant-multi://127.0.0.1:56381?kind=main"
J='Content-Type: application/json'
S=http://127.0.0.1:8770
PIDS=()
cleanup() { kill "${PIDS[@]}" 2>/dev/null || true; }
trap cleanup EXIT

step() { printf '\n\033[1;36m== %s\033[0m\n' "$*"; }

step "1. Postgres and Redis"
docker compose up -d --wait
composer install --no-interaction --quiet

step "2. Place three tenants on the shared deployment, one on a dedicated one"
for u in $A $B $C; do env $SHARED bin/console app:tenant:place $u shared-1; done
env $SHARED bin/console app:tenant:place $D dedicated
env $SHARED bin/console app:tenants:list

step "3. Create and migrate every placed tenant's database, 3 at a time, one process per tenant"
env $SHARED bin/console app:tenants:migrate --concurrency=3
TENANT_UUID=$D bin/console doctrine:database:create --if-not-exists -n
TENANT_UUID=$D bin/console doctrine:migrations:migrate -n

step "4. Start one shared web process, two shared workers, and the dedicated tenant's own web process"
env $SHARED php -d variables_order=EGPCS -S 127.0.0.1:8770 -t public public/index.php >/dev/null 2>&1 & PIDS+=($!)
env $SHARED MESSENGER_CONSUMER_NAME=shared-worker-1 bin/console messenger:consume async --keepalive=30 --time-limit=300 2>&1 | sed 's/^/[worker-1] /' & PIDS+=($!)
env $SHARED MESSENGER_CONSUMER_NAME=shared-worker-2 bin/console messenger:consume async --keepalive=30 --time-limit=300 2>&1 | sed 's/^/[worker-2] /' & PIDS+=($!)
TENANT_MODE=single TENANT_UUID=$D php -d variables_order=EGPCS -S 127.0.0.1:8771 -t public public/index.php >/dev/null 2>&1 & PIDS+=($!)
sleep 2

step "5. The same process answers for each tenant from that tenant's database"
curl -s $S/tenant_$A/whoami; echo
curl -s $S/tenant_$B/whoami; echo

step "6. Interleaved writes; the same worker handles both, each in its own database"
curl -s -X POST -H "$J" -d '{"body":"hello from A"}' $S/tenant_$A/notes; echo
curl -s -X POST -H "$J" -d '{"body":"hello from B"}' $S/tenant_$B/notes; echo
sleep 2
echo "A sees:"; curl -s $S/tenant_$A/notes; echo
echo "B sees:"; curl -s $S/tenant_$B/notes; echo

step "7. The cache is one pool with a namespace per tenant"
curl -s $S/tenant_$A/notes/stats; echo
curl -s $S/tenant_$B/notes/stats; echo

step "8. Nothing is served without a tenant, or for a tenant placed elsewhere"
curl -s -o /dev/null -w '/notes -> %{http_code}\n' $S/notes
curl -s -o /dev/null -w '/tenant_<dedicated D>/notes on the shared deployment -> %{http_code}\n' $S/tenant_$D/notes
curl -s -o /dev/null -w '/health -> %{http_code}\n' $S/health

step "9. The dedicated deployment: same code, TENANT_MODE=single, its own database, with or without the prefix"
curl -s http://127.0.0.1:8771/whoami; echo
curl -s http://127.0.0.1:8771/tenant_$D/whoami; echo
curl -s -o /dev/null -w '/tenant_<A>/whoami on the dedicated deployment -> %{http_code}\n' http://127.0.0.1:8771/tenant_$A/whoami

step "10. Fair use: A queues four 4-second jobs, B posts a note right after; B is not stuck behind A"
for i in 1 2 3 4; do curl -s -X POST -H "$J" -d "{\"seconds\":4,\"label\":\"A-$i\"}" $S/tenant_$A/jobs >/dev/null; done
sleep 0.5
curl -s -X POST -H "$J" -d '{"body":"B during the A burst"}' $S/tenant_$B/notes >/dev/null
sleep 2
curl -s $S/tenant_$B/notes | php -r '$n = end(json_decode(stream_get_contents(STDIN), true)); printf("B note created %s, processed %s by %s\n", substr($n["createdAt"], 11, 8), substr($n["processedAt"] ?? "NOT YET", 11, 8), $n["processedBy"]);'
echo "in flight for A right now: $(docker compose exec -T redis redis-cli get tenant:inflight:$A:main)"
sleep 15

step "11. A failing job for B ends in B's own failed table, not in a shared one"
curl -s -X POST -H "$J" -d '{"seconds":1,"label":"fail"}' $S/tenant_$B/jobs; echo
sleep 8
docker compose exec -T database psql -U app -d tenant_22222222_2222_4222_8222_222222222222 -tAc "select 'B failed rows: ' || count(*) from messenger_messages where queue_name = 'failed'"
env $SHARED bin/console app:tenant:failed $B 2>/dev/null || true

step "12. What a tenant switch costs"
env $SHARED bin/console app:bench:switch --rounds=300

step "done"
