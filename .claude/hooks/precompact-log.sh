#!/bin/sh
# PreCompact hook (project audit Fix Kit, 2026-08-13, Tier 2). No project
# convention yet exists for what a PreCompact hook should actually do —
# this is deliberately a minimal, safe placeholder: log that a compaction
# happened (when, and whether it was manual or auto-triggered) rather than
# silently losing that signal, without attempting anything more elaborate
# than logging until there's a real need driving the design (e.g.
# snapshotting open TODOs, nudging toward session-wrapup, or warning when
# auto-compaction fires mid-task). A future version could act on
# .tool_input.custom_instructions the same way this reads trigger below.
set -eu

input=$(cat)
trigger=$(echo "$input" | jq -r '.trigger // "unknown"')
session_id=$(echo "$input" | jq -r '.session_id // "unknown"')

mkdir -p .claude/logs
printf '%s trigger=%s session=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$trigger" "$session_id" >> .claude/logs/precompact.log

exit 0
