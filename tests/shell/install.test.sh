#!/usr/bin/env bash
# Behavioural tests for install.sh.
#
# Every docker/curl call is stubbed: no daemon, network or containers are
# touched. Scenarios cover a fresh install with and without a local build
# context, interactive input via the terminal, `curl … | bash`, piped
# answers, updates, and failure paths.
#
# Run from anywhere:  bash tests/shell/install.test.sh
# Optional deps: util-linux `script` and `setsid` (their tests are skipped
# when missing) and `timeout`/`gtimeout` (used as a hang guard).

set -u
HARNESS_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HARNESS_DIR/../.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/opensinop-install-tests.XXXXXX")"
PASS=0; FAIL=0

TIMEOUT_CMD=""
if command -v timeout >/dev/null 2>&1; then TIMEOUT_CMD="timeout 90"; fi
if [ -z "$TIMEOUT_CMD" ] && command -v gtimeout >/dev/null 2>&1; then TIMEOUT_CMD="gtimeout 90"; fi

trap 'rm -rf "$WORK"' EXIT

pass() { PASS=$((PASS+1)); printf '  PASS  %s\n' "$1"; }
fail() { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$1"; }

check() { # check <label> <condition-exit>
    if [ "$2" -eq 0 ]; then pass "$1"; else fail "$1"; fi
}

check_contains() { # check_contains <label> <file> <pattern>
    if grep -qE -e "$3" "$2"; then pass "$1"; else fail "$1 (missing '$3' in $2)"; fi
}

check_not_contains() {
    if grep -qE -e "$3" "$2"; then fail "$1 (unexpected '$3' in $2)"; else pass "$1"; fi
}

mkdir -p "$WORK/bin-ok" "$WORK/bin-daemon-down" "$WORK/bin-curl-fail"

# --- docker stub: logs every call, pretends the daemon and compose are fine ---
cat > "$WORK/bin-ok/docker" <<'STUB'
#!/usr/bin/env bash
printf 'docker %s\n' "$*" >> "${DOCKER_STUB_LOG:?}"
if [ "${1:-}" = "info" ]; then exit 0; fi
if [ "${1:-}" = "compose" ]; then
    shift
    case "${1:-}" in
        version) echo "Docker Compose version v2.40.0" ;;
        config) exit 0 ;;
        pull) exit 0 ;;
        up) exit 0 ;;
        ps) exit 0 ;;
        exec) exit 0 ;;
    esac
    exit 0
fi
if [ "${1:-}" = "inspect" ]; then echo "healthy"; exit 0; fi
exit 0
STUB
chmod +x "$WORK/bin-ok/docker"

cat > "$WORK/bin-daemon-down/docker" <<'STUB'
#!/usr/bin/env bash
printf 'docker %s\n' "$*" >> "${DOCKER_STUB_LOG:-/dev/null}"
if [ "${1:-}" = "info" ]; then
    echo "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?" >&2
    exit 1
fi
exit 0
STUB
chmod +x "$WORK/bin-daemon-down/docker"

cat > "$WORK/bin-curl-fail/curl" <<'STUB'
#!/usr/bin/env bash
echo "curl: (6) Could not resolve host: raw.githubusercontent.com" >&2
exit 6
STUB
chmod +x "$WORK/bin-curl-fail/curl"
cp "$WORK/bin-ok/docker" "$WORK/bin-curl-fail/docker"

make_fresh_dir() { # make_fresh_dir <name> [with-compose] [with-dockerfile]
    local dir="$WORK/$1"
    mkdir -p "$dir"
    cp "$REPO/install.sh" "$dir/install.sh"
    [ "${2:-yes}" = "yes" ] && cp "$REPO/docker-compose.yml" "$dir/docker-compose.yml"
    [ "${3:-no}" = "yes" ] && : > "$dir/Dockerfile"
    printf '%s' "$dir"
}

run_install() { # run_install <dir> <log> <command...>  (non-interactive helper)
    local dir="$1" log="$2"; shift 2
    ( cd "$dir" && env PATH="$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/docker.log" "$@" ) > "$log" 2>&1
}

