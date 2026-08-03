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

wp_cli="$(command -v "$wp_cli")"
php_bin="$(command -v "$php_bin")"
wordpress="$(cd "$wordpress" && pwd -P)"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
marker="$wordpress/.ran-updater-disposable-test-site"

if [[ -L "$marker" || ! -f "$marker" ]] ||
	[[ "$(<"$marker")" != 'RAN updater disposable test site' ]]; then
	echo 'The coordination proof requires the disposable-site marker.' >&2
	exit 2
fi
if [[ "$("$php_bin" "$wp_cli" core version --path="$wordpress")" != "$expected_version" ]]; then
	echo 'The coordination proof found an unexpected WordPress version.' >&2
	exit 2
fi

tmp_root="${TMPDIR:-/tmp}"
work="$(mktemp -d "$tmp_root/ran-updater-coordination.XXXXXX")"
cleanup() {
	local status="$?"
	if (( status != 0 )); then
		for output in "$work"/*.out; do
			if [[ -f "$output" ]]; then
				echo "Coordination worker output: $output" >&2
				cat "$output" >&2
			fi
		done
	fi
	if [[ -d "$work" && "$work" == "$tmp_root"/ran-updater-coordination.* ]]; then
		rm -rf -- "$work"
	fi
	return "$status"
}
trap cleanup EXIT

main_url='http://localhost'
child_url='http://localhost/child/'
worker="$root/tests/Coordination/mysql-worker.php"
export RAN_UPDATER_RUNTIME="$root/runtime.php"

"$php_bin" "$wp_cli" core multisite-convert \
	--path="$wordpress" \
	--url="$main_url" \
	--title='RAN Updater Coordination CI'
"$php_bin" "$wp_cli" site create \
	--path="$wordpress" \
	--url="$main_url" \
	--slug=child \
	--title='Coordination child'

run_worker() {
	local action="$1"
	local url="$2"
	local target="$3"
	local operation="$4"
	local ready="$5"
	local go="$6"
	local output="$7"
	RAN_COORD_ACTION="$action" \
	RAN_COORD_TARGET="$target" \
	RAN_COORD_OPERATION="$operation" \
	RAN_COORD_READY="$ready" \
	RAN_COORD_GO="$go" \
		"$php_bin" "$wp_cli" eval-file "$worker" --path="$wordpress" --url="$url" >"$output" 2>&1 &
	ran_coord_pid="$!"
}

wait_for_files() {
	local first="$1"
	local second="$2"
	local deadline=$((SECONDS + 20))
	until [[ -f "$first" && -f "$second" ]]; do
		if (( SECONDS >= deadline )); then
			echo 'Timed out waiting for coordination workers.' >&2
			exit 1
		fi
		sleep 0.01
	done
}

assert_result_count() {
	local expected="$1"
	local fragment="$2"
	shift 2
	local actual
	actual="$(grep -h '^RAN_COORD_RESULT=' "$@" | grep -c "$fragment" || true)"
	if [[ "$actual" != "$expected" ]]; then
		cat "$@" >&2
		echo "Expected $expected result(s) containing $fragment; found $actual." >&2
		exit 1
	fi
}

# MySQL counts changed rows, so two renewals inside one database second must
# still write distinct exact-row values and retain ownership.
RAN_COORD_ACTION=rapid-renew \
RAN_COORD_TARGET='real-mysql-rapid-renew-target' \
RAN_COORD_OPERATION=native-install \
	"$php_bin" "$wp_cli" eval-file "$worker" --path="$wordpress" --url="$main_url" >"$work/rapid-renew.out"
assert_result_count 1 '"kind":"rapid-renew"' "$work/rapid-renew.out"
assert_result_count 1 '"generation":1' "$work/rapid-renew.out"
assert_result_count 1 '"first_advanced":true' "$work/rapid-renew.out"
assert_result_count 1 '"second_advanced":true' "$work/rapid-renew.out"
assert_result_count 1 '"released":true' "$work/rapid-renew.out"

# Two independent processes, entering from different blogs, race the absent
# stable target row. MySQL must admit exactly one INSERT/CAS winner.
cold_target='real-mysql-cold-target'
cold_go="$work/cold-go"
run_worker acquire "$main_url" "$cold_target" managed-preflight "$work/cold-ready-a" "$cold_go" "$work/cold-a.out"
cold_pid_a="$ran_coord_pid"
run_worker acquire "$child_url" "$cold_target" native-discovery "$work/cold-ready-b" "$cold_go" "$work/cold-b.out"
cold_pid_b="$ran_coord_pid"
wait_for_files "$work/cold-ready-a" "$work/cold-ready-b"
touch "$cold_go"
wait "$cold_pid_a" "$cold_pid_b"
assert_result_count 1 '"kind":"claim"' "$work/cold-a.out" "$work/cold-b.out"
assert_result_count 1 '"code":"github_updater_operation_busy"' "$work/cold-a.out" "$work/cold-b.out"
assert_result_count 1 '"generation":1' "$work/cold-a.out" "$work/cold-b.out"

# Keep a real first-generation owner alive, expire its row in MySQL, and race
# two new processes for takeover. Only one may become generation two.
stale_target='real-mysql-stale-target'
stale_ready="$work/stale-ready"
stale_resume="$work/stale-resume"
run_worker hold-stale "$main_url" "$stale_target" native-install "$stale_ready" "$stale_resume" "$work/stale.out"
stale_pid="$ran_coord_pid"
wait_for_files "$stale_ready" "$stale_ready"
RAN_COORD_ACTION=expire \
RAN_COORD_TARGET="$stale_target" \
RAN_COORD_OPERATION=native-install \
	"$php_bin" "$wp_cli" eval-file "$worker" --path="$wordpress" --url="$child_url" >"$work/expire.out"

takeover_go="$work/takeover-go"
run_worker acquire "$main_url" "$stale_target" managed-preflight "$work/takeover-ready-a" "$takeover_go" "$work/takeover-a.out"
takeover_pid_a="$ran_coord_pid"
run_worker acquire "$child_url" "$stale_target" native-discovery "$work/takeover-ready-b" "$takeover_go" "$work/takeover-b.out"
takeover_pid_b="$ran_coord_pid"
wait_for_files "$work/takeover-ready-a" "$work/takeover-ready-b"
touch "$takeover_go"
wait "$takeover_pid_a" "$takeover_pid_b"
assert_result_count 1 '"kind":"claim"' "$work/takeover-a.out" "$work/takeover-b.out"
assert_result_count 1 '"code":"github_updater_operation_busy"' "$work/takeover-a.out" "$work/takeover-b.out"
assert_result_count 1 '"generation":2' "$work/takeover-a.out" "$work/takeover-b.out"

# The original process still holds its exact first-generation claim. It must
# neither publish its result state nor release the generation-two row.
touch "$stale_resume"
wait "$stale_pid"
assert_result_count 1 '"publish_code":"github_updater_operation_fence_lost"' "$work/stale.out"
assert_result_count 1 '"released":false' "$work/stale.out"

RAN_COORD_ACTION=inspect \
RAN_COORD_TARGET="$cold_target" \
RAN_COORD_OPERATION=inspection \
	"$php_bin" "$wp_cli" eval-file "$worker" --path="$wordpress" --url="$child_url" >"$work/inspect.out"
assert_result_count 1 '"authority_count":1' "$work/inspect.out"
assert_result_count 1 '"child_count":0' "$work/inspect.out"

RAN_COORD_ACTION=inspect \
RAN_COORD_TARGET="$stale_target" \
RAN_COORD_OPERATION=inspection \
	"$php_bin" "$wp_cli" eval-file "$worker" --path="$wordpress" --url="$child_url" >"$work/stale-inspect.out"
assert_result_count 1 '"stale_marker":false' "$work/stale-inspect.out"

echo 'Real-MySQL two-process release-operation coordination passed.'
