#!/usr/bin/env bash
#
# Installs LuwiPress version-controlled git hooks into .git/hooks.
# Run once per clone:  bash tools/install-git-hooks.sh
#
set -eu

repo_root=$(git rev-parse --show-toplevel)
src="$repo_root/tools/git-hooks"
dest="$repo_root/.git/hooks"

mkdir -p "$dest"
for hook in "$src"/*; do
  name=$(basename "$hook")
  cp "$hook" "$dest/$name"
  chmod +x "$dest/$name"
  echo "installed: .git/hooks/$name"
done

echo "Done. Bypass any hook with: git commit --no-verify"
