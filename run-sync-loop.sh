#!/bin/bash
# Loop Event Sync chunks on ott-dev until done (or max iterations).
cd '/Users/rwaterbury/Developer/attendee-importer-for-eventkoi' || exit 1
LOG=qa-sync-run.log
: > "$LOG"
for i in $(seq 1 45); do
  LINE=$(python3 run-sync-chunk.py 2>&1)
  echo "chunk $i: $LINE" >> "$LOG"
  echo "chunk $i: $LINE"
  # Stop when the run reports done.
  echo "$LINE" | grep -q '"done": *true' && break
  # Stop on HTTP/transport failure (no JSON summary line).
  echo "$LINE" | grep -q '"processed"' || break
done
echo "LOOP FINISHED" >> "$LOG"
echo "LOOP FINISHED"
