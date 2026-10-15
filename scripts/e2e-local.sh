#!/usr/bin/env bash
#
# The local counterpart of the e2e and phase1 CI jobs (§7.2, §8, OD-3): installs the current branch through one of
# the four entry points into a new directory and checks what it left behind (phase 1), including the browser wizard.
# The herd entry then runs skv:install with the e2e answers and skv:doctor (phase 2). It never deletes anything and
# never runs a Herd command that changes a site; it prints the cleanup commands instead.
#
# Usage: scripts/e2e-local.sh [<target-dir>] [--https] [--entry=herd|terminal|terminal-nodb|composer|clone] [--phase1-only]
#   <target-dir>   where the new app goes; it must not exist yet (default for herd: $HOME/Herd/m6b-e2e)
#   --https        answer https=true, so the Herd step secures the site (herd entry, phase 2)
#   --entry=       herd (default): Herd's laravel new, then phase 2; terminal: laravel new --database=sqlite;
#                  terminal-nodb: laravel new without --database; composer: composer create-project;
#                  clone: git clone plus the commands of composer setup before skv:install (RL-11)
#   --phase1-only  stop after phase 1, leaving the site as phase 1 left it (token present, no marker)
#
# Every entry but herd serves the app with php artisan serve on a free port of 127.0.0.1 for the wizard check, and
# ends after phase 1. Only the committed state of the current branch is installed (as dev-<branch>). Composer runs
# with a temporary COMPOSER_HOME, so the global Composer configuration and credentials are never read or changed.
# The setup token is never printed.

set -euo pipefail

TARGET=""
HTTPS=false
ENTRY=herd
PHASE1_ONLY=false

for argument in "$@"; do
    case "$argument" in
        --https) HTTPS=true ;;
        --entry=*) ENTRY="${argument#--entry=}" ;;
        --phase1-only) PHASE1_ONLY=true ;;
        -*) echo "Unknown option: $argument" >&2; exit 2 ;;
        *) [ -z "$TARGET" ] || { echo "Only one target directory is allowed" >&2; exit 2; }; TARGET="$argument" ;;
    esac
done

case "$ENTRY" in
    herd | terminal | terminal-nodb | composer | clone) ;;
    *) echo "Unknown entry: $ENTRY (use herd, terminal, terminal-nodb, composer or clone)" >&2; exit 2 ;;
esac

if [ "$ENTRY" = herd ]; then
    TARGET="${TARGET:-$HOME/Herd/m6b-e2e}"
elif [ -z "$TARGET" ]; then
    echo "The $ENTRY entry needs a target directory." >&2
    exit 2
elif [ "$HTTPS" = true ]; then
    echo "--https applies to the herd entry's phase 2 only." >&2
    exit 2
fi

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

if [[ "$ENTRY" =~ ^(herd|terminal|terminal-nodb)$ ]] && ! command -v laravel > /dev/null; then
    echo "The laravel command was not found; Herd ships it in ~/Library/Application Support/Herd/bin." >&2
    exit 1
fi

PACKAGE="$(php -r 'echo json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR)["name"];' "$REPO/composer.json")"

# The real cache dir is read before COMPOSER_HOME moves, otherwise it would point into the temporary home.
CACHE_DIR="$(composer config --global cache-dir)"
export COMPOSER_HOME="$(mktemp -d)"
export COMPOSER_CACHE_DIR="$CACHE_DIR"
WORK="$(mktemp -d)"
SERVE_PID=""
# The one EXIT trap: a second one would replace this cleanup.
trap 'kill "${SERVE_PID:-}" 2>/dev/null || true; rm -rf "$COMPOSER_HOME" "$WORK/expected"' EXIT
composer config -g repositories."${PACKAGE#*/}" vcs "$REPO"

LOG="$WORK/install.log"
ANSWERS="$WORK/e2e-answers.json"

