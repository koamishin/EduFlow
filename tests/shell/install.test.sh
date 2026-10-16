#!/usr/bin/env bash
# Behavioural tests for install.sh.
#
# Every docker/curl/sudo/sh/systemctl call is stubbed: no daemon, network,
# containers or package managers are touched. Scenarios cover a fresh install
# (image pull and local build paths), interactive input via the terminal,
# `curl … | bash`, piped answers, updates, failure paths, Docker Engine
# auto-install on a host without Docker, re-runs, SQLite, LXC hosts, a
# sudo-only Docker host and the container entrypoint's secret file handling.
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

check() { # check <label> <numeric-exit> (0 = pass)
    if [ "$2" -eq 0 ]; then pass "$1"; else fail "$1"; fi
}

check_contains() { # check_contains <label> <file> <pattern>
    if grep -qE -e "$3" "$2"; then pass "$1"; else fail "$1 (missing '$3' in $2)"; fi
}

check_not_contains() {
    if grep -qE -e "$3" "$2"; then fail "$1 (unexpected '$3' in $2)"; else pass "$1"; fi
}

mkdir -p "$WORK/bin-ok" "$WORK/bin-sudo" "$WORK/bin-daemon-down" "$WORK/bin-autoinstall" "$WORK/minimal-bin"

# --- curl stub: logs calls; answers Arc RPC; "downloads" get.docker.com ---
write_curl_stub() {
    cat > "$1" <<'STUB'
#!/usr/bin/env bash
printf 'curl %s\n' "$*" >> "${DOCKER_STUB_LOG:?}"
case "$*" in
    *get.docker.com*)
        cat <<'EOS'
#!/bin/sh
dir="$(dirname "$DOCKER_STUB_LOG")"
echo "fake get.docker.com: installing Docker Engine stub"
mkdir -p "$dir/bin-autoinstall"
cp "$dir/bin-ok/docker" "$dir/bin-autoinstall/docker"
chmod +x "$dir/bin-autoinstall/docker"
exit 0
EOS
        ;;
    *eth_chainId*)
        printf '{"jsonrpc":"2.0","id":1,"result":"0x4cef52"}\n'
        ;;
    *)
        printf 'curl: (6) Could not resolve host\n' >&2
        exit 6
        ;;
esac
STUB
    chmod +x "$1"
}

# --- docker stub: logs every call, keeps swarm state in $STUB_STATE ---
cat > "$WORK/bin-ok/docker" <<'STUB'
#!/usr/bin/env bash
printf 'docker %s\n' "$*" >> "${DOCKER_STUB_LOG:?}"
state="${STUB_STATE:?}"
mkdir -p "$state"

arg_after() {
    local flag="$1" prev="" a
    shift
    for a in "$@"; do
        if [ "$prev" = "$flag" ]; then printf '%s' "$a"; return 0; fi
        prev="$a"
    done
    return 1
}

filter_name() {
    local raw
    raw="$(arg_after "-f" "$@" || true)"
    case "$raw" in name=*) raw="${raw#name=}" ;; esac
    raw="${raw#^}"
    raw="${raw%$}"
    printf '%s' "$raw"
}

