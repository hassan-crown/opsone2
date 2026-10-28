#!/bin/bash
# Concurrency check (NFR-13, docs/performance.md §4.2): signs in once (demo seed user locally; PERF_BASE/PERF_PASSWORD elsewhere), then ab per page.
# Usage: load.sh <email> <concurrency> <requests> <path>...
set -e
BASE=${PERF_BASE:-http://127.0.0.1:8084}
HOST=${PERF_HOST:-localhost}
EMAIL=$1; C=$2; N=$3; shift 3
JAR="${TMPDIR:-/tmp}/opsone-perf-jar-$EMAIL"
if [ ! -s $JAR.ok ]; then
curl -s -c $JAR -b $JAR -H "Host: $HOST" $BASE/login -o /dev/null
XSRF=$(sed 's/^#HttpOnly_//' $JAR | awk '$6=="XSRF-TOKEN"{print $7}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
code=$(curl -s -o /dev/null -w '%{http_code}' -c $JAR -b $JAR -H "Host: $HOST" -H "X-XSRF-TOKEN: $XSRF" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"${PERF_PASSWORD:-password}\"}" $BASE/login)
echo "login: $code"
[ "$code" = 200 ] && echo ok > $JAR.ok
fi
COOKIE=$(sed 's/^#HttpOnly_//' $JAR | awk 'NF>=7 && $1 !~ /^#/ {printf "%s=%s; ", $6, $7}')
VER=$(curl -s -b $JAR -H "Host: $HOST" $BASE/dashboard | grep -o '"version":"[^"]*"' | head -1 | cut -d'"' -f4)
ONCE=$(curl -s -b $JAR -H "Host: $HOST" -H 'X-Inertia: true' -H "X-Inertia-Version: $VER" $BASE/dashboard | python3 -c 'import sys,json;d=json.loads(sys.stdin.read());print(",".join((d.get("onceProps") or {}).keys()))')
echo "version=$VER once=$ONCE"
for P in "$@"; do
  echo "== $P"
  ab -q -n $N -c $C -C "${COOKIE%; }" -H "Host: $HOST" -H 'X-Inertia: true' -H "X-Inertia-Version: $VER" -H "X-Inertia-Except-Once-Props: $ONCE" -H 'Accept: text/html' "$BASE$P" 2>&1 \
    | grep -E "Non-2xx|Complete requests|Failed requests|Non-2xx|Requests per second|Time per request.*mean\)|^  50%|^  95%|^  99%|^ 100%"
done
