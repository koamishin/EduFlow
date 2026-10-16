#!/usr/bin/env bash
# install.sh — interactive TUI installer for OpenSinop (macOS/Linux)
# Usage:
#   bash <(curl -fsSL https://raw.githubusercontent.com/koamishin/OpenSinop/main/install.sh)   # bash / zsh
#   curl -fsSL https://raw.githubusercontent.com/koamishin/OpenSinop/main/install.sh | bash    # any shell, incl. fish
#   bash install.sh --update   # update an existing installation
#   bash install.sh --yes      # non-interactive, accept every default

if [ -z "${BASH_VERSION:-}" ]; then
    printf 'install.sh requires bash. Run it with: bash install.sh\n' >&2
    exit 1
fi

set -Eeuo pipefail

# True when the script itself is piped to bash (`curl … | bash`); stdin then
# carries script text and must never be read for answers.
SCRIPT_FROM_STDIN=0
[ -n "${BASH_SOURCE[0]:-}" ] || SCRIPT_FROM_STDIN=1

ROOT="$(pwd)"
ENV_FILE="$ROOT/.env"
COMPOSE_FILE="$ROOT/docker-compose.yml"
COMPOSE_URL="https://raw.githubusercontent.com/koamishin/OpenSinop/main/docker-compose.yml"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

if [ -t 1 ]; then COLOR=1; else COLOR=0; fi
c() { [ "$COLOR" = 1 ] && printf '%b' "$1$2$RESET" || printf '%s' "$2"; }

title() {
    echo; printf '%s\n' "$(c "$CYAN" "==============================================================================")"
    printf '%s\n' "$(c "$CYAN$BOLD" "$1")" | sed 's/^/  /'
    printf '%s\n' "$(c "$CYAN" "==============================================================================")"
}
info() { printf '  %s\n' "$1"; }
warn() { printf '  %s\n' "$(c "$YELLOW" "[warn] $1")"; }
ok() { printf '  %s\n' "$(c "$GREEN" "[ok]   $1")"; }
err() { printf '  %s\n' "$(c "$RED" "[error] $1")"; }

# Where interactive answers are read from. Empty means stdin. Callers never
# see script text as an answer, and stderr is never redirected.
INPUT_SOURCE=""

read_line() {  # read one line from the input source into the variable named in $1
    local __var="$1" __secret="${2:-}"
    if [ "$__secret" = "secret" ]; then
        if [ -n "$INPUT_SOURCE" ]; then
            IFS= read -rs "$__var" < "$INPUT_SOURCE" || true
        else
            IFS= read -rs "$__var" || true
        fi
        printf '\n' >&2
    elif [ -n "$INPUT_SOURCE" ]; then
        IFS= read -r "$__var" < "$INPUT_SOURCE" || true
    else
        # Plain fd 0: reopening /dev/stdin would reseek a redirected file on Linux.
        IFS= read -r "$__var" || true
    fi
}

ask() {
    local prompt="$1" default="${2:-}" value
    if [ "${NON_INTERACTIVE:-0}" = "1" ]; then
        printf '%s' "$default"
        return
    fi
    if [ -n "$default" ]; then
        printf '  %s [%s]: ' "$prompt" "$default" >&2
    else
        printf '  %s: ' "$prompt" >&2
    fi
    read_line value
    value="${value:-$default}"
    printf '%s' "$value"
}

ask_secret() {
    local prompt="$1" default="${2:-}" value
    if [ "${NON_INTERACTIVE:-0}" = "1" ]; then
        printf '%s' "$default"
        return
    fi
    if [ -n "$default" ]; then
        printf '  %s [hidden]: ' "$prompt" >&2
    else
        printf '  %s: ' "$prompt" >&2
    fi
    read_line value secret
    value="${value:-$default}"
    printf '%s' "$value"
}

ask_choice() {
    # prompt default choice1 choice2 ...
    local prompt="$1" default="$2"; shift 2
    local choices=("$@")
    if [ "${NON_INTERACTIVE:-0}" = "1" ]; then
        printf '%s' "$default"
        return
    fi
    printf '  %s\n' "$(c "$BOLD" "$prompt")" >&2
    local i=1
    for ch in "${choices[@]}"; do
        if [ "$ch" = "$default" ]; then
            printf '    %d. %s (default)\n' "$i" "$ch" >&2
        else
            printf '    %d. %s\n' "$i" "$ch" >&2
        fi
        i=$((i+1))
    done
    printf '  Select: ' >&2
    local value idx
    read_line value
    value="${value:-$default}"
    if [[ "$value" =~ ^[0-9]+$ ]] && [ "$value" -ge 1 ] 2>/dev/null && [ "$value" -le "${#choices[@]}" ]; then
        printf '%s' "${choices[$((value-1))]}"
        return
    fi
    for ch in "${choices[@]}"; do
        [ "$ch" = "$value" ] && { printf '%s' "$value"; return; }
    done
    printf '%s' "$default"
}

