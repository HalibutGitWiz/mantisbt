<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * MantisBT Core
 *
 * Initialises the MantisBT core, connects to the database, starts plugins and
 * performs other global operations that either help initialise MantisBT or
 * are required to be executed on every page load.
 *
 * @package MantisBT
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses authentication_api.php
 * @uses collapse_api.php
 * @uses compress_api.php
 * @uses config_api.php
 * @uses config_defaults_inc.php
 * @uses config_inc.php
 * @uses constant_inc.php
 * @uses crypto_api.php
 * @uses custom_constants_inc.php
 * @uses custom_functions_inc.php
 * @uses database_api.php
 * @uses event_api.php
 * @uses http_api.php
 * @uses lang_api.php
 * @uses mantis_offline.php
 * @uses plugin_api.php
 * @uses php_api.php
 * @uses user_pref_api.php
 * @uses wiki_api.php
 *
 * @noinspection PhpIncludeInspection
 */

/**
 * Before doing anything... check if MantisBT is down for maintenance
 *
 *   To make MantisBT 'offline' simply create a file called
 *   'mantis_offline.php' in the MantisBT root directory.
 *   Users are redirected to that file if it exists.
 *   If you have to test MantisBT while it's offline, add the
 *   parameter 'mbadmin=1' to the URL.
 */
if( file_exists( 'mantis_offline.php' ) && !isset( $_GET['mbadmin'] ) ) {
	include( 'mantis_offline.php' );
	exit;
}

$g_request_time = microtime( true );

# Load supplied constants
require_once( __DIR__ . '/core/constant_inc.php' );

# Enforce our minimum PHP requirements
if( version_compare( PHP_VERSION, PHP_MIN_VERSION, '<' ) ) {
	echo '<strong>FATAL ERROR: Your version of PHP is too old. '
		. 'MantisBT requires ' . PHP_MIN_VERSION . ' or newer</strong><br />'
		. 'Your are running PHP version <em>' . PHP_VERSION . '</em>';
	die();
}

ensure_php_extension_loaded( 'mbstring', 'for Unicode (UTF-8) support' );

# Ensure that encoding is always UTF-8 independent from any PHP default or ini setting
mb_internal_encoding( 'UTF-8' );

if( php_sapi_name() != 'cli' ) {
	ob_start();
}

# Load Composer autoloader
require_once( __DIR__ . '/vendor/autoload.php' );

# Include default configuration settings
require_once( __DIR__ . '/config_defaults_inc.php' );

# Load user-defined constants (if required)
global $g_config_path;
if( file_exists( $g_config_path . 'custom_constants_inc.php' ) ) {
	require_once( $g_config_path . 'custom_constants_inc.php' );
}

# config_inc may not be present if this is a new install
$t_config_inc_found = file_exists( $g_config_path . 'config_inc.php' );
if( $t_config_inc_found ) {
	require_once( $g_config_path . 'config_inc.php' );
}

# Set global path variables
# NOTE: We store whether $g_path was defaulted, so we can later warn the admin
# about the exposure to host header injection attacks if they didn't set it.
global $g_defaulted_path;
$g_defaulted_path = set_default_path();

# Ensure PHP LDAP extension is available when Login Method is LDAP
global $g_login_method;
if ( $g_login_method == LDAP ) {
	ensure_php_extension_loaded( 'ldap', 'when using LDAP as Login Method' );
}

# Register the autoload function to make it effective immediately
spl_autoload_register( 'autoload_mantis' );

# Include PHP compatibility file
require_api( 'php_api.php' );

# Ensure that output is blank so far (output at this stage generally denotes
# that an error has occurred)
if( ( $t_output = ob_get_contents() ) != '' ) {
	echo 'Possible Whitespace/Error in Configuration File - Aborting. Output so far follows:<br />';
	var_dump( $t_output );
	die;
}
unset( $t_output );