echo "=== T1: fresh dir, --yes, no Dockerfile (image pull path) ==="
D1="$(make_fresh_dir t1)"
: > "$WORK/docker.log"
run_install "$D1" "$WORK/t1.log" bash install.sh --yes
check "T1 exit 0" $?
cp "$WORK/docker.log" "$WORK/t1.docker.log"
check_contains "T1 checks daemon" "$WORK/t1.docker.log" '^docker info$'
check_contains "T1 validates compose" "$WORK/t1.docker.log" '^docker compose config -q$'
check_contains "T1 pulls app image" "$WORK/t1.docker.log" '^docker compose pull app$'
check_contains "T1 starts containers" "$WORK/t1.docker.log" '^docker compose up -d$'
check_contains "T1 writes env" "$D1/.env" '^APP_NAME="OpenSinop"$'
check_contains "T1 writes institution" "$D1/.env" '^EDUFLOW_INSTITUTION="My School"$'
check_contains "T1 writes lepton driver" "$D1/.env" '^LEPTON_DRIVER="fake"$'
check_contains "T1 writes app key" "$D1/.env" '^APP_KEY="base64:.+"$'
check_contains "T1 writes app port" "$D1/.env" '^APP_PORT="[0-9]+"$'
check_contains "T1 completes" "$WORK/t1.log" 'Installation complete'
check_not_contains "T1 no hidden errors" "$WORK/t1.log" '\[error\]'

echo "=== T2: repo-style dir with Dockerfile (build path, no pull) ==="
D2="$(make_fresh_dir t2 yes yes)"
: > "$WORK/docker.log"
run_install "$D2" "$WORK/t2.log" bash install.sh --yes
check "T2 exit 0" $?
cp "$WORK/docker.log" "$WORK/t2.docker.log"
check_not_contains "T2 does not pull app image" "$WORK/t2.docker.log" '^docker compose pull app$'
check_contains "T2 starts containers" "$WORK/t2.docker.log" '^docker compose up -d$'
check_contains "T2 completes" "$WORK/t2.log" 'Installation complete'

echo "=== T3: interactive pty, cat install.sh | bash (curl | bash shape) ==="
if command -v script >/dev/null 2>&1; then
    D3="$(make_fresh_dir t3)"
    : > "$WORK/docker.log"
    yes '' | head -30 > "$WORK/t3.answers"
    ( cd "$D3" && DOCKER_STUB_LOG="$WORK/docker.log" $TIMEOUT_CMD script -qec "env PATH=\"$WORK/bin-ok:\$PATH\" bash -c 'cat install.sh | bash'" /dev/null < "$WORK/t3.answers" ) > "$WORK/t3.log" 2>&1
    check "T3 exit 0" $?
    cp "$WORK/docker.log" "$WORK/t3.docker.log"
    check_contains "T3 shows first prompt" "$WORK/t3.log" 'Application name'
    check_contains "T3 shows institution prompt" "$WORK/t3.log" 'Institution name'
    check_contains "T3 shows treasury prompt" "$WORK/t3.log" 'Treasury wallet address'
    check_contains "T3 shows review prompt" "$WORK/t3.log" 'Write this configuration and start Docker'
    check_contains "T3 writes env" "$D3/.env" '^EDUFLOW_INSTITUTION="My School"$'
    check_contains "T3 reaches container start" "$WORK/t3.docker.log" '^docker compose up -d$'
    check_contains "T3 completes" "$WORK/t3.log" 'Installation complete'
    check_not_contains "T3 no hidden errors" "$WORK/t3.log" '\[error\]'
else
    echo "  SKIP  T3 (util-linux 'script' not available)"
fi