herd_lists() {
    # $1: the read-only herd listing (links or isolated); true when it has a row for the site.
    command -v herd > /dev/null && herd "$1" 2>/dev/null | grep -qiE "^\| *${NAME//./\\.}(\.[a-z]+)? *\|"
}

LINKED_BEFORE=false
if [ "$ENTRY" = herd ] && herd_lists links; then LINKED_BEFORE=true; fi

# The installer hides the hooks' output from a detected AI agent (it logs to a temporary file and prints JSON), so
# the agent markers are removed for this one command; the output then matches a run from a terminal.
AGENT_VARIABLES=(AI_AGENT CURSOR_AGENT GEMINI_CLI CODEX_SANDBOX CODEX_CI CODEX_THREAD_ID AUGMENT_AGENT OPENCODE_CLIENT
    OPENCODE AMP_CURRENT_THREAD_ID CLAUDECODE CLAUDE_CODE REPL_ID COPILOT_MODEL COPILOT_ALLOW_ALL COPILOT_GITHUB_TOKEN
    COPILOT_CLI ANTIGRAVITY_AGENT PI_CODING_AGENT KIRO_AGENT_PATH)
UNSET=()
for variable in "${AGENT_VARIABLES[@]}"; do UNSET+=(-u "$variable"); done

FAILED=0

install_app() {
    case "$ENTRY" in
        herd) (cd "$TARGET_PARENT" && env "${UNSET[@]}" laravel new "$NAME" --using="$PACKAGE:dev-$BRANCH" --no-interaction --no-ansi --database=sqlite --npm --pest --git --boost) ;;
        terminal) (cd "$TARGET_PARENT" && env "${UNSET[@]}" laravel new "$NAME" --using="$PACKAGE:dev-$BRANCH" --no-interaction --no-ansi --database=sqlite) ;;
        terminal-nodb) (cd "$TARGET_PARENT" && env "${UNSET[@]}" laravel new "$NAME" --using="$PACKAGE:dev-$BRANCH" --no-interaction --no-ansi) ;;
        composer) composer create-project "$PACKAGE:dev-$BRANCH" "$TARGET" --stability=dev --no-interaction ;;
        # RL-11: composer setup without its final skv:install, which is the CLI by design (§5.3.3).
        clone)
            git clone --quiet --branch "$BRANCH" "$REPO" "$TARGET"
            (cd "$TARGET" \
                && composer install --no-interaction \
                && php -r "file_exists('.env') || copy('.env.example', '.env');" \
                && php artisan skv:bootstrap --no-interaction)
            ;;
    esac
}

echo "Installing $PACKAGE:dev-$BRANCH into $TARGET through the $ENTRY entry (log: $LOG)"
if ! install_app 2>&1 | tee "$LOG"; then
    echo "FAIL: the $ENTRY install exited with an error"
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

# The wizard check (§7.2). The token is read into TOKEN and compared, never printed; the token request reads its URL
# from stdin, so the token stays out of the process list too.
TOKEN=""
JAR="$WORK/cookies.txt"
token_path() { (cd "$TARGET" && php -r 'require "vendor/autoload.php"; echo App\Setup\SetupToken::PATH;'); }
# Env::writeVariables quotes the key skv:bootstrap writes (composer, clone); the installer's key:generate does not.
has_app_key() { grep -qE '^APP_KEY="?base64:' "$TARGET/.env"; }
home_redirects() {
    local location
    location="$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/")" && [ -n "$TOKEN" ] && [[ "$location" == *"/welcome-setup?token=$TOKEN" ]]
}
token_accepted() {
    [ "$(printf 'url = "%s"\n' "$BASE/welcome-setup?token=$TOKEN" | curl -s -K - -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}')" = 302 ]
}
wizard_renders() {
    local code
    code="$(curl -s -c "$JAR" -b "$JAR" -o "$WORK/wizard.html" -w '%{http_code}' "$BASE/welcome-setup")" \
        && [ "$code" = 200 ] && grep -q 'data-test="setup-wizard"' "$WORK/wizard.html"
}

