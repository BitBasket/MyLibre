#!/bin/bash

# Function to check if a port is free.
# Returns 0 if the port is available, 1 if something is already listening on it.
is_port_free() {
    local port=$1

    # Preferred: ss — reports any socket LISTENing on the port
    if command -v ss >/dev/null 2>&1; then
        if ss -ltn "sport = :$port" 2>/dev/null | grep -q LISTEN; then
            return 1  # Port is in use
        fi
    fi

    # lsof as a second opinion (it can be blind inside containers, so never trust
    # a negative from it alone)
    if command -v lsof >/dev/null 2>&1; then
        if lsof -Pi :"$port" -sTCP:LISTEN -t >/dev/null 2>&1; then
            return 1  # Port is in use
        fi
    fi

    # Authoritative check: try to connect. A refused connection means nothing is
    # listening, so the port can be bound. timeout guards against a listener with a
    # full backlog hanging the script on bash's untimed /dev/tcp connect.
    # (the fd lives in the subshell, so it is closed as soon as the test finishes)
    local rc=0
    if command -v timeout >/dev/null 2>&1; then
        timeout 1 bash -c 'exec 3<>"/dev/tcp/127.0.0.1/$1"' _ "$port" 2>/dev/null || rc=$?
    else
        (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null || rc=$?
    fi
    if [ "$rc" -eq 0 ] || [ "$rc" -eq 124 ]; then
        return 1  # Connected, or the connect hung — either way, don't gamble on it
    fi
    return 0  # Port is free
}

# Preferred port, and the last port we are willing to try
BASE_PORT=8000
MAX_PORT=9000

# Find the first available port starting from BASE_PORT
PORT=$BASE_PORT
while ! is_port_free "$PORT"; do
    echo "Port $PORT is in use, trying next port..."
    PORT=$((PORT + 1))

    # Safety check to avoid infinite loop
    if [ "$PORT" -gt "$MAX_PORT" ]; then
        echo "Error: No free ports found between $BASE_PORT-$MAX_PORT"
        exit 1
    fi
done

if [ "$PORT" -ne "$BASE_PORT" ]; then
    echo "Using free port $PORT instead of $BASE_PORT."
fi

# Get absolute path to public directory and nginx config
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PUBLIC_DIR="$SCRIPT_DIR/public"
NGINX_CONF="$SCRIPT_DIR/nginx/default.conf"

# Check if public directory exists
if [ ! -d "$PUBLIC_DIR" ]; then
    echo "Error: ./public directory does not exist"
    echo "Creating ./public directory..."
    mkdir -p "$PUBLIC_DIR"
    echo "<h1>Welcome to nginx!</h1>" > "$PUBLIC_DIR/index.html"
fi

# Container name
CONTAINER_NAME="nginx-local-${PORT}"

# Stop and remove any existing container with the same name
if docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "Stopping existing container..."
    docker stop "$CONTAINER_NAME" >/dev/null 2>&1
    docker rm "$CONTAINER_NAME" >/dev/null 2>&1
fi

# Run nginx container.
# Host networking is required so /api/librelink/ can reach the poller's
# loopback AUTH_LISTEN (127.0.0.1:8766). -p is ignored with --network host,
# so the listen port is rewritten to $PORT.
echo "Starting nginx on port $PORT..."
DOCKER_ARGS=(
    --name "$CONTAINER_NAME"
    --network host
    -v "$PUBLIC_DIR:/usr/share/nginx/html:ro"
)
if [ -f "$NGINX_CONF" ]; then
    RUNTIME_CONF="${TMPDIR:-/tmp}/mylibre-nginx-${PORT}.conf"
    sed "s/listen 80;/listen 127.0.0.1:${PORT};/" "$NGINX_CONF" > "$RUNTIME_CONF"
    DOCKER_ARGS+=(-v "$RUNTIME_CONF:/etc/nginx/conf.d/default.conf:ro")
fi
docker run -d "${DOCKER_ARGS[@]}" nginx:latest

# Check if container started successfully
if [ $? -eq 0 ]; then
    echo ""
    echo "✅ nginx is running!"
    echo "🌐 URL: http://localhost:$PORT/"
    echo ""
    echo "📁 Serving files from: $PUBLIC_DIR"
    echo "🛑 To stop: docker stop $CONTAINER_NAME"
else
    echo "❌ Failed to start nginx container"
    exit 1
fi
