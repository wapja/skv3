#!/usr/bin/env bash
#
# The local counterpart of the e2e CI job (§8, OD-3): installs the current branch with Herd's Laravel installer into
# a new directory, checks what the installer left behind (phase 1), runs skv:install with the e2e answers and then
# skv:doctor. It never deletes anything and never runs a Herd command that changes a site; it prints the cleanup
# commands instead.
#
# Usage: scripts/e2e-local.sh [<target-dir>] [--https]
#   <target-dir>  where the new app goes (default: $HOME/Herd/m6a-e2e); it must not exist yet
#   --https       answer https=true, so the Herd step secures the site
#
# Only the committed state of the current branch is installed (as dev-<branch>). Composer runs with a temporary
# COMPOSER_HOME, so the global Composer configuration and credentials are never read or changed.

set -euo pipefail

TARGET=""
HTTPS=false

for argument in "$@"; do
    case "$argument" in
        --https) HTTPS=true ;;
        -*) echo "Unknown option: $argument" >&2; exit 2 ;;
        *) [ -z "$TARGET" ] || { echo "Only one target directory is allowed" >&2; exit 2; }; TARGET="$argument" ;;
    esac
done

TARGET="${TARGET:-$HOME/Herd/m6a-e2e}"

if [ -e "$TARGET" ]; then
    echo "Refusing to install into $TARGET: it already exists. Remove it yourself or pick another directory." >&2
    exit 1
fi

TARGET_PARENT="$(cd "$(dirname "$TARGET")" && pwd)"
NAME="$(basename "$TARGET")"
TARGET="$TARGET_PARENT/$NAME"

REPO=$(git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --show-toplevel)
BRANCH=$(git -C "$REPO" rev-parse --abbrev-ref HEAD)

if [ "$BRANCH" = HEAD ]; then
    echo "Check out a branch first: the installer installs dev-<branch>." >&2
    exit 1
fi

if [ -n "$(git -C "$REPO" status --porcelain)" ]; then
    echo "WARN: the working tree has uncommitted changes; only the commits on $BRANCH are installed."
fi

if ! command -v laravel > /dev/null; then
    echo "The laravel command was not found; Herd ships it in ~/Library/Application Support/Herd/bin." >&2
    exit 1
fi

PACKAGE="$(php -r 'echo json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR)["name"];' "$REPO/composer.json")"

# The real cache dir is read before COMPOSER_HOME moves, otherwise it would point into the temporary home.
CACHE_DIR="$(composer config --global cache-dir)"
export COMPOSER_HOME="$(mktemp -d)"
export COMPOSER_CACHE_DIR="$CACHE_DIR"
WORK="$(mktemp -d)"
trap 'rm -rf "$COMPOSER_HOME" "$WORK/expected"' EXIT
composer config -g repositories."${PACKAGE#*/}" vcs "$REPO"

LOG="$WORK/install.log"
ANSWERS="$WORK/e2e-answers.json"

herd_lists() {
    # $1: the read-only herd listing (links or isolated); true when it has a row for the site.
    command -v herd > /dev/null && herd "$1" 2>/dev/null | grep -qiE "^\| *${NAME//./\\.}(\.[a-z]+)? *\|"
}

LINKED_BEFORE=false
if herd_lists links; then LINKED_BEFORE=true; fi

# The installer hides the hooks' output from a detected AI agent (it logs to a temporary file and prints JSON), so
# the agent markers are removed for this one command; the output then matches a run from a terminal.
AGENT_VARIABLES=(AI_AGENT CURSOR_AGENT GEMINI_CLI CODEX_SANDBOX CODEX_CI CODEX_THREAD_ID AUGMENT_AGENT OPENCODE_CLIENT
    OPENCODE AMP_CURRENT_THREAD_ID CLAUDECODE CLAUDE_CODE REPL_ID COPILOT_MODEL COPILOT_ALLOW_ALL COPILOT_GITHUB_TOKEN
    COPILOT_CLI ANTIGRAVITY_AGENT PI_CODING_AGENT KIRO_AGENT_PATH)