# Start HTML compression handler (if enabled)
require_api( 'compress_api.php' );
compress_start_handler();

# If no configuration file exists, redirect the user to the admin page so
# they can complete installation and configuration of MantisBT
if( false === $t_config_inc_found ) {
	if( php_sapi_name() == 'cli' ) {
		echo 'Error: ' . $g_config_path . "config_inc.php file not found; ensure MantisBT is properly setup.\n";
		exit( 1 );
	}

	# Do not load Core for dynamic javascript files when MantisBT is not installed
	if( isset( $_SERVER['SCRIPT_NAME'] ) && ( 0 < strpos( $_SERVER['SCRIPT_NAME'], 'javascript' ) ) ) {
		http_response_code( HTTP_STATUS_NO_CONTENT );
		exit;
	}

	if( !( isset( $_SERVER['SCRIPT_NAME'] ) && ( 0 < strpos( $_SERVER['SCRIPT_NAME'], 'admin' ) ) ) ) {
		header( 'Content-Type: text/html' );
		# Temporary redirect (307) instead of Found (302) default
		header( 'Location: admin/install.php', true, 307 );
		# Make sure it's not cached
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		exit;
	}
}

# Initialise cryptographic keys
require_api( 'crypto_api.php' );
crypto_init();

# Connect to the database
require_api( 'database_api.php' );
require_api( 'config_api.php' );

# Set the default timezone
# To reduce overhead, we assume that the timezone configuration is valid,
# i.e. it exists in timezone_identifiers_list(). If not, a PHP NOTICE will
# be raised and we fall back to the system's default timezone.
# Use admin checks to validate configuration.
$t_tz = config_get_global( 'default_timezone' );
if( empty( $t_tz ) || !date_default_timezone_set( $t_tz ) ) {
	$t_tz = date_default_timezone_get();
}
config_set_global( 'default_timezone', $t_tz );

if( !defined( 'MANTIS_MAINTENANCE_MODE' ) ) {
	global $g_use_persistent_connections, $g_hostname, $g_db_username, $g_db_password, $g_database_name;
	if( OFF == $g_use_persistent_connections ) {
		db_connect( config_get_global( 'dsn', false ), $g_hostname, $g_db_username, $g_db_password, $g_database_name );
	} else {
		db_connect( config_get_global( 'dsn', false ), $g_hostname, $g_db_username, $g_db_password, $g_database_name, true );
	}
}

# Register global shutdown function
shutdown_functions_register();

# Push default language to speed calls to lang_get
if( !defined( 'LANG_LOAD_DISABLED' ) ) {
	require_api( 'lang_api.php' );
	lang_push( lang_get_default() );
}

# Initialise plugins
require_api( 'plugin_api.php' );  # necessary for some upgrade steps
if( !defined( 'PLUGINS_DISABLED' ) && !defined( 'MANTIS_MAINTENANCE_MODE' ) ) {
	plugin_init_installed();
}

# Initialise Wiki integration
if( config_get_global( 'wiki_enable' ) == ON ) {
	require_api( 'wiki_api.php' );
	wiki_init();
}

if( !isset( $g_login_anonymous ) ) {
	$g_login_anonymous = true;
}

if( !defined( 'MANTIS_MAINTENANCE_MODE' ) ) {
	require_api( 'authentication_api.php' );

	# Override the default timezone according to user's preferences
	if( auth_is_user_authenticated() ) {
		require_api( 'user_pref_api.php' );
		$t_tz = user_pref_get_pref( auth_get_current_user_id(), 'timezone' );
		@date_default_timezone_set( $t_tz );
	}
}
unset( $t_tz );

# Cache current user's collapse API data
if( !defined( 'MANTIS_MAINTENANCE_MODE' ) ) {
	require_api( 'collapse_api.php' );
	collapse_cache_token();
}

# Load custom functions
require_api( 'custom_function_api.php' );