wizard_check() {
    # $1: the base URL; $2: what the label adds, if anything.
    BASE="$1"
    local suffix="${2:-}" path
    rm -f "$JAR" "$WORK/wizard.html"
    path="$(token_path 2>/dev/null || true)"
    TOKEN=""
    if [ -n "$path" ] && [ -f "$TARGET/$path" ]; then TOKEN="$(tr -d '[:space:]' < "$TARGET/$path")"; fi

    check ".env has an APP_KEY$suffix" has_app_key
    check "the setup token exists$suffix" test -n "$TOKEN"
    check "/ redirects to /welcome-setup with the token$suffix" home_redirects
    check "/welcome-setup?token=… answers 302$suffix" token_accepted
    check "/welcome-setup answers 200 with the wizard$suffix" wizard_renders
    rm -f "$JAR" "$WORK/wizard.html"
}

free_port() { php -r '$s=stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s,false))[1];'; }
serving() { curl -s -o /dev/null "$1/favicon.ico"; }
wait_until_serving() {
    for _ in $(seq 1 60); do
        if serving "$1"; then return 0; fi
        sleep 0.5
    done

    return 1
}

if [ "$ENTRY" = herd ]; then
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

    URL="http://$NAME.test"
else
    echo
    echo "Phase 1 (§7.2): $ENTRY"
    PORT="$(free_port)"
    URL="http://127.0.0.1:$PORT"
    (cd "$TARGET" && exec php artisan serve --host=127.0.0.1 --port="$PORT" > /dev/null 2>&1) &
    SERVE_PID=$!
    check "php artisan serve answers on $URL" wait_until_serving "$URL"
fi

# §7.2: the wizard renders without public/build.
if [[ "$ENTRY" =~ ^(composer|clone)$ ]]; then
    check "public/build/manifest.json is absent" test ! -e "$TARGET/public/build/manifest.json"
fi

wizard_check "$URL"

if [ -d "$TARGET/public/build" ]; then
    mv "$TARGET/public/build" "$WORK/build"
    wizard_check "$URL" " (without public/build)"
    mv "$WORK/build" "$TARGET/public/build"
fi

if [ "$ENTRY" != herd ]; then
    kill "$SERVE_PID" 2>/dev/null || true
    SERVE_PID=""
fi

if [ "$ENTRY" = herd ] && [ "$PHASE1_ONLY" = false ]; then
    php -r '$a = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); $a["https"] = $argv[3] === "true"; file_put_contents($argv[2], json_encode($a, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));' "$REPO/tests/Fixtures/e2e-answers.json" "$ANSWERS" "$HTTPS"

    echo
    echo "Phase 2: skv:install (https=$HTTPS)"
    INSTALLED=true
    (cd "$TARGET" && php artisan skv:install --no-interaction --answers=@"$ANSWERS") || INSTALLED=false
    check "skv:install finished" "$INSTALLED"
    check "require-dev laravel/boost is ^2.10 (DEC-22 rule 5)" boost_restored

    echo
    echo "skv:doctor --json"
    DOCTOR_GREEN=true
    (cd "$TARGET" && php artisan skv:doctor --json) || DOCTOR_GREEN=false
    check "skv:doctor is green" "$DOCTOR_GREEN"
fi

echo
if [ "$FAILED" = 0 ]; then echo "Summary: every check passed."; else echo "Summary: some checks failed (see FAIL above)."; fi

echo
echo "Clean up when you are done (this script runs none of these):"
if [ "$ENTRY" = herd ]; then
    echo "  herd unsecure $NAME"
    if herd_lists isolated; then echo "  herd unisolate --site=$NAME"; fi
    if [ "$LINKED_BEFORE" = false ] && herd_lists links; then echo "  herd unlink $NAME"; fi
fi
echo "  rm -rf \"$TARGET\""
echo "  rm -rf \"$WORK\""

exit "$FAILED"
