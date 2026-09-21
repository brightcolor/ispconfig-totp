#!/bin/sh
#
# install-test.sh — install.sh and uninstall.sh against a mock panel.
#
#   sh tests/install-test.sh
#
# The mock is built from the files of ispconfig-brightcolor/harness/vendor
# (a copy of a real panel's interface) where they exist, and from minimal
# stand-ins otherwise. The database step is replaced by a stub; the real
# SQL runs in the end-to-end test on a panel.
#
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(dirname "$HERE")"
WORK="$REPO/harness/state/install-test"
IFACE="$WORK/interface"
WEB="$IFACE/web"
KEY="$WORK/totpauth/secret.key"

failures=0
ok()  { printf '  ok    %s\n' "$1"; }
bad() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
run()  { sh "$REPO/install.sh" "$@" > "$WORK/last.log" 2>&1; }
urun() { sh "$REPO/uninstall.sh" "$@" > "$WORK/last.log" 2>&1; }

rm -rf "$WORK"
mkdir -p "$IFACE/lib/classes" "$WEB/login" "$WEB/js/js.d"
printf "<?php\ndefine('ISPC_APP_VERSION', '3.3.1p1');\n" > "$IFACE/lib/config.inc.php"
printf '<?php\n// loads ISPC_WEB_PATH/<module>/lib/plugin.d\n' > "$IFACE/lib/classes/plugin.inc.php"
printf "<?php\n\$_SESSION['s_pending'] = 1;\n\$app->plugin->raiseEvent('login', \$username);\n" > "$WEB/login/index.php"
printf "<?php\n\$js_d = ISPC_WEB_PATH . '/js/js.d';\n" > "$WEB/index.php"

# Database stub: answers "check" with the days in $WORK/force_days and logs every call.
cat > "$WORK/setup-stub" <<'EOF'
#!/bin/sh
dir="$(dirname "$0")"
printf '%s\n' "$2" >> "$dir/setup.calls"
case "$2" in
	check) printf 'force_days=%s\n' "$(cat "$dir/force_days" 2>/dev/null || echo 0)" ;;
	create|drop) echo "stub $2" ;;
esac
EOF
chmod +x "$WORK/setup-stub"
export TOTPAUTH_SETUP_CMD="$WORK/setup-stub"

printf '1. fresh install\n'
run "$IFACE"; code=$?
check "exit 0" "[ $code -eq 0 ]"
check "module in web/totpauth" "[ -f '$WEB/totpauth/lib/plugin.d/totpauth_plugin.inc.php' ] && [ -f '$WEB/totpauth/api.php' ]"
check "scripts in js.d" "[ -f '$WEB/js/js.d/totpauth.js' ] && [ -f '$WEB/js/js.d/totpauth-qr.js' ]"
check "key of 32 bytes" "[ \"\$(wc -c < '$KEY' | tr -d ' ')\" = 32 ]"
check "table created" "grep -q create '$WORK/setup.calls'"
check "no staging left" "[ ! -e '$WORK/totpauth-staging' ]"
key1="$(cksum < "$KEY")"

printf '2. second run keeps the key\n'
chmod 600 "$KEY"
run "$IFACE"; code=$?
check "exit 0" "[ $code -eq 0 ]"
check "key unchanged" "[ \"\$(cksum < '$KEY')\" = '$key1' ]"
check "previous module backed up" "ls -d '$WORK'/totpauth-backups/totpauth-* >/dev/null 2>&1"

printf '3. forced password change on\n'
echo 30 > "$WORK/force_days"
before="$(ls -la "$WEB/totpauth" | cksum)"
run "$IFACE"; code=$?
echo 0 > "$WORK/force_days"
check "refused with exit 1" "[ $code -eq 1 ] && grep -q 'Force password change' '$WORK/last.log'"
check "installed module untouched" "[ \"\$(ls -la '$WEB/totpauth' | cksum)\" = '$before' ]"

printf '4. login hook missing\n'
cp "$WEB/login/index.php" "$WORK/index.bak"
printf "<?php\n\$_SESSION['s_pending'] = 1;\n" > "$WEB/login/index.php"
run "$IFACE"; code=$?
cp "$WORK/index.bak" "$WEB/login/index.php"
check "refused with exit 1" "[ $code -eq 1 ] && grep -q 'login event' '$WORK/last.log'"

printf '5. damaged key is not replaced\n'
chmod 600 "$KEY"
printf 'short' > "$KEY"
run "$IFACE"; code=$?
check "refused with exit 1" "[ $code -eq 1 ] && grep -q 'restore it from a backup' '$WORK/last.log'"
check "damaged key left as it was" "[ \"\$(cat '$KEY')\" = short ]"

printf '6. uninstall keeps data, --purge removes it\n'
urun "$IFACE"; code=$?
check "exit 0" "[ $code -eq 0 ]"
check "module and scripts gone" "[ ! -e '$WEB/totpauth' ] && [ ! -e '$WEB/js/js.d/totpauth.js' ]"
check "key kept" "[ -f '$KEY' ]"
urun --purge "$IFACE"; code=$?
check "purge: exit 0" "[ $code -eq 0 ]"
check "purge: key gone" "[ ! -e '$KEY' ]"
check "purge: table dropped" "grep -q drop '$WORK/setup.calls'"

printf '\n'
if [ $failures -gt 0 ]; then
	printf '%s check(s) failed, last output in %s\n' "$failures" "$WORK/last.log" >&2
	exit 1
fi
printf 'All installer checks passed.\n'