UNSET=()
for variable in "${AGENT_VARIABLES[@]}"; do UNSET+=(-u "$variable"); done

FAILED=0

echo "Installing $PACKAGE:dev-$BRANCH into $TARGET (log: $LOG)"
if ! (cd "$TARGET_PARENT" && env "${UNSET[@]}" laravel new "$NAME" --using="$PACKAGE:dev-$BRANCH" --no-interaction --no-ansi --database=sqlite --npm --pest --git --boost) 2>&1 | tee "$LOG"; then
    echo "FAIL: laravel new exited with an error"
    FAILED=1
fi

check() {
    # $1: what is checked; the rest: the command that must succeed.
    local label="$1"
    shift

    if "$@" > /dev/null 2>&1; then
        echo "PASS: $label"
    else
        echo "FAIL: $label"
        FAILED=1
    fi
}

bootstrapped_once() { [ "$(grep -c 'skv:install' "$LOG" || true)" = 1 ]; }
migrated() { local status; status="$(cd "$TARGET" && php artisan migrate:status --no-ansi)" && ! grep -q 'Pending' <<< "$status"; }
one_boost_update() {
    php -r '$c = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); exit(count(array_filter($c["scripts"]["post-update-cmd"] ?? [], fn ($line) => str_contains($line, "boost:update"))) <= 1 ? 0 : 1);' "$TARGET/composer.json"
}
boost_restored() {
    php -r '$c = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); exit(($c["require-dev"]["laravel/boost"] ?? null) === "^2.10" ? 0 : 1);' "$TARGET/composer.json"
}

# What the installer received: a clone of the branch, so ignored local files (screenshots, caches) do not count.
git clone --quiet --branch "$BRANCH" "$REPO" "$WORK/expected"

echo
echo "Phase 1 (§5.2, §7.2, §8)"
check "skv:bootstrap ran once" bootstrapped_once
check ".env has DB_CONNECTION=sqlite" grep -q '^DB_CONNECTION=sqlite' "$TARGET/.env"
check "the SQLite database is migrated" migrated
check "public/build/manifest.json exists" test -f "$TARGET/public/build/manifest.json"
check "tests.yml is unchanged" diff "$WORK/expected/.github/workflows/tests.yml" "$TARGET/.github/workflows/tests.yml"
check "tests/Pest.php is unchanged" diff "$WORK/expected/tests/Pest.php" "$TARGET/tests/Pest.php"
check "tests/ is unchanged" diff -r "$WORK/expected/tests" "$TARGET/tests"
check "post-update-cmd holds at most one boost:update" one_boost_update

php -r '$a = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); $a["https"] = $argv[3] === "true"; file_put_contents($argv[2], json_encode($a, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));' "$REPO/tests/Fixtures/e2e-answers.json" "$ANSWERS" "$HTTPS"

echo
echo "Phase 2: skv:install (https=$HTTPS)"
INSTALLED=true
(cd "$TARGET" && php artisan skv:install --no-interaction --answers="$(cat "$ANSWERS")") || INSTALLED=false
check "skv:install finished" "$INSTALLED"
check "require-dev laravel/boost is ^2.10 (DEC-22 rule 5)" boost_restored

echo
echo "skv:doctor --json"
DOCTOR_GREEN=true
(cd "$TARGET" && php artisan skv:doctor --json) || DOCTOR_GREEN=false
check "skv:doctor is green" "$DOCTOR_GREEN"

echo
if [ "$FAILED" = 0 ]; then echo "Summary: every check passed."; else echo "Summary: some checks failed (see FAIL above)."; fi

echo
echo "Clean up when you are done (this script runs none of these):"
echo "  herd unsecure $NAME"
if herd_lists isolated; then echo "  herd unisolate --site=$NAME"; fi
if [ "$LINKED_BEFORE" = false ] && herd_lists links; then echo "  herd unlink $NAME"; fi
echo "  rm -rf \"$TARGET\""
echo "  rm -rf \"$WORK\""

exit "$FAILED"
