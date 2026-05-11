#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
ASK_SELF_PATH=${ASK_SELF_PATH:-/Users/noelsaw/Documents/GH Repos/ask-self}

if [ ! -d "$ASK_SELF_PATH" ]; then
  echo "ASK_SELF_PATH does not exist: $ASK_SELF_PATH" >&2
  exit 1
fi

ENTRYPOINT=$ASK_SELF_PATH/ask_self_query.py
if [ ! -f "$ENTRYPOINT" ]; then
  echo "ask-self query entrypoint missing: $ENTRYPOINT" >&2
  exit 1
fi

if [ -n "${ASK_SELF_PYTHON:-}" ]; then
  PYTHON_BIN=$ASK_SELF_PYTHON
elif [ -x "$ASK_SELF_PATH/.venv/bin/python" ]; then
  PYTHON_BIN=$ASK_SELF_PATH/.venv/bin/python
else
  PYTHON_BIN=python3
fi

HARNESS=$PROJECT_ROOT/ask_self/ask_self_harness.json

export TOKENIZERS_PARALLELISM=${TOKENIZERS_PARALLELISM:-false}

cd "$PROJECT_ROOT"

exec "$PYTHON_BIN" "$ENTRYPOINT" --harness-config "$HARNESS" "$@"
