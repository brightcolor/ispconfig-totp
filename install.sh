#!/bin/sh
#
# Install the authenticator app login into an ISPConfig panel (3.3).
#
#   sudo ./install.sh [path-to-ispconfig-interface]
#
# Default path: /usr/local/ispconfig/interface
#
# Adds, and changes nothing else:
#   interface/web/totpauth/               module: plugin, code page, API
#   interface/web/js/js.d/totpauth*.js    profile row, modal, admin button
#   <panel>/totpauth/secret.key           key for the stored secrets, created once
#   table totpauth_user                   in the panel database
#
# Re-run it after every ISPConfig update: it checks that the hooks it relies
# on are still there and puts the files back if an update removed them.
#
set -eu

fail() { printf 'error: %s\n' "$1" >&2; exit 1; }

INTERFACE=""
for arg in "$@"; do
	case "$arg" in
		-*) fail "unknown option $arg" ;;
		*)
			[ -z "$INTERFACE" ] || fail "two paths given ($INTERFACE and $arg), expected one interface path"
			INTERFACE="$arg"
			;;
	esac
done
INTERFACE="${INTERFACE:-/usr/local/ispconfig/interface}"

HERE="$(cd "$(dirname "$0")" && pwd)"
WEB="$INTERFACE/web"
PANEL_ROOT="$(dirname "$INTERFACE")"
KEY_DIR="$PANEL_ROOT/totpauth"
KEY="$KEY_DIR/secret.key"
STAGE="$PANEL_ROOT/totpauth-staging"
BACKUP_DIR="$PANEL_ROOT/totpauth-backups"
TARGET="$WEB/totpauth"
# Tests replace the database step with TOTPAUTH_SETUP_CMD (an executable);
# on a panel it is always install/setup.php.
setup() {
	if [ -n "${TOTPAUTH_SETUP_CMD:-}" ]; then
		"$TOTPAUTH_SETUP_CMD" "$@"
	else
		php "$HERE/install/setup.php" "$@"
	fi
}

[ -f "$HERE/VERSION" ] || fail "VERSION not found next to this script"
[ -d "$HERE/module/totpauth" ] || fail "module/totpauth not found next to this script"
[ -f "$INTERFACE/lib/config.inc.php" ] || fail "$INTERFACE/lib/config.inc.php not found - pass the interface path as the first argument"
VERSION="$(tr -d ' \r\n' < "$HERE/VERSION")"
printf 'totpauth %s\n' "$VERSION"

# ---------------------------------------------------------------------------
# The hooks this relies on. An ISPConfig update may move them; then the
# login would silently skip the app, so the installer refuses instead.
# ---------------------------------------------------------------------------
APP_VERSION="$(sed -n "s/.*define('ISPC_APP_VERSION',[ ]*'\([^']*\)').*/\1/p" "$INTERFACE/lib/config.inc.php" | head -1)"
[ -n "$APP_VERSION" ] || fail "cannot read ISPC_APP_VERSION from $INTERFACE/lib/config.inc.php"
printf 'Panel: ISPConfig %s\n' "$APP_VERSION"

grep -q "plugin.d" "$INTERFACE/lib/classes/plugin.inc.php" 2>/dev/null \
	|| fail "lib/classes/plugin.inc.php no longer loads web/<module>/lib/plugin.d - the plugin would never run"
grep -q "raiseEvent('login'" "$WEB/login/index.php" 2>/dev/null \
	|| fail "login/index.php no longer raises the login event - the app would be skipped at login"
grep -q "js.d" "$WEB/index.php" 2>/dev/null \
	|| fail "index.php no longer loads js/js.d - the profile would show no setup"
grep -q "s_pending" "$WEB/login/index.php" 2>/dev/null \
	|| fail "login/index.php no longer parks sessions in s_pending - this version of ISPConfig is not supported"
printf 'Hooks: plugin.d, login event, js.d found\n'

