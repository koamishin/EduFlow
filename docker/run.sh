#!/bin/bash
set -e

# One-line Docker deployment script for OpenSinop Production
# Usage: ./docker/run.sh

echo "🚀 Deploying OpenSinop Production with Docker..."

# Check if .env file exists in the current directory and read variables
ENV_OPTS=()
if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    . ./.env
    set +a
    ENV_OPTS=(--env-file .env)
else
    echo "ℹ️  No .env file found; using default container environment."
fi

# Build the Docker image first
echo "📦 Building optimized Docker image..."
docker build \
    --build-arg APP_NAME="${APP_NAME:-OpenSinop}" \
    --build-arg APP_ENV="${APP_ENV:-production}" \
    --build-arg APP_DEBUG="${APP_DEBUG:-false}" \
    --build-arg APP_URL="${APP_URL:-http://localhost:8000}" \
    -t koamishin/opensinop:latest \
    -t ghcr.io/koamishin/opensinop:latest \
    -f docker/Dockerfile .

# Ask to publish
if [[ "$*" == *"--publish"* ]]; then
    PUBLISH="y"
else
    if [ -t 0 ]; then
        read -p "Do you want to publish the image to Docker Hub? (y/n) " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            PUBLISH="y"
        fi
    else
        PUBLISH="n"
    fi
fi

if [ "$PUBLISH" = "y" ]; then
    echo "📦 Publishing image to Docker Hub..."
    docker push koamishin/opensinop:latest
fi

# Remove existing container if present
if docker ps -a --format '{{.Names}}' | grep -q "^opensinop-production$"; then
    echo "Stopping and removing existing opensinop-production container..."
    docker rm -f opensinop-production >/dev/null 2>&1 || true
fi

# Detect free port if 8000 is occupied and APP_PORT not explicitly set
PORT="${APP_PORT:-8000}"
if [ -z "$APP_PORT" ]; then
    if command -v ss >/dev/null 2>&1 && ss -tlpn 2>/dev/null | grep -E -q "[:.]${PORT}[[:space:]]"; then
        for candidate in 8080 8088 8888 8008; do
            if ! ss -tlpn 2>/dev/null | grep -E -q "[:.]${candidate}[[:space:]]"; then
                PORT="$candidate"
                break
            fi
        done
    fi
fi

# Run the Docker container
echo "🚀 Starting Docker container on port ${PORT}..."
docker run -d \
    --name opensinop-production \
    --restart unless-stopped \
    "${ENV_OPTS[@]}" \
    -p "${PORT}:8000" \
    -v opensinop-storage:/app/storage \
    -v opensinop-logs:/app/storage/logs \
    --health-cmd "healthcheck || exit 1" \
    --health-interval 30s \
    --health-timeout 10s \
    --health-retries 3 \
    --health-start-period 60s \
    koamishin/opensinop:latest

echo "✅ OpenSinop deployed successfully!"
echo "🌐 Application is available at: http://localhost:${PORT}"
echo "📊 Horizon dashboard: http://localhost:${PORT}/horizon"
echo "💓 Pulse dashboard: http://localhost:${PORT}/pulse"
echo ""
echo "To view logs: docker logs -f opensinop-production"
echo "To stop: docker stop opensinop-production"
echo "To restart: docker restart opensinop-production"
