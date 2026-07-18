#!/usr/bin/env bash
# TEFAS file uploader via wp proxy
# Usage: ./tefas-upload.sh <local_file> [remote_filename]
# Example: ./tefas-upload.sh /tmp/tefas-refactor/agent.php agent.php

set -euo pipefail

LOCAL_FILE="${1:?Usage: $0 <local_file> [remote_filename]}"
REMOTE_FILENAME="${2:-$(basename "$LOCAL_FILE")}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WP="$SCRIPT_DIR/wp"
REMOTE_BASE="/home/cylcoinc/kirbas.com/tefas"
REMOTE_PATH="$REMOTE_BASE/$REMOTE_FILENAME"

if [[ ! -f "$LOCAL_FILE" ]]; then
    echo "ERROR: Local file not found: $LOCAL_FILE"
    exit 1
fi

FILE_SIZE=$(wc -c < "$LOCAL_FILE")
echo "Uploading: $LOCAL_FILE ($FILE_SIZE bytes)"
echo "Target:    $REMOTE_PATH"

# Create backup of existing file
echo "Creating backup..."
"$WP" php "\$f='$REMOTE_PATH'; if(file_exists(\$f)){copy(\$f,\$f.'.bak-'.date('Ymd-His')); echo 'backup ok';}else{echo 'no existing file';}" 2>/dev/null || true

# Upload via base64
echo "Uploading content..."
B64=$(cat "$LOCAL_FILE" | base64 -w0)
"$WP" php "\$c=base64_decode('$B64'); file_put_contents('$REMOTE_PATH',\$c); echo 'OK: '.strlen(\$c).' bytes written to $REMOTE_FILENAME';"

# Verify
echo "Verifying..."
REMOTE_SIZE=$("$WP" php "echo filesize('$REMOTE_PATH');" 2>/dev/null || echo "0")
echo "Remote file size: $REMOTE_SIZE bytes"

if [[ "$REMOTE_SIZE" == "$FILE_SIZE" ]]; then
    echo "SUCCESS: File uploaded and verified"
else
    echo "WARNING: Size mismatch (local=$FILE_SIZE, remote=$REMOTE_SIZE)"
fi