yes_no() {
    local prompt="$1" default="${2:-yes}" ans
    if [ "${NON_INTERACTIVE:-0}" = "1" ]; then
        printf '%s' "$default"
        return
    fi
    printf '  %s [%s]: ' "$prompt" "$default" >&2
    read_line ans
    ans="${ans:-$default}"
    case "$(printf '%s' "$ans" | tr '[:upper:]' '[:lower:]')" in
        y|yes|true|1) printf 'yes' ;;
        *) printf 'no' ;;
    esac
}

cmd_exists() { command -v "$1" >/dev/null 2>&1; }

port_in_use() {
    local p="$1"
    if cmd_exists ss; then
        ss -tln 2>/dev/null | grep -E -q "[:.]${p}[[:space:]]" && return 0
    elif cmd_exists netstat; then
        netstat -an 2>/dev/null | grep -E 'LISTEN(ING)?' | grep -E -q "[:.]${p}[[:space:]]" && return 0
    elif cmd_exists lsof; then
        lsof -iTCP:"$p" -sTCP:LISTEN >/dev/null 2>&1 && return 0
    fi
    if { exec 3<>"/dev/tcp/127.0.0.1/$p"; } 2>/dev/null; then
        exec 3<&- || true
        return 0
    fi
    return 1
}

redis_ping() {
    local host="$1" port="$2" pass="$3"
    if [[ -z "$pass" || "$pass" == "null" ]]; then pass=""; fi
    if cmd_exists nc; then
        local out
        if [ -n "$pass" ]; then
            out=$(printf 'AUTH %s\r\nPING\r\n' "$pass" | nc -w 3 "$host" "$port" 2>/dev/null || true)
        else
            out=$(printf 'PING\r\n' | nc -w 3 "$host" "$port" 2>/dev/null || true)
        fi
        printf '%s' "$out" | grep -qi pong && return 0 || return 1
    fi
    if ! { exec 3<>"/dev/tcp/$host/$port"; } 2>/dev/null; then
        return 2
    fi
    if [ -n "$pass" ]; then printf 'AUTH %s\r\n' "$pass" >&3; fi
    printf 'PING\r\n' >&3
    local line
    IFS= read -t 3 -r line <&3 || true
    exec 3<&- || true
    printf '%s' "$line" | grep -qi pong
}

rpc_test() {
    local url="$1"
    [ -n "$url" ] || { printf 'skipped'; return; }
    if ! cmd_exists curl; then printf 'curl not available'; return; fi
    local resp
    resp=$(curl -fsS -m 8 -H 'Content-Type: application/json' \
        -d '{"jsonrpc":"2.0","method":"eth_chainId","params":[],"id":1}' "$url" 2>/dev/null || true)
    if printf '%s' "$resp" | grep -q '"result"'; then
        printf '%s' "$resp" | sed -n 's/.*"result":"*\([^"]*\)".*/\1/p' | head -1
        return 0
    fi
    printf 'FAIL'
}

gen_key() {
    if cmd_exists openssl; then
        printf 'base64:%s' "$(openssl rand -base64 32 2>/dev/null | tr -d '\n')"
    else
        local raw
        raw=$(head -c 32 /dev/urandom | base64 | tr -d '\n')
        printf 'base64:%s' "$raw"
    fi
}


# ---- Argument parsing ----
UPDATE=0
NON_INTERACTIVE=0
for arg in "$@"; do
    case "$arg" in
        --update|-u) UPDATE=1 ;;
        --yes|-y|--defaults|-d|--non-interactive) NON_INTERACTIVE=1 ;;
        --help|-h)
            cat <<'EOF'
Usage: install.sh [--update] [--yes|-y]

  --update, -u   Update an existing installation (pull images and restart).
  --yes, -y      Non-interactive: accept every default. Also NON_INTERACTIVE=1.
EOF
            exit 0
            ;;
    esac
done