if( file_exists( $g_config_path . 'custom_functions_inc.php' ) ) {
	require_once( $g_config_path . 'custom_functions_inc.php' );
}

# Set HTTP response headers
require_api( 'http_api.php' );
event_signal( 'EVENT_CORE_HEADERS' );
http_all_headers();

# Signal plugins that the core system is loaded
if( !defined( 'PLUGINS_DISABLED' ) && !defined( 'MANTIS_MAINTENANCE_MODE' ) ) {
	require_api( 'event_api.php' );
	event_signal( 'EVENT_CORE_READY' );
}

/**
 * Define an API inclusion function to replace require_once
 *
 * @param string $p_api_name An API file name.
 * @return void
 */
function require_api( $p_api_name ) {
	static $s_api_included;
	global $g_core_path;
	if( !isset( $s_api_included[$p_api_name] ) ) {
		require_once( $g_core_path . $p_api_name );
		$t_new_globals = array_diff_key( get_defined_vars(), $GLOBALS, array( 't_new_globals' => 0 ) );
		foreach ( $t_new_globals as $t_global_name => $t_global_value ) {
			$GLOBALS[$t_global_name] = $t_global_value;
		}
		$s_api_included[$p_api_name] = 1;
	}
}

/**
 * Define an API inclusion function to replace require_once
 *
 * @param string $p_library_name A library file name.
 * @return void
 */
function require_lib( $p_library_name ) {
	static $s_libraries_included;

	if( !isset( $s_libraries_included[$p_library_name] ) ) {
		global $g_library_path;
		$t_library_file_path = $g_library_path . $p_library_name;

		if( file_exists( $t_library_file_path ) ) {
			require_once( $t_library_file_path );
		} else {
			echo 'External library \'' . $t_library_file_path . '\' not found.';
			exit;
		}

		$t_new_globals = array_diff_key( get_defined_vars(), $GLOBALS, array( 't_new_globals' => 0 ) );
		foreach ( $t_new_globals as $t_global_name => $t_global_value ) {
			$GLOBALS[$t_global_name] = $t_global_value;
		}

		$s_libraries_included[$p_library_name] = 1;
	}
}

/**
 * Checks to see if script was queried through the HTTPS protocol
 * @return boolean True if protocol is HTTPS
 */
function http_is_protocol_https() {
	if( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
		return strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) == 'https';
	}

	if( !empty( $_SERVER['HTTPS'] ) && ( strtolower( $_SERVER['HTTPS'] ) != 'off' ) ) {
		return true;
	}

	return false;
}

/**
 * Set Global Path variables.
 *
 * @see $g_path
 * @see $g_short_path
 *
 * @return bool True if default $g_path was assigned, false if it was already set.
 */
