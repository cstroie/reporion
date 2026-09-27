#!/usr/bin/env bash
# SPDX-License-Identifier: GPL-3.0-or-later
# Test-only: stops what tools/browser/start.sh started, and only the Chrome
# processes whose profile lives in <fixture dir> (other sessions may run theirs).
DIR="${1:?usage: stop.sh <fixture dir>}"
[ -f "$DIR/pids" ] && kill $(cat "$DIR/pids") 2>/dev/null
for pid in $(pgrep -f "user-data-dir=$DIR/"); do kill "$pid" 2>/dev/null; done
exit 0