# Choose where answers come from. When the script is piped into bash, stdin
# holds script text, so answers must come from the terminal instead.
if [ "$NON_INTERACTIVE" = "0" ] && [ ! -t 0 ] && [ "$SCRIPT_FROM_STDIN" = "1" ]; then
    if { true < /dev/tty; } 2>/dev/null; then
        INPUT_SOURCE="/dev/tty"
    else
        NON_INTERACTIVE=1
        warn "No terminal available to read answers; using defaults (like --yes)."
    fi
fi

env_value() {
    local key="$1" value=""
    if [ -f "$ENV_FILE" ]; then
        value="$(grep -E "^${key}=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true)"
    fi
    printf '%s' "$value"
}

require_docker() {
    if ! cmd_exists docker; then
        err "Docker is required but was not found."
        info "Install Docker Engine: https://docs.docker.com/engine/install/"
        return 1
    fi
    if ! docker info >/dev/null 2>&1; then
        err "Docker is installed, but the daemon is not reachable."
        info "Start Docker and make sure your user can access it (add yourself to the 'docker' group or use sudo)."
        return 1
    fi
    if ! docker compose version >/dev/null 2>&1; then
        err "Docker Compose v2 is required, but 'docker compose' is not available."
        info "Install the Compose plugin: https://docs.docker.com/compose/install/linux/"
        return 1
    fi
    return 0
}

ensure_compose_file() {
    if [ -f "$COMPOSE_FILE" ]; then
        return 0
    fi
    if ! cmd_exists curl; then
        err "docker-compose.yml is missing and curl is not installed."
        info "Install curl, or download $COMPOSE_URL into $ROOT manually."
        return 1
    fi
    info "Downloading docker-compose.yml ..."
    if ! curl -fsSL --retry 3 --retry-delay 2 "$COMPOSE_URL" -o "$COMPOSE_FILE"; then
        err "Could not download docker-compose.yml from $COMPOSE_URL"
        info "Check network access (proxy, firewall), or download the file into $ROOT manually."
        return 1
    fi
    ok "Downloaded docker-compose.yml"
}

validate_compose_file() {
    if ! docker compose config -q; then
        err "docker-compose.yml failed to validate with this Docker Compose version."
        info "Docker Compose v2.24+ is recommended; update Docker/Compose and try again."
        return 1
    fi
    return 0
}

if [ "$UPDATE" = "1" ]; then
    title "OpenSinop Update"
    if ! require_docker; then
        exit 1
    fi
    if [ ! -f "$ENV_FILE" ]; then
        err "No .env found in $ROOT. Run the installer first."
        exit 1
    fi
    if ! ensure_compose_file; then
        exit 1
    fi
    if ! validate_compose_file; then
        exit 1
    fi
    DB_CONN="$(env_value DB_CONNECTION)"; DB_CONN="${DB_CONN:-pgsql}"
    if [ "$DB_CONN" = "pgsql" ]; then
        DB_USER="$(env_value DB_USERNAME)"; DB_USER="${DB_USER:-opensinop}"
        DB_NAME="$(env_value DB_DATABASE)"; DB_NAME="${DB_NAME:-opensinop}"
        DB_CID="$(docker compose ps -q db 2>/dev/null || true)"
        if [ -n "$DB_CID" ]; then
            backup_dir="$ROOT/backups"
            mkdir -p "$backup_dir"
            backup_file="$backup_dir/opensinop-$(date +%Y%m%d-%H%M%S).sql"
            if docker compose exec -T db sh -c "pg_dump -U '$DB_USER' '$DB_NAME'" > "$backup_file" 2>/dev/null; then
                ok "Database backup written to $backup_file"
            else
                warn "Database backup failed; continuing update."
                rm -f "$backup_file"
            fi
        fi
    elif [ "$DB_CONN" = "sqlite" ]; then
        APP_CID="$(docker compose ps -q app 2>/dev/null || true)"
        if [ -n "$APP_CID" ]; then
            backup_dir="$ROOT/backups"
            mkdir -p "$backup_dir"
            backup_file="$backup_dir/opensinop-$(date +%Y%m%d-%H%M%S).sqlite"
            DB_FILE="$(env_value DB_DATABASE)"; DB_FILE="${DB_FILE:-/app/database/database.sqlite}"
            if docker compose exec -T app sh -c "sqlite3 '$DB_FILE' '.backup /tmp/backup.sqlite' && cat /tmp/backup.sqlite" > "$backup_file" 2>/dev/null; then
                ok "Database backup written to $backup_file"
            fi
        fi
    fi
    title "Pulling latest images"
    if ! docker compose pull; then
        err "docker compose pull failed."
        info "If the application image is private, authenticate first: docker login ghcr.io (token with read:packages)."
        exit 1
    fi
    title "Starting updated containers"
    if ! docker compose up -d; then
        err "docker compose up failed."
        exit 1
    fi
    healthy=0
    for _ in $(seq 1 24); do
        status=$(docker inspect --format '{{.State.Health.Status}}' opensinop 2>/dev/null || true)
        if [ "$status" = "healthy" ]; then healthy=1; break; fi
        if [ "$status" = "unhealthy" ]; then break; fi
        sleep 5
    done
    if [ "$healthy" = "1" ]; then
        ok "Application is healthy."
    else
        warn "Application did not report healthy yet; see docker logs opensinop."
    fi
    APP_URL="$(env_value APP_URL)"; APP_URL="${APP_URL:-http://localhost:8000}"
    info "App URL: $APP_URL"
    info "Note: database migrations run automatically on container start."
    ok "Update complete."
    exit 0