function set_default_path() {
	global $g_path, $g_short_path, $g_config_path;

	# $g_path is set in config_inc.php
	if( $g_path ) {
		# Derive $g_short_path from $g_path if not set
		if( !$g_short_path ) {
			$g_short_path = parse_url( $g_path, PHP_URL_PATH );
		}
		return false;
	}

	$t_protocol = 'http';
	$t_host = 'localhost';
	if( isset( $_SERVER['SCRIPT_NAME'] ) ) {
		$t_protocol = http_is_protocol_https() ? 'https' : 'http';

		# $_SERVER['SERVER_PORT'] is not defined in case of php-cgi.exe
		if( isset( $_SERVER['SERVER_PORT'] ) ) {
			$t_port = ':' . $_SERVER['SERVER_PORT'];
			if( ( ':80' == $t_port && 'http' == $t_protocol )
				|| ( ':443' == $t_port && 'https' == $t_protocol )) {
				$t_port = '';
			}
		} else {
			$t_port = '';
		}

		if( isset( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) { # Support ProxyPass
			$t_hosts = explode( ',', $_SERVER['HTTP_X_FORWARDED_HOST'] );
			$t_host = $t_hosts[0];
		} else if( isset( $_SERVER['HTTP_HOST'] ) ) {
			$t_host = $_SERVER['HTTP_HOST'];
		} else if( isset( $_SERVER['SERVER_NAME'] ) ) {
			$t_host = $_SERVER['SERVER_NAME'] . $t_port;
		} else if( isset( $_SERVER['SERVER_ADDR'] ) ) {
			$t_host = $_SERVER['SERVER_ADDR'] . $t_port;
		}

		# Prevent XSS if the path is displayed later on. This is the equivalent of
		# FILTER_SANITIZE_STRING, which was deprecated in PHP 8.1:
		# strip tags and null bytes, then encode quotes into HTML entities
		$t_path = preg_replace( '/\x00|<[^>]*>?/', '', $_SERVER['SCRIPT_NAME'] );
		$t_path = str_replace( ["'", '"'], ['&#39;', '&#34;'], $t_path );

		$t_path = dirname( $t_path );
		switch( basename( $t_path ) ) {
			case 'admin':
				$t_path = dirname( $t_path );
				break;
			case 'check': # admin checks dir
			case 'soap':
			case 'rest':
				$t_path = dirname( $t_path, 2 );
				break;
			case 'swagger':
				$t_path = dirname( $t_path, 3 );
				break;
		}
		$t_path = rtrim( $t_path, '/\\' ) . '/';

		if( strpos( $t_path, '&#' ) ) {
			echo 'Can not safely determine $g_path. Please set $g_path manually in ' . $g_config_path . 'config_inc.php';
			die;
		}
	} else {
		$t_path = 'mantisbt/';
	}

	$g_path	= $t_protocol . '://' . $t_host . $t_path;
	$g_short_path = $t_path;

	return true;
}

/**
 * Define an autoload function to automatically load classes when referenced
 *
 * @param string $p_class Class name being autoloaded.
 * @return void
 */
function autoload_mantis( $p_class ) {
	global $g_core_path;

	# Remove namespace from class name
	$t_end_of_namespace = strrpos( $p_class, '\\' );
	if( $t_end_of_namespace !== false ) {
		$p_class = substr( $p_class, $t_end_of_namespace + 1 );
	}

	# Commands
	if( substr( $p_class, -7 ) === 'Command' ) {
		$t_require_path = $g_core_path . 'commands/' . $p_class . '.php';
		if( file_exists( $t_require_path ) ) {
			require_once( $t_require_path );
			return;
		}
	}

	# Exceptions
	if( substr( $p_class, -9 ) === 'Exception' ) {
		$t_require_path = $g_core_path . 'exceptions/' . $p_class . '.php';
		if( file_exists( $t_require_path ) ) {
			require_once( $t_require_path );
			return;
		}
	}

	global $g_class_path;
	global $g_library_path;

	$t_require_path = $g_class_path . $p_class . '.class.php';

	if( file_exists( $t_require_path ) ) {
		require_once( $t_require_path );
		return;
	}

	$t_require_path = $g_library_path . 'rssbuilder/class.' . $p_class . '.inc.php';

	if( file_exists( $t_require_path ) ) {
		require_once( $t_require_path );
		return;
	}
}

/**
 * Ensures the given extension is loaded, dies with an error message if not.
 *
 * @param string $p_extension      Extension name
 * @param string $p_reason_message Justification for requiring the extension
 * @return bool
 */
function ensure_php_extension_loaded( $p_extension, $p_reason_message ) {
	if( extension_loaded( $p_extension ) ) {
		return true;
	}

	/** @noinspection HtmlUnknownTarget */
	printf( '<strong>FATAL ERROR: PHP <i>%1$s</i> extension is not enabled.</strong><br>'
		. 'MantisBT requires this extension %2$s.<br>'
		. '<a href="%3$s">%3$s</a>',
		$p_extension,
		$p_reason_message,
		"https://www.php.net/manual/en/$p_extension.installation.php"
	);
	die;
}
