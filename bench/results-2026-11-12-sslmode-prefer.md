# tenant-shared-demo under load, 2026-11-12 14:48 UTC

GET /tenant_<uuid>/notes, three tenants in turn, 20 notes each. wrk -t4 -c32 -d20s, 10 s warm-up before each run.
Each app container: 4 CPUs, 8 PHP threads or processes, PHP 8.4.27, opcache on, APP_ENV=prod, DATABASE_URL sslmode=prefer. Docker Desktop on Apple M3 Max.

| deployment | requests/s | avg latency | p50 | p99 | max | errors | DB connections opened | RSS after |
|---|---|---|---|---|---|---|---|---|
| fpm-nginx | 690.05 | 46.28ms | 45.44ms | 70.09ms | 81.35ms | 0 | 13892 | 41.62MiB |
| frankenphp | 365.72 | 87.06ms | 90.30ms | 113.40ms | 153.72ms | 0 | 7383 | 77.95MiB |
| frankenphp-worker | 553.01 | 57.70ms | 64.33ms | 88.14ms | 280.13ms | 0 | 7771 | 68.65MiB |
| frankenphp-worker-keep | 8520.52 | 3.86ms | 3.38ms | 10.65ms | 41.41ms | 0 | 1026 | 75.39MiB |