fi

title "OpenSinop TUI Installer (bash)"
info "Interactive setup for Docker-based self-hosting."
info "Press Enter to accept a suggested default."
echo

if ! require_docker; then
    exit 1
fi
ok "Docker found."

if ! ensure_compose_file; then
    exit 1
fi
if ! validate_compose_file; then
    exit 1
fi

# Existing .env backup
if [ -f "$ENV_FILE" ]; then
    cp "$ENV_FILE" "$ROOT/.env.previous" 2>/dev/null || true
    warn "Existing .env backed up to .env.previous"
fi

declare -a FIELDS=()
add() { FIELDS+=("$1=$2"); }

title "Application"
APP_NAME=$(ask "Application name" "OpenSinop")
APP_ENV="production"
APP_DEBUG="false"
DEFAULT_PORT="8000"
if port_in_use "$DEFAULT_PORT"; then
    for candidate in 8080 8088 8888 8008; do
        if ! port_in_use "$candidate"; then
            DEFAULT_PORT="$candidate"
            break
        fi
    done
    if [ "$DEFAULT_PORT" = "8000" ]; then
        warn "Ports 8000, 8080, 8088, 8888 and 8008 all appear to be in use."
    else
        info "Port 8000 is in use; suggesting $DEFAULT_PORT instead."
    fi
fi
APP_PORT=$(ask "Published HTTP port" "$DEFAULT_PORT")
APP_URL=$(ask "Public URL" "http://localhost:$APP_PORT")
APP_KEY="$(gen_key)"
ok "Generated APP_KEY"

title "Database"
DB_CONNECTION=$(ask_choice "Database driver" "pgsql" pgsql mysql sqlite)
if [ "$DB_CONNECTION" = "sqlite" ]; then
    # Keep SQLite inside the persisted storage volume so it survives container recreation.
    DB_HOST=""; DB_PORT=""; DB_DATABASE="/app/storage/database.sqlite"; DB_USERNAME=""; DB_PASSWORD=""
else
    DB_HOST="db"
    DB_PORT="$([ "$DB_CONNECTION" = pgsql ] && echo 5432 || echo 3306)"
    DB_DATABASE="opensinop"
    DB_USERNAME="opensinop"
    DB_PASSWORD="opensinop"
fi

# Common environment defaults (production)
CACHE_STORE="database"
SESSION_DRIVER="database"
QUEUE_CONNECTION="database"
REDIS_HOST="redis"
REDIS_PORT="6379"
REDIS_PASSWORD="null"

# Mail defaults
MAIL_MAILER="log"
MAIL_HOST="127.0.0.1"
MAIL_PORT="2525"
MAIL_USERNAME=""
MAIL_PASSWORD=""
MAIL_SCHEME="null"
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="$APP_NAME"

title "School identity"
EDUFLOW_INSTITUTION=$(ask "Institution name" "My School")
EDUFLOW_COUNTRY=$(ask "Country (2-letter code)" "US" | tr '[:lower:]' '[:upper:]')
EDUFLOW_TIMEZONE=$(ask "Timezone" "UTC")
EDUFLOW_CURRENCY=$(ask "Accounting currency" "USD" | tr '[:lower:]' '[:upper:]')