check="$(setup "$INTERFACE" check)" || fail "the database check failed, see above"
force_days="$(printf '%s\n' "$check" | sed -n 's/^force_days=\([0-9]*\)$/\1/p')"
[ -n "$force_days" ] || fail "the database check gave no answer ($check)"
if [ "$force_days" -gt 0 ]; then
	fail "\"Force password change after X days\" is $force_days. A forced password change opens the session without the login event, so users would get in without the app. Set it to 0 under System -> Interface -> Interface Config, tab Misc, then run this again."
fi
printf 'Forced password change: off\n'

# ---------------------------------------------------------------------------
# Key: created once, never replaced. A new key would make every stored
# secret unreadable and lock out everyone who uses the app.
# ---------------------------------------------------------------------------
OWNER="$(ls -ld "$WEB/login" | awk '{print $3 ":" $4}')"
if [ -f "$KEY" ]; then
	size="$(wc -c < "$KEY" | tr -d ' ')"
	[ "$size" = "32" ] || fail "$KEY exists but holds $size bytes instead of 32 - restore it from a backup, do not replace it"
	printf 'Key: %s kept\n' "$KEY"
else
	mkdir -p "$KEY_DIR"
	( umask 077; head -c 32 /dev/urandom > "$KEY" )
	[ "$(wc -c < "$KEY" | tr -d ' ')" = "32" ] || { rm -f "$KEY"; fail "could not create a 32-byte key in $KEY"; }
	printf 'Key: %s created\n' "$KEY"
fi
chown "$OWNER" "$KEY_DIR" "$KEY" 2>/dev/null || printf 'warning: could not chown the key to %s\n' "$OWNER" >&2
chmod 700 "$KEY_DIR"
chmod 400 "$KEY"

setup "$INTERFACE" create || fail "the table could not be created, see above"

# ---------------------------------------------------------------------------
# Files: staged and checked first, then swapped in
# ---------------------------------------------------------------------------
rm -rf "$STAGE"
mkdir -p "$STAGE"
cp -R "$HERE/module/totpauth" "$STAGE/totpauth"
printf '%s\n' "$VERSION" > "$STAGE/totpauth/VERSION"
cp "$HERE/jsd/totpauth.js" "$HERE/jsd/totpauth-qr.js" "$STAGE/"

if command -v php >/dev/null 2>&1; then
	bad="$(find "$STAGE" -name '*.php' | while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || printf '%s ' "${f#"$STAGE"/}"; done)"
	if [ -n "$bad" ]; then
		rm -rf "$STAGE"
		fail "these files do not parse: $bad- installed files left unchanged"
	fi
fi

chown -R "$OWNER" "$STAGE" 2>/dev/null || printf 'warning: could not chown to %s\n' "$OWNER" >&2
find "$STAGE" -type d -exec chmod 750 {} +
find "$STAGE" -type f -exec chmod 640 {} +

if [ -d "$TARGET" ]; then
	mkdir -p "$BACKUP_DIR"
	backup="$BACKUP_DIR/totpauth-$(date +%Y%m%d%H%M%S)"
	mv "$TARGET" "$backup"
	printf 'Previous module moved to %s\n' "$backup"
	# keep the five newest
	( cd "$BACKUP_DIR" && ls -1dt totpauth-* 2>/dev/null | tail -n +6 | while read -r old; do rm -rf -- "$BACKUP_DIR/$old"; done )
fi
mv "$STAGE/totpauth" "$TARGET"
mkdir -p "$WEB/js/js.d"
mv "$STAGE/totpauth.js" "$STAGE/totpauth-qr.js" "$WEB/js/js.d/"
rm -rf "$STAGE"

for f in "$TARGET/lib/plugin.d/totpauth_plugin.inc.php" "$TARGET/verify.php" "$TARGET/api.php" "$WEB/js/js.d/totpauth.js" "$WEB/js/js.d/totpauth-qr.js"; do
	[ -f "$f" ] || fail "$f is missing after the install"
done

printf '\nInstalled totpauth %s.\n' "$VERSION"
printf 'Users set it up under Tools -> Settings. Sessions pick the plugin up at their next login.\n'