cmd="${1:-}"
sub="${2:-}"
case "$cmd" in
    info)
        case "$*" in
            *LocalNodeState*) printf '%s\n' "${STUB_SWARM_STATE:-inactive}" ;;
            *ControlAvailable*) printf '%s\n' "${STUB_SWARM_CONTROL:-false}" ;;
        esac
        exit 0
        ;;
    swarm) exit 0 ;;
    network)
        case "$sub" in
            ls)
                [ -f "$state/net-$(filter_name "$@")" ] && printf 'netid1\n'
                exit 0
                ;;
            create)
                netname=""
                for a in "$@"; do
                    case "$a" in -*) ;; *) netname="$a" ;; esac
                done
                : > "$state/net-$netname"
                exit 0
                ;;
        esac
        exit 0
        ;;
    secret)
        case "$sub" in
            ls)
                [ -f "$state/secret-$(filter_name "$@")" ] && printf 'secid1\n'
                exit 0
                ;;
            create)
                secname="${3:-}"
                if [ -f "$state/secret-$secname" ]; then
                    printf 'Error response from daemon: secret %s already exists\n' "$secname" >&2
                    exit 1
                fi
                cat > "$state/secret-$secname.value"
                : > "$state/secret-$secname"
                printf 'secid1\n'
                exit 0
                ;;
        esac
        exit 0
        ;;
    service)
        case "$sub" in
            ls)
                [ -f "$state/svc-$(filter_name "$@")" ] && printf 'svcid1\n'
                exit 0
                ;;
            create)
                svcname="$(arg_after --name "$@" || true)"
                if [ -n "$svcname" ] && [ -f "$state/svc-$svcname" ]; then
                    printf 'Error response from daemon: service %s already exists\n' "$svcname" >&2
                    exit 1
                fi
                if [ -n "$svcname" ]; then : > "$state/svc-$svcname"; fi
                printf 'svcid1\n'
                exit 0
                ;;
            rm)
                rm -f "$state/svc-${3:-}"
                printf '%s\n' "${3:-}"
                exit 0
                ;;
            update) exit 0 ;;
        esac
        exit 0
        ;;
    ps)
        svcname=""; prev=""
        for a in "$@"; do
            if [ "$prev" = "-f" ]; then
                case "$a" in
                    label=com.docker.swarm.service.name=*) svcname="${a#label=com.docker.swarm.service.name=}" ;;
                esac
            fi
            prev="$a"
        done
        if [ -n "$svcname" ] && [ -f "$state/svc-$svcname" ]; then
            printf '%s\n' "${svcname}cid"
        fi
        exit 0
        ;;
    inspect) printf 'healthy\n'; exit 0 ;;
    exec) exit 0 ;;
    build) exit 0 ;;
    pull)
        if [ "${STUB_FAIL_PULL:-}" = "1" ]; then
            printf 'Error response from daemon: Head "https://ghcr.io/v2/koamishin/opensinop/manifests/latest": unauthorized\n' >&2
            exit 1
        fi
        exit 0
        ;;
    version) printf 'Docker version 28.5.0, build test\n'; exit 0 ;;
esac
exit 0
STUB
chmod +x "$WORK/bin-ok/docker"
write_curl_stub "$WORK/bin-ok/curl"

# --- sudo-required host: plain `docker info` is denied, sudo works ---
cat > "$WORK/bin-sudo/docker" <<STUB
#!/usr/bin/env bash
if [ "\${1:-}" = "info" ] && [ "\${SUDO_INVOKED:-}" != "1" ]; then
    printf 'permission denied while trying to connect to the Docker daemon socket at unix:///var/run/docker.sock\n' >&2
    exit 1
fi
exec "$WORK/bin-ok/docker" "\$@"
STUB
chmod +x "$WORK/bin-sudo/docker"
cat > "$WORK/bin-sudo/sudo" <<'STUB'
#!/usr/bin/env bash
printf 'sudo %s\n' "$*" >> "${DOCKER_STUB_LOG:?}"
exec env SUDO_INVOKED=1 "$@"
STUB
chmod +x "$WORK/bin-sudo/sudo"

# --- daemon down: every docker call fails ---
cat > "$WORK/bin-daemon-down/docker" <<'STUB'
#!/usr/bin/env bash
printf 'docker %s\n' "$*" >> "${DOCKER_STUB_LOG:-/dev/null}"
printf 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?\n' >&2
exit 1
STUB
chmod +x "$WORK/bin-daemon-down/docker"
cat > "$WORK/bin-daemon-down/sudo" <<'STUB'
#!/usr/bin/env bash
printf 'sudo %s\n' "$*" >> "${DOCKER_STUB_LOG:-/dev/null}"
exit 1
STUB
chmod +x "$WORK/bin-daemon-down/sudo"