title "Lepton agent (Circle + Arc)"
info "Driver selection:"
info "  • fake      - In-memory simulated ledger (no credentials needed; safe for testing/demos)"
info "  • circle    - Circle CLI agent stack (email OTP login via Circle CLI binary)"
info "  • developer - Pure HTTP REST API using Circle Developer-Controlled Wallets (no CLI needed)"
LEPTON_DRIVER=$(ask_choice "Lepton driver" "fake" fake circle developer)
LEPTON_CHAIN=$(ask_choice "Chain" "ARC-TESTNET" ARC-TESTNET ARC-MAINNET)
LEPTON_DRY_RUN=$(ask_choice "Dry run (no real transfers)" "true" true false)
LEPTON_TREASURY_ADDRESS=$(ask "Treasury wallet address (empty for fake)" "")
LEPTON_RPC_URL=$(ask "Arc RPC URL (empty for default)" "")

LEPTON_CIRCLE_API_KEY=""
LEPTON_ENTITY_SECRET=""
LEPTON_WALLET_SET_ID=""
if [ "$LEPTON_DRIVER" = "developer" ]; then
    title "Circle Developer REST API"
    info "Configure Developer-Controlled Wallets credentials (api.circle.com/v1/w3s):"
    LEPTON_CIRCLE_API_KEY=$(ask_secret "Circle API Key")
    LEPTON_ENTITY_SECRET=$(ask_secret "Entity Secret (32-byte / 64 hex characters)")
    LEPTON_WALLET_SET_ID=$(ask "Wallet Set ID (optional)" "")
fi

title "Optional AI advisory"
AI_PROVIDER=$(ask_choice "AI provider" "none" none local openai anthropic)
AI_LOCAL_URL=""; AI_LOCAL_MODEL=""; AI_LOCAL_API_KEY=""; OPENAI_API_KEY=""; ANTHROPIC_API_KEY=""
if [ "$AI_PROVIDER" = "local" ]; then
    AI_PROVIDER="local"
    AI_LOCAL_URL=$(ask "Local OpenAI-compatible URL" "http://127.0.0.1:11434/v1")
    AI_LOCAL_MODEL=$(ask "Local model" "llama3.1")
    AI_LOCAL_API_KEY=$(ask_secret "Local API key (optional)" "")
elif [ "$AI_PROVIDER" = "openai" ]; then AI_PROVIDER="openai"
    OPENAI_API_KEY=$(ask_secret "OpenAI API key" "")
elif [ "$AI_PROVIDER" = "anthropic" ]; then AI_PROVIDER="anthropic"
    ANTHROPIC_API_KEY=$(ask_secret "Anthropic API key" "")
else
    AI_PROVIDER="local"
fi

title "Live network checks"
rpc_target="${LEPTON_RPC_URL:-https://rpc.testnet.arc.io}"
if [ "$LEPTON_CHAIN" = "ARC-MAINNET" ] && [ -z "$LEPTON_RPC_URL" ]; then
    rpc_target="https://rpc.mainnet.arc.io"
fi
res=$(rpc_test "$rpc_target")
if [ "$res" = "FAIL" ]; then
    warn "Arc RPC check failed for $rpc_target."
elif [ -n "$res" ]; then
    ok "Arc RPC reachable ($rpc_target): chainId=$res"
fi

if cmd_exists circle; then ok "Circle CLI found on PATH."; else warn "Circle CLI not found on PATH (needed only for circle driver)."; fi

title "Review"
printf '  APP_NAME=%s\n' "$APP_NAME"
printf '  APP_ENV=%s APP_DEBUG=%s\n' "$APP_ENV" "$APP_DEBUG"
printf '  DB_CONNECTION=%s\n' "$DB_CONNECTION"
printf '  CACHE_STORE=%s SESSION_DRIVER=%s QUEUE_CONNECTION=%s\n' "$CACHE_STORE" "$SESSION_DRIVER" "$QUEUE_CONNECTION"
printf '  LEPTON_DRIVER=%s CHAIN=%s\n' "$LEPTON_DRIVER" "$LEPTON_CHAIN"
echo
if [ "$(yes_no "Write this configuration and start Docker?" "yes")" != "yes" ]; then
    warn "Aborted. No changes were made."
    exit 0
fi

