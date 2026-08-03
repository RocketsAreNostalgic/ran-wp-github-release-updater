#!/usr/bin/env bash

set -euo pipefail

wordpress="${RAN_UPDATER_WORDPRESS_PATH:-}"
expected_version="${RAN_UPDATER_WORDPRESS_VERSION:-}"
wp_cli="${WP_CLI_BIN:-wp}"
php_bin="${PHP_BIN:-php}"

if [[ "${RAN_UPDATER_LIFECYCLE_TEST_DISPOSABLE:-}" != '1' ]]; then
	echo 'Set RAN_UPDATER_LIFECYCLE_TEST_DISPOSABLE=1 only for an isolated disposable WordPress installation.' >&2
	exit 2
fi
if [[ -z "$wordpress" || ! -f "$wordpress/wp-load.php" ]]; then
	echo 'Set RAN_UPDATER_WORDPRESS_PATH to a disposable WordPress installation.' >&2
	exit 2
fi
if [[ -z "$expected_version" ]]; then
	echo 'Set RAN_UPDATER_WORDPRESS_VERSION to the exact disposable WordPress version.' >&2
	exit 2
fi
if ! command -v "$wp_cli" >/dev/null 2>&1 || ! command -v "$php_bin" >/dev/null 2>&1; then
	echo 'WP-CLI and PHP are required for the updater lifecycle proof.' >&2
	exit 2
fi

wp_cli="$(command -v "$wp_cli")"
php_bin="$(command -v "$php_bin")"
wordpress="$(cd "$wordpress" && pwd -P)"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
marker="$wordpress/.ran-updater-disposable-test-site"

if [[ -L "$marker" || ! -f "$marker" ]] ||
	[[ "$(<"$marker")" != 'RAN updater disposable test site' ]]; then
	echo "The updater lifecycle proof requires $marker with the expected disposable-site marker." >&2
	exit 2
fi
if [[ "$($php_bin -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" != '8.2' ]]; then
	echo 'The updater lifecycle proof requires PHP 8.2.' >&2
	exit 2
fi
if [[ "$($php_bin "$wp_cli" core version --path="$wordpress")" != "$expected_version" ]]; then
	echo 'The updater lifecycle proof found an unexpected WordPress version.' >&2
	exit 2
fi

fixtures="$root/tests/WordPress/fixtures"
plugin_target="$wordpress/wp-content/plugins/ran-updater-lifecycle-registrar"
if [[ -e "$plugin_target" ]]; then
	echo 'The updater lifecycle fixture plugin target already exists.' >&2
	exit 2
fi
cp -R "$fixtures/lifecycle-registrar" "$plugin_target"

for stylesheet in \
	ran-updater-direct-active \
	ran-updater-inactive \
	ran-updater-registrar-active \
	ran-updater-registrar-inactive; do
	theme_target="$wordpress/wp-content/themes/$stylesheet"
	if [[ -e "$theme_target" ]]; then
		echo "The updater lifecycle fixture theme target already exists: $stylesheet" >&2
		exit 2
	fi
	cp -R "$fixtures/$stylesheet" "$theme_target"
done

export RAN_UPDATER_LIFECYCLE_BOOTSTRAP="$root/bootstrap.php"

"$php_bin" "$wp_cli" theme activate ran-updater-direct-active --path="$wordpress"
"$php_bin" "$wp_cli" plugin activate ran-updater-lifecycle-registrar --path="$wordpress"
"$php_bin" "$wp_cli" eval-file "$root/tests/WordPress/assert-direct-theme-lifecycle.php" --path="$wordpress"

"$php_bin" "$wp_cli" theme activate ran-updater-registrar-active --path="$wordpress"
"$php_bin" "$wp_cli" eval-file "$root/tests/WordPress/assert-early-theme-registrar.php" --path="$wordpress"
