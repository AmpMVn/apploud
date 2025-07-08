#!/bin/bash
# bin/test-cache.sh

echo "První spuštění:"
time bin/console gitlab:access-report "$1"
echo -e "\nDruhé spuštění:"
time bin/console gitlab:access-report "$1"