# Write .env
{
    cat <<EOF
APP_NAME="$APP_NAME"
APP_ENV="$APP_ENV"
APP_KEY="$APP_KEY"
APP_DEBUG="$APP_DEBUG"
APP_URL="$APP_URL"
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
BCRYPT_ROUNDS=12
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning
DB_CONNECTION="$DB_CONNECTION"
DB_HOST="$DB_HOST"
DB_PORT="$DB_PORT"
DB_DATABASE="$DB_DATABASE"
DB_USERNAME="$DB_USERNAME"
DB_PASSWORD="$DB_PASSWORD"
CACHE_STORE="$CACHE_STORE"
SESSION_DRIVER="$SESSION_DRIVER"
QUEUE_CONNECTION="$QUEUE_CONNECTION"
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
MAIL_MAILER="$MAIL_MAILER"
MAIL_HOST="$MAIL_HOST"
MAIL_PORT="$MAIL_PORT"
MAIL_USERNAME="$MAIL_USERNAME"
MAIL_PASSWORD="$MAIL_PASSWORD"
MAIL_SCHEME="$MAIL_SCHEME"
MAIL_FROM_ADDRESS="$MAIL_FROM_ADDRESS"
MAIL_FROM_NAME="$MAIL_FROM_NAME"
EDUFLOW_INSTITUTION="$EDUFLOW_INSTITUTION"
EDUFLOW_COUNTRY="$EDUFLOW_COUNTRY"
EDUFLOW_TIMEZONE="$EDUFLOW_TIMEZONE"
EDUFLOW_CURRENCY="$EDUFLOW_CURRENCY"
LEPTON_DRIVER="$LEPTON_DRIVER"
LEPTON_CHAIN="$LEPTON_CHAIN"
LEPTON_DRY_RUN="$LEPTON_DRY_RUN"
LEPTON_TREASURY_ADDRESS="$LEPTON_TREASURY_ADDRESS"
LEPTON_RPC_URL="$LEPTON_RPC_URL"
LEPTON_CIRCLE_API_KEY="$LEPTON_CIRCLE_API_KEY"
LEPTON_ENTITY_SECRET="$LEPTON_ENTITY_SECRET"
LEPTON_WALLET_SET_ID="$LEPTON_WALLET_SET_ID"
LEPTON_CIRCLE_API_URL="https://api.circle.com/v1/w3s"
AI_PROVIDER="$AI_PROVIDER"
AI_LOCAL_URL="$AI_LOCAL_URL"
AI_LOCAL_MODEL="$AI_LOCAL_MODEL"
AI_LOCAL_API_KEY="$AI_LOCAL_API_KEY"
OPENAI_API_KEY="$OPENAI_API_KEY"
ANTHROPIC_API_KEY="$ANTHROPIC_API_KEY"
REDIS_HOST="$REDIS_HOST"
REDIS_PORT="$REDIS_PORT"
REDIS_PASSWORD="$REDIS_PASSWORD"
EOF
} > "$ENV_FILE"
chmod 600 "$ENV_FILE" 2>/dev/null || true
# also persist port in .env for reference
printf 'APP_PORT="%s"\n' "$APP_PORT" >> "$ENV_FILE"
ok "Wrote $ENV_FILE"

if [ ! -f "$ROOT/Dockerfile" ]; then
    title "Pulling application image"
    info "No local build context found; fetching the published image."
    if ! docker compose pull app; then
        err "Could not pull the application image."
        info "If the ghcr.io image is private, authenticate first: docker login ghcr.io (token with read:packages)."
        info "Or clone the repository and run install.sh from it so Compose builds the image locally."
        exit 1
    fi
    ok "Application image is ready."
fi

title "Starting containers"
info "Running: docker compose up -d"
if ! docker compose up -d; then
    err "docker compose up failed."
    exit 1
fi
ok "Containers started."

title "Health check"
healthy=0
for _ in $(seq 1 24); do
    status=$(docker inspect --format '{{.State.Health.Status}}' opensinop 2>/dev/null || true)
    if [ "$status" = "healthy" ]; then healthy=1; break; fi
    if [ "$status" = "unhealthy" ]; then break; fi
    sleep 5
done
if [ "$healthy" = 1 ]; then ok "Application is healthy."; else warn "Application did not report healthy yet; see docker logs opensinop."; fi

title "Next steps"
info "Open the app at $APP_URL"
info "Create the first administrator:"
printf '  %s\n' "$(c "$BOLD" "docker exec -it opensinop php artisan app:bootstrap-admin --name=\"School Operator\" --email=\"operator@example.org\"")"
info "Circle/Arc features require the Circle CLI and arc-canteen on your workflow host."
ok "Installation complete."
