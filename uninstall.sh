#!/bin/sh
#
# Remove the authenticator app login from an ISPConfig panel.
#
#   sudo ./uninstall.sh [--purge] [path-to-ispconfig-interface]
#
# Without --purge the table and the key stay, so a later install picks up
# every account's app again. --purge removes both; every app set up so far
# is then gone for good.
#
# Afterwards everyone signs in with the password, and the email code where
# it is chosen. Sessions still holding the plugin in their cache simply find
# no plugin file any more.
#
set -eu

fail() { printf 'error: %s\n' "$1" >&2; exit 1; }

PURGE=0
INTERFACE=""
for arg in "$@"; do
	case "$arg" in
		--purge) PURGE=1 ;;
		-*) fail "unknown option $arg (known: --purge)" ;;
		*)
			[ -z "$INTERFACE" ] || fail "two paths given ($INTERFACE and $arg), expected one interface path"
			INTERFACE="$arg"
			;;
	esac
done
INTERFACE="${INTERFACE:-/usr/local/ispconfig/interface}"
HERE="$(cd "$(dirname "$0")" && pwd)"
WEB="$INTERFACE/web"
KEY_DIR="$(dirname "$INTERFACE")/totpauth"

[ -f "$INTERFACE/lib/config.inc.php" ] || fail "$INTERFACE/lib/config.inc.php not found - pass the interface path"

rm -rf "$WEB/totpauth"
rm -f "$WEB/js/js.d/totpauth.js" "$WEB/js/js.d/totpauth-qr.js"
printf 'Module and scripts removed.\n'

if [ "$PURGE" = "1" ]; then
	if [ -n "${TOTPAUTH_SETUP_CMD:-}" ]; then
		"$TOTPAUTH_SETUP_CMD" "$INTERFACE" drop
	else
		php "$HERE/install/setup.php" "$INTERFACE" drop
	fi
	rm -rf "$KEY_DIR"
	printf 'Table and key removed.\n'
else
	printf 'Table totpauth_user and %s kept; run with --purge to remove them.\n' "$KEY_DIR"
fi
