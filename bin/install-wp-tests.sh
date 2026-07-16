#!/usr/bin/env bash
# install-wp-tests.sh
#
# Installs the WordPress test suite and a test database.
# Downloaded from: https://raw.githubusercontent.com/wp-cli/scaffold-command/main/templates/install-wp-tests.sh
#
# Usage: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]
#
# Example: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

set -euo pipefail

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}
SKIP_DB_CREATE=${6:-false}

TMPDIR=${TMPDIR:-/tmp}
TMPDIR=${TMPDIR%/}
WP_TESTS_DIR=${WP_TESTS_DIR:-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-$TMPDIR/wordpress}

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL "$1" -o "$2"
	elif command -v wget >/dev/null 2>&1; then
		wget -q -O "$2" "$1"
	else
		echo "curl or wget is required to download WordPress test files" >&2
		exit 1
	fi
}

wordpress_develop_archive_url() {
	local ref_type
	local ref_name

	if [[ $WP_TESTS_TAG == trunk ]]; then
		ref_type="heads"
		ref_name="trunk"
	elif [[ $WP_TESTS_TAG == branches/* ]]; then
		ref_type="heads"
		ref_name="${WP_TESTS_TAG#branches/}"
	elif [[ $WP_TESTS_TAG == tags/* ]]; then
		ref_type="tags"
		ref_name="${WP_TESTS_TAG#tags/}"
	else
		echo "Unsupported WordPress test tag: $WP_TESTS_TAG" >&2
		exit 1
	fi

	echo "https://github.com/WordPress/wordpress-develop/archive/refs/${ref_type}/${ref_name}.tar.gz"
}

download_wordpress_develop() {
	local target_dir=$1
	local archive=$TMPDIR/wordpress-develop-${WP_TESTS_TAG//\//-}.tar.gz

	if [ -d "$target_dir/tests/phpunit" ] && [ -f "$target_dir/wp-tests-config-sample.php" ]; then
		return
	fi

	rm -rf "$target_dir"
	mkdir -p "$target_dir"
	download "$(wordpress_develop_archive_url)" "$archive"
	tar --strip-components=1 -zxmf "$archive" -C "$target_dir"
}

directory_has_files() {
	[ -d "$1" ] && [ -n "$(find "$1" -mindepth 1 -maxdepth 1 -print -quit)" ]
}

sed_escape() {
	printf '%s' "$1" | sed -e 's/[\/&]/\\&/g'
}

configure_test_suite() {
	sed_in_place() {
		if [[ $(uname -s) == 'Darwin' ]]; then
			sed -i .bak "$@"
			rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"
		else
			sed -i "$@"
		fi
	}

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		echo "Missing WordPress test config: $WP_TESTS_DIR/wp-tests-config.php" >&2
		exit 1
	fi

	if [ ! -d "$WP_CORE_DIR" ]; then
		echo "Missing WordPress core directory: $WP_CORE_DIR" >&2
		exit 1
	fi

	local wp_core_dir_for_config wp_core_dir_escaped db_name_escaped db_user_escaped db_pass_escaped db_host_escaped

	if [[ "$WP_CORE_DIR" == */ ]]; then
		wp_core_dir_for_config="$WP_CORE_DIR"
	else
		wp_core_dir_for_config="$WP_CORE_DIR/"
	fi

	wp_core_dir_escaped=$(sed_escape "$wp_core_dir_for_config")
	db_name_escaped=$(sed_escape "$DB_NAME")
	db_user_escaped=$(sed_escape "$DB_USER")
	db_pass_escaped=$(sed_escape "$DB_PASS")
	db_host_escaped=$(sed_escape "$DB_HOST")

	# Update both fresh placeholder configs and already-configured reusable test suites.
	sed_in_place "s:dirname( __FILE__ ) . '/src/':'$wp_core_dir_escaped':" "$WP_TESTS_DIR/wp-tests-config.php"
	sed_in_place "s/youremptytestdbnamehere/$db_name_escaped/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed_in_place "s/yourusernamehere/$db_user_escaped/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed_in_place "s/yourpasswordhere/$db_pass_escaped/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed_in_place "s|localhost|$db_host_escaped|" "$WP_TESTS_DIR/wp-tests-config.php"
	sed_in_place "s/define( 'DB_NAME', '.*' );/define( 'DB_NAME', '$db_name_escaped' );/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed_in_place "s/define( 'DB_USER', '.*' );/define( 'DB_USER', '$db_user_escaped' );/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed_in_place "s/define( 'DB_PASSWORD', '.*' );/define( 'DB_PASSWORD', '$db_pass_escaped' );/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed_in_place "s/define( 'DB_HOST', '.*' );/define( 'DB_HOST', '$db_host_escaped' );/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed_in_place "s|define( 'ABSPATH', .* );|define( 'ABSPATH', '$wp_core_dir_escaped' );|" "$WP_TESTS_DIR/wp-tests-config.php"
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		# version X.X.0 means the first release of that major version's branch, so strip the ".0" and download /branches/X.X
		WP_TESTS_TAG="branches/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	LATEST_JSON="$TMPDIR/wp-latest.json"
	download https://api.wordpress.org/core/version-check/1.7/ "$LATEST_JSON"
	LATEST_VERSION=$(grep -o '"version":"[^"]*"' "$LATEST_JSON" | sed -n '1{s/"version":"//;s/"//;p;}')
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be found"
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

install_wp() {
	if [ -f "$WP_CORE_DIR/wp-includes/version.php" ]; then
		return;
	fi

	if directory_has_files "$WP_CORE_DIR"; then
		echo "WP_CORE_DIR exists but does not look like a complete WordPress install: $WP_CORE_DIR" >&2
		exit 1
	fi

	rm -rf "$WP_CORE_DIR"
	mkdir -p "$WP_CORE_DIR"

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		mkdir -p "$TMPDIR/wordpress-trunk"
		if [ ! -d "$TMPDIR/wordpress-trunk/tests/phpunit" ]; then
			if command -v svn >/dev/null 2>&1; then
				svn export --quiet https://develop.svn.wordpress.org/trunk/ "$TMPDIR/wordpress-trunk"
			else
				download_wordpress_develop "$TMPDIR/wordpress-trunk"
			fi
		fi
		cp -R "$TMPDIR/wordpress-trunk/build/." "$WP_CORE_DIR/"
	else
		local ARCHIVE_NAME
		if [[ $WP_VERSION == 'latest' ]]; then
			ARCHIVE_NAME='latest'
		elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
			# https serves multiple offers and may not have the version we want
			ARCHIVE_NAME="wordpress-$WP_VERSION"
		fi
		download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "$TMPDIR/wordpress.tar.gz"
		tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
	fi

	download https://raw.github.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
	# set up testing suite if it doesn't yet exist
	if [ -f "$WP_TESTS_DIR/includes/functions.php" ] && [ -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		# Use existing tests directory if it exists, but refresh DB/core config
		# so repeated local/CI installs do not keep stale credentials.
		configure_test_suite
		return;
	fi

	if directory_has_files "$WP_TESTS_DIR"; then
		echo "WP_TESTS_DIR exists but does not look like a complete WordPress test suite: $WP_TESTS_DIR" >&2
		exit 1
	fi

	rm -rf "$WP_TESTS_DIR"
	mkdir -p "$WP_TESTS_DIR"

	# set up testing suite
	if command -v svn >/dev/null 2>&1; then
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
		download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
	else
		local develop_dir=$TMPDIR/wordpress-develop-${WP_TESTS_TAG//\//-}
		download_wordpress_develop "$develop_dir"
		cp -R "$develop_dir/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
		cp -R "$develop_dir/tests/phpunit/data" "$WP_TESTS_DIR/data"
		cp "$develop_dir/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
	fi

	configure_test_suite
}

install_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi

	# parse DB_HOST for port or socket references
	local DB_HOSTNAME=$DB_HOST
	local DB_SOCK_OR_PORT=""
	local mysql_args=(--user="$DB_USER" --password="$DB_PASS")
	local mysqladmin_args=(--user="$DB_USER" --password="$DB_PASS")

	if [[ "$DB_HOST" == *:* ]]; then
		DB_HOSTNAME=${DB_HOST%%:*}
		DB_SOCK_OR_PORT=${DB_HOST#*:}
	fi

	if [ -n "$DB_SOCK_OR_PORT" ]; then
		if [[ "$DB_SOCK_OR_PORT" =~ ^[0-9]+$ ]]; then
			mysql_args+=(--host="$DB_HOSTNAME" --port="$DB_SOCK_OR_PORT" --protocol=tcp)
			mysqladmin_args+=(--host="$DB_HOSTNAME" --port="$DB_SOCK_OR_PORT" --protocol=tcp)
		else
			mysql_args+=(--socket="$DB_SOCK_OR_PORT")
			mysqladmin_args+=(--socket="$DB_SOCK_OR_PORT")
		fi
	fi

	# create database
	if command -v mysql >/dev/null 2>&1 ; then
		mysql "${mysql_args[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`"
	elif command -v mysqladmin >/dev/null 2>&1 ; then
		mysqladmin create "$DB_NAME" "${mysqladmin_args[@]}"
	else
		echo "mysql or mysqladmin is required to create the WordPress test database" >&2
		exit 1
	fi
}

install_wp
install_test_suite
install_db
