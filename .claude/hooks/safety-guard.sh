#!/bin/sh
# PreToolUse safety-guard hook (project audit, 2026-08-04, Tier 1).
# Blocks the three destructive Bash patterns the audit called out:
# recursive-force rm, DROP TABLE/DATABASE, and a forced git push. This is
# a local guardrail, not a security boundary — pragmatic substring/regex
# matching against the common real-world forms of each, not a shell
# parser. Deliberately covers accidental/careless commands, not
# deliberately obfuscated ones (e.g. a force flag split across shell
# variables) — that's outside what this is for.
set -eu

cmd=$(cat | jq -r '.tool_input.command // empty')

# Strip heredoc BODIES (e.g. `git commit -m "$(cat <<'EOF' ... EOF)"`,
# this project's own commit convention) before pattern-matching —
# otherwise prose that merely MENTIONS a dangerous command (exactly what
# a commit message documenting this very hook contains) false-positives
# as an actual dangerous command. Found live: the first version of this
# hook blocked its own introducing commit for this reason. Handles any
# `<<DELIM` / `<<'DELIM'` / `<<-DELIM` opener paired with a bare DELIM
# closing line, not just literal `EOF` — matches this project's varied
# delimiter use (EOF, SQL, CRON, JSON, ...) across this session's actual
# commits.
scanned=$(echo "$cmd" | sed "/<<[-]*'\\{0,1\\}[A-Za-z_][A-Za-z0-9_]*'\\{0,1\\}/,/^[A-Za-z_][A-Za-z0-9_]*\$/d")

if echo "$scanned" | grep -qiE '(^|[;&|]) *(sudo +)?rm +(-[a-zA-Z]*rf[a-zA-Z]*|-[a-zA-Z]*fr[a-zA-Z]*|-r +-f|-f +-r|--recursive +--force|--force +--recursive)\b' \
  || echo "$scanned" | grep -qiE '\bdrop +(table|database)\b' \
  || echo "$scanned" | grep -qiE '\bgit +push\b.*(--force\b|--force-with-lease\b| -f( |$))'
then
  cat <<'JSON'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"Blocked by the project safety-guard hook (audit Tier 1): this command matches a destructive pattern (rm -rf, DROP TABLE/DATABASE, or git push --force). Run it manually outside Claude Code if it is genuinely needed."}}
JSON
fi

exit 0