echo "=== T4: piped answers into script file (bash install.sh < answers) ==="
D4="$(make_fresh_dir t4)"
: > "$WORK/docker.log"
{ printf 'Piped School\n\n\n\nSeed High\n'; yes '' | head -25; } > "$WORK/t4.answers"
( cd "$D4" && env PATH="$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/docker.log" bash install.sh < "$WORK/t4.answers" ) > "$WORK/t4.log" 2>&1
check "T4 exit 0" $?
check_contains "T4 consumes piped answer for name" "$D4/.env" '^APP_NAME="Piped School"$'
check_contains "T4 consumes piped answer for institution" "$D4/.env" '^EDUFLOW_INSTITUTION="Seed High"$'
check_contains "T4 completes" "$WORK/t4.log" 'Installation complete'
check_not_contains "T4 no hidden errors" "$WORK/t4.log" '\[error\]'

echo "=== T5: cat install.sh | bash with no terminal (CI shape) ==="
if command -v setsid >/dev/null 2>&1; then
    D5="$(make_fresh_dir t5)"
    : > "$WORK/docker.log"
    ( cd "$D5" && env PATH="$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/docker.log" $TIMEOUT_CMD setsid bash -c 'cat install.sh | bash' ) > "$WORK/t5.log" 2>&1
    check "T5 exit 0" $?
    check_contains "T5 warns about defaults" "$WORK/t5.log" 'No terminal available'
    check_contains "T5 writes env" "$D5/.env" '^APP_NAME="OpenSinop"$'
    check_contains "T5 completes" "$WORK/t5.log" 'Installation complete'
else
    echo "  SKIP  T5 (setsid not available)"
fi

echo "=== T6: --update on an existing deployment ==="
D6="$WORK/t6"
mkdir -p "$D6"
cp "$REPO/install.sh" "$REPO/docker-compose.yml" "$D6/"
# Deliberately sparse .env: missing DB_USERNAME must not kill the update path.
printf 'DB_CONNECTION="pgsql"\nAPP_URL="http://localhost:8000"\n' > "$D6/.env"
: > "$WORK/docker.log"
run_install "$D6" "$WORK/t6.log" bash install.sh --update
check "T6 exit 0" $?
cp "$WORK/docker.log" "$WORK/t6.docker.log"
check_contains "T6 checks daemon" "$WORK/t6.docker.log" '^docker info$'
check_contains "T6 pulls images" "$WORK/t6.docker.log" '^docker compose pull$'
check_contains "T6 starts containers" "$WORK/t6.docker.log" '^docker compose up -d$'
check_contains "T6 completes" "$WORK/t6.log" 'Update complete'
check "T6 leaves no backups dir" $([ ! -d "$D6/backups" ] && echo 0 || echo 1)

echo "=== T7: daemon down -> clear error, nothing written ==="
D7="$(make_fresh_dir t7)"
( cd "$D7" && env PATH="$WORK/bin-daemon-down:$PATH" DOCKER_STUB_LOG="$WORK/docker.log" bash install.sh --yes ) > "$WORK/t7.log" 2>&1
RC7=$?
check "T7 exit 1" $([ "$RC7" -ne 0 ] && echo 0 || echo 1)
check_contains "T7 explains daemon" "$WORK/t7.log" 'daemon is not reachable'
check "T7 no .env written" $([ ! -f "$D7/.env" ] && echo 0 || echo 1)

echo "=== T8: compose download failure -> clear error, nothing written ==="
D8="$(make_fresh_dir t8 no)"
( cd "$D8" && env PATH="$WORK/bin-curl-fail:$PATH" DOCKER_STUB_LOG="$WORK/docker.log" bash install.sh --yes ) > "$WORK/t8.log" 2>&1
RC8=$?
check "T8 exit 1" $([ "$RC8" -ne 0 ] && echo 0 || echo 1)
check_contains "T8 explains download" "$WORK/t8.log" 'Could not download docker-compose.yml'
check "T8 no .env written" $([ ! -f "$D8/.env" ] && echo 0 || echo 1)

echo "=== T9: --help ==="
( cd "$WORK" && bash "$REPO/install.sh" --help ) > "$WORK/t9.log" 2>&1
check "T9 exit 0" $?
check_contains "T9 shows usage" "$WORK/t9.log" 'Usage: install\.sh'
check_contains "T9 documents --update" "$WORK/t9.log" '--update'

echo
echo "RESULT: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
