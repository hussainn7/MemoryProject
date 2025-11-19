#!/bin/bash
# Script to restart the PHP development server with proper routing

cd "$(dirname "$0")"

# Kill any existing PHP server on port 8000
echo "Stopping existing server..."
pkill -f "php.*8000" 2>/dev/null
sleep 1

# Start the server with router script and custom php.ini
echo "Starting PHP server on port 8000 with increased upload limits..."
nohup php -c php-dev.ini -S 0.0.0.0:8000 -t public public/router.php > var/log/php-server.log 2>&1 &
echo $! > var/php-server.pid

sleep 2

# Check if server is running
if ps -p $(cat var/php-server.pid) > /dev/null 2>&1; then
    echo "✓ Server started successfully!"
    echo "✓ PID: $(cat var/php-server.pid)"
    echo "✓ Access at: http://localhost:8000"
    echo "✓ Or at: http://$(hostname -I | awk '{print $1}'):8000"
    echo ""
    echo "To stop: kill \$(cat var/php-server.pid)"
else
    echo "✗ Server failed to start. Check var/log/php-server.log"
    exit 1
fi