# --- host without Docker: auto-install path (exercised with a tiny PATH) ---
cat > "$WORK/bin-autoinstall/sh" <<'STUB'
#!/usr/bin/env bash
printf 'sh %s\n' "$*" >> "${DOCKER_STUB_LOG:?}"
exec /bin/sh "$@"
STUB
chmod +x "$WORK/bin-autoinstall/sh"
cat > "$WORK/bin-autoinstall/sudo" <<'STUB'
#!/usr/bin/env bash
printf 'sudo %s\n' "$*" >> "${DOCKER_STUB_LOG:?}"
exec "$@"
STUB
chmod +x "$WORK/bin-autoinstall/sudo"
for tool in apt-mark systemctl usermod; do
    cat > "$WORK/bin-autoinstall/$tool" <<'STUB'
#!/usr/bin/env bash
printf '%s %s\n' "$(basename "$0")" "$*" >> "${DOCKER_STUB_LOG:?}"
exit 0
STUB
    chmod +x "$WORK/bin-autoinstall/$tool"
done
write_curl_stub "$WORK/bin-autoinstall/curl"

# A PATH that hides every real tool the installer might otherwise find, so a
# missing Docker is genuinely missing (used only by the auto-install test).
for tool in bash env sh uname id grep sed cut head tail tr cat date openssl \
            mkdir chmod rm cp mv sleep hostname touch ls awk base64 dirname basename; do
    src="$(command -v "$tool" 2>/dev/null || true)"
    case "$src" in
        /*) ln -sf "$src" "$WORK/minimal-bin/$tool" ;;
    esac
done

make_fresh_dir() { # make_fresh_dir <name> [with-dockerfile]
    local dir="$WORK/$1"
    mkdir -p "$dir"
    cp "$REPO/install.sh" "$dir/install.sh"
    if [ "${2:-no}" = "yes" ]; then : > "$dir/Dockerfile"; fi
    printf '%s' "$dir"
}

run_install() { # run_install <dir> <log> <state> <command...>  (stubbed PATH)
    local dir="$1" log="$2" state="$3"; shift 3
    mkdir -p "$state"
    ( cd "$dir" && env PATH="$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="${log%.log}.docker.log" STUB_STATE="$state" "$@" ) > "$log" 2>&1
}

echo "=== T1: fresh dir, --yes, no Dockerfile (image pull path) ==="
D1="$(make_fresh_dir t1)"
run_install "$D1" "$WORK/t1.log" "$WORK/state-t1" bash install.sh --yes
check "T1 exit 0" $?
L1="$WORK/t1.docker.log"
check_contains "T1 checks daemon" "$L1" '^docker info$'
check_contains "T1 initializes swarm" "$L1" '^docker swarm init'
check_contains "T1 creates overlay network" "$L1" '^docker network create --driver overlay --attachable opensinop-network$'
check_contains "T1 creates db secret" "$L1" '^docker secret create opensinop_db_password -$'
check_contains "T1 creates db service" "$L1" '^docker service create --name opensinop-db .*--env POSTGRES_PASSWORD_FILE=/run/secrets/opensinop_db_password'
check_contains "T1 db service mounts secret" "$L1" '^docker service create --name opensinop-db .*--secret source=opensinop_db_password'
check_contains "T1 creates redis service" "$L1" '^docker service create --name opensinop-redis .*redis:7-alpine$'
check_contains "T1 creates app service" "$L1" '^docker service create --name opensinop --replicas 1 --network opensinop-network'
check_contains "T1 publishes app port" "$L1" '^docker service create --name opensinop .*--publish mode=host,published=[0-9]+,target=8000'
check_contains "T1 mounts storage volume" "$L1" '^docker service create --name opensinop .*--mount type=volume,source=opensinop_storage,target=/app/storage'
check_contains "T1 wires health check" "$L1" '^docker service create --name opensinop .*--health-cmd healthcheck'
check_contains "T1 mounts secret into app" "$L1" '^docker service create --name opensinop .*--secret source=opensinop_db_password'
check_contains "T1 passes env to app" "$L1" ' -e APP_NAME=OpenSinop '
check_contains "T1 passes secret file env" "$L1" ' -e DB_PASSWORD_FILE=/run/secrets/opensinop_db_password'
check_contains "T1 pulls app image" "$L1" '^docker pull ghcr.io/koamishin/opensinop:latest$'
check_not_contains "T1 pulls no resolve flag for image from registry" "$L1" '--no-resolve-image'
check_contains "T1 waits for postgres" "$L1" '^docker exec opensinop-dbcid pg_isready -U opensinop -d opensinop$'
check_contains "T1 writes env name" "$D1/.env" '^APP_NAME="OpenSinop"$'
check_contains "T1 writes institution" "$D1/.env" '^EDUFLOW_INSTITUTION="My School"$'
check_contains "T1 writes lepton driver" "$D1/.env" '^LEPTON_DRIVER="fake"$'
check_contains "T1 writes app key" "$D1/.env" '^APP_KEY="base64:.+"$'
check_contains "T1 writes app port" "$D1/.env" '^APP_PORT="[0-9]+"$'
check_contains "T1 writes random db password" "$D1/.env" '^DB_PASSWORD="[A-Za-z0-9+/=]{20,}"$'
check_contains "T1 points app at db service" "$D1/.env" '^DB_HOST="opensinop-db"$'
check_contains "T1 points app at redis service" "$D1/.env" '^REDIS_HOST="opensinop-redis"$'
check_contains "T1 prints admin bootstrap command" "$WORK/t1.log" 'app:bootstrap-admin'
check_contains "T1 completes" "$WORK/t1.log" 'Installation complete'
check_not_contains "T1 no hidden errors" "$WORK/t1.log" '\[error\]'
env_pw="$(grep -E '^DB_PASSWORD=' "$D1/.env" | cut -d= -f2- | tr -d '"')"
secret_pw="$(cat "$WORK/state-t1/secret-opensinop_db_password.value" 2>/dev/null || true)"
if [ -n "$env_pw" ] && [ "$env_pw" = "$secret_pw" ]; then
    pass "T1 secret value matches .env"
else
    fail "T1 secret value matches .env"
fi

echo "=== T2: repo-style dir with Dockerfile (build path, no pull) ==="
D2="$(make_fresh_dir t2 yes)"
run_install "$D2" "$WORK/t2.log" "$WORK/state-t2" bash install.sh --yes
check "T2 exit 0" $?
L2="$WORK/t2.docker.log"
check_contains "T2 builds image" "$L2" '^docker build -t ghcr.io/koamishin/opensinop:latest -f .*Dockerfile .*'
check_not_contains "T2 skips pull" "$L2" '^docker pull'
check_contains "T2 skips image resolution" "$L2" '^docker service create --name opensinop .*--no-resolve-image'
check_contains "T2 creates db service" "$L2" '^docker service create --name opensinop-db '
check_contains "T2 completes" "$WORK/t2.log" 'Installation complete'

echo "=== T2b: image pull failure -> clear error, nothing started ==="
D2B="$(make_fresh_dir t2b)"
( cd "$D2B" && env PATH="$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/t2b.docker.log" STUB_STATE="$WORK/state-t2b" STUB_FAIL_PULL=1 bash install.sh --yes ) > "$WORK/t2b.log" 2>&1
RC2B=$?
check "T2b exit 1" $([ "$RC2B" -ne 0 ] && echo 0 || echo 1)
check_contains "T2b explains the pull failure" "$WORK/t2b.log" 'Could not pull the application image'
check_contains "T2b suggests ghcr login" "$WORK/t2b.log" 'docker login ghcr.io'
check_not_contains "T2b starts nothing" "$WORK/t2b.docker.log" '^docker service create'

echo "=== T3: interactive pty, cat install.sh | bash (curl | bash shape) ==="
if command -v script >/dev/null 2>&1; then
    D3="$(make_fresh_dir t3)"
    yes '' | head -30 > "$WORK/t3.answers"
    ( cd "$D3" && DOCKER_STUB_LOG="$WORK/t3.docker.log" STUB_STATE="$WORK/state-t3" $TIMEOUT_CMD script -qec "env PATH=\"$WORK/bin-ok:\$PATH\" bash -c 'cat install.sh | bash'" /dev/null < "$WORK/t3.answers" ) > "$WORK/t3.log" 2>&1
    check "T3 exit 0" $?
    check_contains "T3 shows first prompt" "$WORK/t3.log" 'Application name'
    check_contains "T3 shows institution prompt" "$WORK/t3.log" 'Institution name'
    check_contains "T3 shows treasury prompt" "$WORK/t3.log" 'Treasury wallet address'
    check_contains "T3 shows review prompt" "$WORK/t3.log" 'Write this configuration and start Docker'
    check_contains "T3 creates app service" "$WORK/t3.docker.log" '^docker service create --name opensinop '
    check_contains "T3 writes env" "$D3/.env" '^EDUFLOW_INSTITUTION="My School"$'
    check_contains "T3 completes" "$WORK/t3.log" 'Installation complete'
    check_not_contains "T3 no hidden errors" "$WORK/t3.log" '\[error\]'
else
    echo "  SKIP  T3 (util-linux 'script' not available)"
fi

echo "=== T4: piped answers into script file (bash install.sh < answers) ==="
D4="$(make_fresh_dir t4)"
{ printf 'Piped School\n\n\n\nSeed High\n'; yes '' | head -25; } > "$WORK/t4.answers"
( cd "$D4" && env PATH="$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/t4.docker.log" STUB_STATE="$WORK/state-t4" bash install.sh < "$WORK/t4.answers" ) > "$WORK/t4.log" 2>&1
check "T4 exit 0" $?
check_contains "T4 consumes piped answer for name" "$D4/.env" '^APP_NAME="Piped School"$'
check_contains "T4 consumes piped answer for institution" "$D4/.env" '^EDUFLOW_INSTITUTION="Seed High"$'
check_contains "T4 completes" "$WORK/t4.log" 'Installation complete'
check_not_contains "T4 no hidden errors" "$WORK/t4.log" '\[error\]'

echo "=== T4b: fish shell pipe form (fish has no process substitution) ==="
if command -v fish >/dev/null 2>&1; then
    D4B="$(make_fresh_dir t4b)"
    ( cd "$D4B" && env PATH="$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/t4b.docker.log" STUB_STATE="$WORK/state-t4b" $TIMEOUT_CMD fish -c 'cat install.sh | bash -s -- --yes' ) > "$WORK/t4b.log" 2>&1
    check "T4b exit 0" $?
    check_contains "T4b writes env" "$D4B/.env" '^APP_NAME="OpenSinop"$'
    check_contains "T4b completes" "$WORK/t4b.log" 'Installation complete'
else
    echo "  SKIP  T4b (fish not installed on this machine)"
fi

echo "=== T5: cat install.sh | bash with no terminal (CI shape) ==="
if command -v setsid >/dev/null 2>&1; then
    D5="$(make_fresh_dir t5)"
    ( cd "$D5" && env PATH="$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/t5.docker.log" STUB_STATE="$WORK/state-t5" $TIMEOUT_CMD setsid bash -c 'cat install.sh | bash' ) > "$WORK/t5.log" 2>&1
    check "T5 exit 0" $?
    check_contains "T5 warns about defaults" "$WORK/t5.log" 'No terminal available'
    check_contains "T5 writes env" "$D5/.env" '^APP_NAME="OpenSinop"$'
    check_contains "T5 completes" "$WORK/t5.log" 'Installation complete'
else
    echo "  SKIP  T5 (setsid not available)"
fi

echo "=== T6: --update rolls the app service and backs up PostgreSQL ==="
D6="$WORK/t6"
mkdir -p "$D6" "$WORK/state-t6"
cp "$REPO/install.sh" "$D6/"
# Deliberately sparse .env: missing DB_USERNAME must not kill the update path.
printf 'DB_CONNECTION="pgsql"\nAPP_URL="http://localhost:8000"\n' > "$D6/.env"
: > "$WORK/state-t6/svc-opensinop"
: > "$WORK/state-t6/svc-opensinop-db"
run_install "$D6" "$WORK/t6.log" "$WORK/state-t6" bash install.sh --update
check "T6 exit 0" $?
L6="$WORK/t6.docker.log"
check_contains "T6 checks daemon" "$L6" '^docker info$'
check_contains "T6 finds the app service" "$L6" '^docker service ls -q -f name='
check_contains "T6 dumps the database" "$L6" '^docker exec opensinop-dbcid sh -c pg_dump'
check_contains "T6 pulls the image" "$L6" '^docker pull ghcr.io/koamishin/opensinop:latest$'
check_contains "T6 updates the service" "$L6" '^docker service update --image ghcr.io/koamishin/opensinop:latest opensinop$'
check_contains "T6 notes that .env waits" "$WORK/t6.log" 'full re-run of install.sh'
check_contains "T6 completes" "$WORK/t6.log" 'Update complete'
check_not_contains "T6 builds without a Dockerfile" "$L6" '^docker build'
check "T6 writes a backup file" $([ -n "$(ls "$D6/backups"/*.sql 2>/dev/null)" ] && echo 0 || echo 1)

echo "=== T6b: --update without a service -> clear error ==="
D6B="$WORK/t6b"
mkdir -p "$D6B" "$WORK/state-t6b"
cp "$REPO/install.sh" "$D6B/"
printf 'DB_CONNECTION="pgsql"\n' > "$D6B/.env"
run_install "$D6B" "$WORK/t6b.log" "$WORK/state-t6b" bash install.sh --update
RC6B=$?
check "T6b exit 1" $([ "$RC6B" -ne 0 ] && echo 0 || echo 1)
check_contains "T6b explains the missing service" "$WORK/t6b.log" "No Swarm service named 'opensinop'"
check_not_contains "T6b does not report success" "$WORK/t6b.log" 'Update complete'

echo "=== T7: daemon down -> clear error, nothing written ==="
D7="$(make_fresh_dir t7)"
( cd "$D7" && env PATH="$WORK/bin-daemon-down:$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/t7.docker.log" STUB_STATE="$WORK/state-t7" bash install.sh --yes ) > "$WORK/t7.log" 2>&1
RC7=$?
check "T7 exit 1" $([ "$RC7" -ne 0 ] && echo 0 || echo 1)
check_contains "T7 explains the daemon" "$WORK/t7.log" 'daemon is not reachable'
check "T7 no .env written" $([ ! -f "$D7/.env" ] && echo 0 || echo 1)

echo "=== T8: Docker missing -> installs via get.docker.com, then deploys ==="
if [ "$(uname)" = "Linux" ]; then
    D8="$(make_fresh_dir t8)"
    ( cd "$D8" && env PATH="$WORK/bin-autoinstall:$WORK/minimal-bin" DOCKER_STUB_LOG="$WORK/t8.docker.log" STUB_STATE="$WORK/state-t8" DOCKER_VERSION="28.5.0" bash install.sh --yes ) > "$WORK/t8.log" 2>&1
    check "T8 exit 0" $?
    L8="$WORK/t8.docker.log"
    check_contains "T8 downloads get.docker.com" "$L8" 'get\.docker\.com'
    check_contains "T8 runs the installer script" "$L8" '^(sudo )?sh -s -- --version 28\.5\.0$'
    check_contains "T8 holds packages at the pinned version" "$L8" '^(sudo )?apt-mark hold docker-ce docker-ce-cli docker-ce-rootless-extras$'
    check_contains "T8 initializes swarm after install" "$L8" '^docker swarm init'
    check_contains "T8 deploys the app" "$L8" '^docker service create --name opensinop '
    check_contains "T8 writes env" "$D8/.env" '^APP_NAME="OpenSinop"$'
    check_contains "T8 completes" "$WORK/t8.log" 'Installation complete'
else
    echo "  SKIP  T8 (Linux-only scenario)"
fi

echo "=== T9: --help ==="
( cd "$WORK" && bash "$REPO/install.sh" --help ) > "$WORK/t9.log" 2>&1
check "T9 exit 0" $?
check_contains "T9 shows usage" "$WORK/t9.log" 'Usage: install\.sh'
check_contains "T9 documents --update" "$WORK/t9.log" '--update'
check_contains "T9 documents the swarm stack" "$WORK/t9.log" 'Docker Swarm stack'

echo "=== T10: re-run removes and recreates services, keeps password and secret ==="
D10="$(make_fresh_dir t10)"
printf 'DB_PASSWORD="OriginalPass1234567890"\nAPP_URL="http://localhost:8000"\n' > "$D10/.env"
mkdir -p "$WORK/state-t10"
: > "$WORK/state-t10/net-opensinop-network"
: > "$WORK/state-t10/secret-opensinop_db_password"
: > "$WORK/state-t10/svc-opensinop"
: > "$WORK/state-t10/svc-opensinop-db"
: > "$WORK/state-t10/svc-opensinop-redis"
run_install "$D10" "$WORK/t10.log" "$WORK/state-t10" bash install.sh --yes
check "T10 exit 0" $?
L10="$WORK/t10.docker.log"
check_contains "T10 reuses the network" "$WORK/t10.log" 'already present'
check_contains "T10 keeps the existing secret" "$WORK/t10.log" 'keeping it'
check_contains "T10 backs up the old .env" "$WORK/t10.log" 'backed up'
check_contains "T10 removes the old app service" "$L10" '^docker service rm opensinop$'
check_contains "T10 keeps the password" "$D10/.env" '^DB_PASSWORD="OriginalPass1234567890"$'
check_not_contains "T10 does not recreate the secret" "$L10" '^docker secret create'
check_not_contains "T10 does not recreate the network" "$L10" '^docker network create'
awk '/^docker service rm opensinop$/{rm=NR} /^docker service create --name opensinop /{cr=NR} END{exit !(rm && cr && rm < cr)}' "$L10"
check "T10 removes before recreating" $?
check_contains "T10 completes" "$WORK/t10.log" 'Installation complete'

echo "=== T11: sqlite needs no database service or secret ==="
D11="$(make_fresh_dir t11)"
{ printf '\n\n\n3\n'; yes '' | head -25; } > "$WORK/t11.answers"
( cd "$D11" && env PATH="$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/t11.docker.log" STUB_STATE="$WORK/state-t11" bash install.sh < "$WORK/t11.answers" ) > "$WORK/t11.log" 2>&1
check "T11 exit 0" $?
check_contains "T11 writes sqlite driver" "$D11/.env" '^DB_CONNECTION="sqlite"$'
check_contains "T11 stores sqlite in the storage volume" "$D11/.env" '^DB_DATABASE="/app/storage/database.sqlite"$'
check_not_contains "T11 creates no db service" "$WORK/t11.docker.log" '^docker service create --name opensinop-db'
check_not_contains "T11 creates no secret" "$WORK/t11.docker.log" '^docker secret'
check_not_contains "T11 app mounts no secret" "$WORK/t11.docker.log" '^docker service create --name opensinop .*--secret'
check_contains "T11 still deploys the app" "$WORK/t11.docker.log" '^docker service create --name opensinop '
check_not_contains "T11 waits for no postgres" "$WORK/t11.docker.log" 'pg_isready'
check_contains "T11 completes" "$WORK/t11.log" 'Installation complete'

echo "=== T12: LXC host gets --endpoint-mode dnsrr ==="
D12="$(make_fresh_dir t12)"
( cd "$D12" && env container=lxc PATH="$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/t12.docker.log" STUB_STATE="$WORK/state-t12" bash install.sh --yes ) > "$WORK/t12.log" 2>&1
check "T12 exit 0" $?
check_contains "T12 explains the fallback" "$WORK/t12.log" 'endpoint-mode dnsrr'
check_contains "T12 db uses dnsrr" "$WORK/t12.docker.log" '^docker service create --name opensinop-db .*--endpoint-mode dnsrr'
check_contains "T12 redis uses dnsrr" "$WORK/t12.docker.log" '^docker service create --name opensinop-redis .*--endpoint-mode dnsrr'
check_contains "T12 app uses dnsrr" "$WORK/t12.docker.log" '^docker service create --name opensinop .*--endpoint-mode dnsrr'
check_contains "T12 completes" "$WORK/t12.log" 'Installation complete'

echo "=== T13: daemon needs sudo -> DOCKER_CMD uses sudo ==="
if [ "$(id -u)" != "0" ]; then
    D13="$(make_fresh_dir t13)"
    ( cd "$D13" && env PATH="$WORK/bin-sudo:$WORK/bin-ok:$PATH" DOCKER_STUB_LOG="$WORK/t13.docker.log" STUB_STATE="$WORK/state-t13" bash install.sh --yes ) > "$WORK/t13.log" 2>&1
    check "T13 exit 0" $?
    check_contains "T13 warns about sudo" "$WORK/t13.log" 'using sudo'
    check_contains "T13 initializes swarm with sudo" "$WORK/t13.docker.log" '^sudo docker swarm init'
    check_contains "T13 creates the app service with sudo" "$WORK/t13.docker.log" '^sudo docker service create --name opensinop '
    check_contains "T13 completes" "$WORK/t13.log" 'Installation complete'
else
    echo "  SKIP  T13 (running as root: sudo fallback not exercised)"
fi

echo "=== T14: container entrypoint reads DB_PASSWORD_FILE ==="
mkdir -p "$WORK/ent"
printf 's3cret-from-file' > "$WORK/ent/db-secret"
out="$(env ROOT="$WORK/ent" DB_PASSWORD_FILE="$WORK/ent/db-secret" APP_KEY="base64:dGVzdA==" sh "$REPO/docker/start-container" sh -c 'printf %s "$DB_PASSWORD"' 2>/dev/null)"
check "T14 reads the secret file" $([ "$out" = "s3cret-from-file" ] && echo 0 || echo 1)
out="$(env ROOT="$WORK/ent" DB_PASSWORD="explicit" DB_PASSWORD_FILE="$WORK/ent/db-secret" APP_KEY="base64:dGVzdA==" sh "$REPO/docker/start-container" sh -c 'printf %s "$DB_PASSWORD"' 2>/dev/null)"
check "T14 explicit DB_PASSWORD wins" $([ "$out" = "explicit" ] && echo 0 || echo 1)
out="$(env ROOT="$WORK/ent" APP_KEY="base64:dGVzdA==" sh "$REPO/docker/start-container" sh -c 'printf %s "${DB_PASSWORD:-unset}"' 2>/dev/null)"
check "T14 absent file leaves it unset" $([ "$out" = "unset" ] && echo 0 || echo 1)
printf 'no-newline-secret' > "$WORK/ent/db-secret2"
out="$(env ROOT="$WORK/ent" DB_PASSWORD_FILE="$WORK/ent/db-secret2" APP_KEY="base64:dGVzdA==" sh "$REPO/docker/start-container" sh -c 'printf %s "$DB_PASSWORD"' 2>/dev/null)"
check "T14 survives a file without trailing newline" $([ "$out" = "no-newline-secret" ] && echo 0 || echo 1)

echo
echo "RESULT: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
