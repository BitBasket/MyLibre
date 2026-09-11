#!/bin/bash

# Function to check if a port is free
is_port_free() {
    local port=$1
    if ! lsof -Pi :$port -sTCP:LISTEN -t >/dev/null 2>&1; then
        return 0  # Port is free
    else
        return 1  # Port is in use
    fi
}

# Find first available port starting from 8000
PORT=8000
while ! is_port_free $PORT; do
    echo "Port $PORT is in use, trying next port..."
    PORT=$((PORT + 1))
    
    # Safety check to avoid infinite loop
    if [ $PORT -gt 9000 ]; then
        echo "Error: No free ports found between 8000-9000"
        exit 1
    fi
done

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

# Run nginx container
echo "Starting nginx on port $PORT..."
DOCKER_ARGS=(
    --name "$CONTAINER_NAME"
    -p "127.0.0.1:$PORT:80"
    -v "$PUBLIC_DIR:/usr/share/nginx/html:ro"
)
if [ -f "$NGINX_CONF" ]; then
    DOCKER_ARGS+=(-v "$NGINX_CONF:/etc/nginx/conf.d/default.conf:ro")
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
