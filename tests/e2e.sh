#!/bin/sh
#
# e2e.sh — the whole login against a real panel, for an account made for it.
#
#   TOTP_E2E_PASSWORD=... sh tests/e2e.sh https://panel.example.test totp-test
#
# The account must exist, be active, have no app yet and no email code.
# The script sets the app up through the API, then signs in again and again:
# wrong code, good code, replayed code, recovery code, reused recovery code,
# five wrong codes in one login. It ends by turning the app off again.
# The password is read from the environment and never printed.
#
set -u

BASE="${1:?usage: TOTP_E2E_PASSWORD=... sh tests/e2e.sh <panel-url> <username>}"
USER_NAME="${2:?usage: TOTP_E2E_PASSWORD=... sh tests/e2e.sh <panel-url> <username>}"
: "${TOTP_E2E_PASSWORD:?set TOTP_E2E_PASSWORD}"
HERE="$(cd "$(dirname "$0")" && pwd)"
LIB="$HERE/../module/totpauth/lib/totp.inc.php"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

failures=0
ok()  { printf '  ok    %s\n' "$1"; }
bad() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }
expect() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (got: $2, expected: $3)"; fi; }
contains() { if printf '%s' "$2" | grep -q "$3"; then ok "$1"; else bad "$1 (missing: $3)"; fi; }

# code for the step offset from now, from the base32 secret in $WORK/secret
code() {
	php -r 'require $argv[1]; echo totpauth_code(totpauth_base32_decode($argv[2]), totpauth_step() + (int) $argv[3]);' "$LIB" "$(cat "$WORK/secret")" "$1"
}

# new session, password: prints the path the login redirects to
login() {
	rm -f "$WORK/jar"
	curl -s -o /dev/null -c "$WORK/jar" -b "$WORK/jar" -w '%{redirect_url}' \
		--data-urlencode "username=$USER_NAME" --data-urlencode "password=$TOTP_E2E_PASSWORD" \
		-d 's_mod=login&s_pg=index' "$BASE/login/index.php" | sed "s#^$BASE##"
}

# the code page: prints the body, keeps its CSRF token
verify_page() {
	curl -s -c "$WORK/jar" -b "$WORK/jar" "$BASE/totpauth/verify.php" | tee "$WORK/page"
}

csrf_from_page() {
	sed -n "s/.*name=\"$1\" value=\"\([^\"]*\)\".*/\1/p" "$WORK/page" | head -1
}

# POST a code on the code page: prints "<status> <redirect>" and keeps the body
post_code() {
	id="$(csrf_from_page _csrf_id)"
	key="$(csrf_from_page _csrf_key)"
	curl -s -o "$WORK/page" -c "$WORK/jar" -b "$WORK/jar" -w '%{http_code} %{redirect_url}' \
		--data-urlencode "code=$1" --data-urlencode "_csrf_id=$id" --data-urlencode "_csrf_key=$key" \
		"$BASE/totpauth/verify.php" | sed "s#$BASE##"
}

api() {
	curl -s -c "$WORK/jar" -b "$WORK/jar" "$@"
}

json() {
	php -r '$j = json_decode(stream_get_contents(STDIN), true); $v = $j; foreach (explode(".", $argv[1]) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_array($v) ? json_encode($v) : var_export($v, true);' "$1"
}

api_post() {
	action="$1"; shift
	id="$(json csrf.id < "$WORK/last.json" | tr -d "'")"
	key="$(json csrf.key < "$WORK/last.json" | tr -d "'")"
	api -X POST --data-urlencode "action=$action" --data-urlencode "_csrf_id=$id" --data-urlencode "_csrf_key=$key" "$@" \
		"$BASE/totpauth/api.php" > "$WORK/last.json"
}

printf '1. sign in without app, set it up\n'
expect "password alone opens the panel" "$(login)" "/index.php"
api "$BASE/totpauth/api.php?action=status" > "$WORK/last.json"
expect "status: not set up" "$(json enabled < "$WORK/last.json")" "false"
api_post begin
json secret < "$WORK/last.json" | tr -d "' " > "$WORK/secret"
[ -s "$WORK/secret" ] && ok "setup gives a secret" || bad "setup gives a secret"
api_post confirm --data-urlencode "code=000000"
contains "wrong confirming code refused" "$(cat "$WORK/last.json")" '"ok":false'
api_post confirm --data-urlencode "code=$(code 0)"
expect "good confirming code sets it up" "$(json ok < "$WORK/last.json")" "true"
json codes < "$WORK/last.json" | tr -d '[]"' | tr ',' '\n' > "$WORK/recovery"
expect "eight recovery codes" "$(wc -l < "$WORK/recovery" | tr -d ' ')" "7"   # last line has no newline

printf '2. sign in with app\n'
expect "password leads to the code page" "$(login)" "/totpauth/verify.php"
page="$(verify_page)"
contains "code page renders the form" "$page" 'name="code"'
contains "code page carries the theme" "$page" 'brightcolor'
contains "wrong code refused" "$(post_code 000001; cat "$WORK/page")" 'Der Code passt nicht'
good="$(code 1)"
expect "good code opens the panel" "$(post_code "$good")" "302 /index.php"
contains "panel page loads" "$(api "$BASE/index.php")" 'id=.pageContent'

printf '3. replay\n'
login > /dev/null
verify_page > /dev/null
contains "the code that opened the last login is refused" "$(post_code "$good"; cat "$WORK/page")" 'Der Code passt nicht'

printf '4. recovery code\n'
rec="$(head -1 "$WORK/recovery")"
login > /dev/null
verify_page > /dev/null
expect "recovery code opens the panel" "$(post_code "$rec")" "302 /index.php"
login > /dev/null
verify_page > /dev/null
contains "same recovery code refused" "$(post_code "$rec"; cat "$WORK/page")" 'Wiederherstellungscode passt nicht'

printf '5. five wrong codes end the login\n'
login > /dev/null
verify_page > /dev/null
i=0
while [ $i -lt 5 ]; do post_code 111111 > /dev/null; i=$((i + 1)); done
contains "login ended" "$(cat "$WORK/page")" 'Anmeldung wurde abgebrochen'
expect "code page now sends back to the login" "$(curl -s -o /dev/null -b "$WORK/jar" -w '%{redirect_url}' "$BASE/totpauth/verify.php" | sed "s#$BASE##")" "/login/index.php"

printf '6. turn it off again\n'
login > /dev/null
verify_page > /dev/null
post_code "$(sed -n 2p "$WORK/recovery")" > /dev/null
api "$BASE/totpauth/api.php?action=status" > "$WORK/last.json"
api_post disable --data-urlencode "code=$(sed -n 3p "$WORK/recovery")"
expect "disable with a recovery code" "$(json ok < "$WORK/last.json")" "true"
expect "password alone opens the panel again" "$(login)" "/index.php"

printf '\n'
if [ $failures -gt 0 ]; then
	printf '%s check(s) failed\n' "$failures" >&2
	exit 1
fi
printf 'All end-to-end checks passed.\n'
