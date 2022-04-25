<?php
/**
 * Functions for WordPress specific actions.
 */

namespace TEC\Tric;

use CallbackFilterIterator;
use FilesystemIterator;
use SplFileInfo;

/**
 * Generates a .htaccess file in the WP root if missing.
 */
function maybe_generate_htaccess() {
	$htaccess_path = root( '_wordpress/.htaccess' );
	$htaccess      = is_file( $htaccess_path ) && file_get_contents( $htaccess_path );

	if ( $htaccess ) {
		return;
	}

	$htaccess = <<< HTACCESS
# BEGIN WordPress

RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]

# END WordPress
HTACCESS;

	file_put_contents( $htaccess_path, $htaccess );
}

/**
 * Indicates whether the current dir has a local-config.php file.
 *
 * @param string $dir Path to search for local-config.
 *
 * @return bool
 */
function dir_has_local_config( $dir ) {
	return file_exists( "{$dir}/local-config.php" );
}

/**
 * Indicates whether the current dir has a wp-config.php file.
 *
 * @param string $dir Path to search for wp-config.
 *
 * @return bool
 */
function dir_has_wp_config( $dir ) {
	return file_exists( "{$dir}/wp-config.php" );
}

/**
 * Returns a list of the available plugins or themes.
 *
 * @return array<string,SplFileInfo> A map of each directory in the relevant plugins or themes directory to the
 *                                   corresponding file information.
 */
function wp_content_dir_list( $content_type = 'plugins' ) {
	$function = "\\TEC\\Tric\\tric_{$content_type}_dir";
	$path     = $function();

	if ( ! is_dir( $path ) ) {
		return [];
	}

	$dirs    = [];
	$options = FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS;

	$dir = new CallbackFilterIterator( new FilesystemIterator( $path, $options ),
		static function ( SplFileInfo $file ) {
			return $file->isDir();
		}
	);

	$allowed_subdirs = get_allowed_use_subdirectories();
	foreach ( iterator_to_array( $dir ) as $key => $value ) {
		$basename          = basename( $key );
		$dirs[ $basename ] = $value;
		foreach ( $allowed_subdirs as $subdir ) {
			$subdir_path = $value . '/' . $subdir;
			if ( file_exists( $subdir_path ) ) {
				$dirs[ $basename . '/' . $subdir ] = $subdir_path;
			}
		}
	}

	return $dirs;
}

/**
 * Returns the list of allowed subdirectories for tric use.
 *
 * @return array<string> Allowed subdirectories for use.
 */
function get_allowed_use_subdirectories() {
	return [ 'common' ];
}

/**
 * Ensures WordPress file are correctly unzipped and placed.
 *
 * If a different version of WordPress is already installed, then
 * it will be removed.
 *
 * @param string|null $version    The WordPress version to set up
 *                                the files for.
 *
 * @return bool Always `true` to indicate files are in place.
 */
function ensure_wordpress_files( $version = null ) {
	// By default, download the latest WordPress version.
	$source_url = 'https://wordpress.org/latest.zip';

	if ( $version !== null ) {
		// The provided WordPress version will override any env defined version.
		$source_url = "https://wordpress.org/wordpress-$version.zip";
	} else {
		// If set, then use the WordPress version defined by the env.
		$env_wp_version = getenv( 'TRIC_WP_VERSION' );
		$version = $env_wp_version;
		if ( ! empty( $env_wp_version ) ) {
			$source_url = "https://wordpress.org/wordpress-$env_wp_version.zip";
		}
	}

	$version = $version ? $version : 'latest';

	if ( $version === 'latest' ) {
		$version = get_wordpress_latest_version();
	}

	$wp_root_dir = getenv( 'TRIC_WP_DIR' );
	$version_file = $wp_root_dir . '/wp-includes/version.php';

	// Check only if the specified version is not latest.
	if ( is_file( $version_file ) ) {
		include_once $version_file;
		// `$wp_version` is globally defined in the `wp-includes/version.php` file.
		if ( isset( $wp_version ) && version_compare( $wp_version, $version ) === 0 ) {
			return true;
		}

		// Remove the previous version of WordPress.
		rrmdir( $wp_root_dir );
	}

	// Ensure the destination directory exists.
	if ( ! is_dir( $wp_root_dir ) && ! mkdir( $wp_root_dir, 0755, false ) && ! is_dir( $wp_root_dir ) ) {
		echo magenta( "Failed to create WordPress root directory {$wp_root_dir}." );
		exit( 1 );
	}

	// Download WordPress.
	$zip_file = cache( '/wordpress/wordpress.zip' );
	if ( ! is_file( $zip_file ) ) {
		$zip_file = download_file( $source_url, $zip_file );

		if ( $zip_file === false ) {
			echo magenta( "Failed to download WordPress file from $source_url." );
			exit( 1 );
		}
	}

	// Unzip WordPress.
	if (
		! is_file( $wp_root_dir . '/wp-load.php' )
		&& ! unzip_file( $zip_file, $wp_root_dir )
	) {
		echo magenta( "Failed to extract WordPress file $zip_file to $wp_root_dir." );
		exit( 1 );
	}

	return true;
}

/**
 * Ensures WordPress is correctly configured.
 *
 * @return bool Always `true` to indicate WordPress
 *              is set up correctly.
 */
function ensure_wordpress_configured() {
	$wp_root_dir    = getenv( 'TRIC_WP_DIR' );
	$wp_config_file = $wp_root_dir . '/wp-config.php';

	if ( is_file( $wp_config_file ) ) {
		// If the wp-config.php file already exists, assume WordPress is already configured correctly.
		return true;
	}

	$wp_config_sample_file = $wp_root_dir . '/wp-config-sample.php';

	if ( ! is_file( $wp_config_sample_file ) ) {
		echo magenta( "Config sample file $wp_config_sample_file not found." );
		exit( 1 );
	}

	$wp_config_contents = file_get_contents( $wp_config_sample_file );

	if ( empty( $wp_config_contents ) ) {
		echo magenta( "Config sample file $wp_config_sample_file could not be read or is empty." );
		exit( 1 );
	}

	// Set up the db credentials, rely on the placeholders that come with a default WordPress installation.
	$wp_config_contents = str_replace( [
		'<?php',
		"'database_name_here'",
		"'username_here'",
		"'password_here'",
		"'localhost'"
	], [
		"<?php\n\nfunction tric_env( \$key, \$default ){\n\treturn getenv( \$key ) ?: \$default;\n}\n",
		"'" . get_db_name() . "'",
		"'" . get_db_user() . "'",
		"'" . get_db_password() . "'",
		"tric_env( 'DB_HOST', 'db' )"
	], $wp_config_contents );

	// As is common practice, use the "That's all, stop editing! Happy publishing" line as a marker.
	$marker = "/* That's all, stop editing! Happy publishing. */";

	if ( strpos( $wp_config_contents, $marker ) === false ) {
		echo magenta( "Config sample file $wp_config_sample_file does not contain marker line." );
		exit( 1 );
	}

	// Generate salts, there are 8 of them, each should be different.
	for ( $i = 0; $i < 8; $i ++ ) {
		// Cryptographically weak, but fine in testing environment.
		$salt = hash( 'sha256', (string) microtime( true ) . mt_rand( 1, PHP_INT_MAX ) );
		// Use `preg_replace` to limit the replacements.
		$wp_config_contents = preg_replace( '/put your unique phrase here/', $salt, $wp_config_contents, 1 );
	}

	$config_extras      = <<< CONFIG_EXTRAS
\$scheme = empty( \$_SERVER['HTTPS'] ) ? 'http' : 'https';
\$url    = isset( \$_SERVER['HTTP_HOST'] ) ? \$_SERVER['HTTP_HOST'] : 'wordpress.test';
define( 'WP_HOME', \$scheme . '://' . \$url );
define( 'WP_SITEURL', \$scheme . '://' . \$url );
define( 'WP_REDIS_HOST', 'redis' );
define( 'WP_REDIS_PORT', 6379 );
define( 'TRIBE_NO_FREEMIUS', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'WP_DEBUG_LOG', true );
CONFIG_EXTRAS;
	$wp_config_contents = str_replace( $marker, $marker . PHP_EOL . $config_extras, $wp_config_contents );

	if ( ! file_put_contents( $wp_config_file, $wp_config_contents, LOCK_EX ) ) {
		echo magenta( "Failed to write $wp_config_file file." );
		exit( 1 );
	}

	return true;
}

/**
 * Ensures WordPress is correctly installed.
 *
 * @return bool Always `true` to indicate WordPress is
 *              correctly installed.
 */
function ensure_wordpress_installed() {
	// Bring up the database.
	ensure_db_service_running();

	// Run a query to check for the installation.
	$db = get_localhost_db_handle();

	if ( ! $db instanceof \mysqli ) {
		echo magenta( 'Failed to connect to WordPress database: ' . mysqli_connect_error() );
		exit( 1 );
	}

	// Check if the default tables are there or not.
	$tables = $db->query( 'SHOW TABLES' );

	if ( ! $tables instanceof \mysqli_result ) {
		echo magenta( 'Failed to query the WordPress database: ' . mysqli_error( $db ) );
		exit( 1 );
	}

	$tables_list = $tables->fetch_all( MYSQLI_NUM );
	if ( ! empty( $tables_list ) ) {
		$default_tables = get_default_tables_list();
		$tables_list    = array_column( $tables_list, 0 );
		if ( count( array_diff( $default_tables, $tables_list ) ) === 0 ) {
			return true;
		}
	}

	$wp_root_dir  = getenv( 'TRIC_WP_DIR' );
	$install_file = realpath( $wp_root_dir . '/wp-admin/install.php' );

	if ( ! is_file( $install_file ) ) {
		echo magenta( "WordPress installation file $install_file not found." );
		exit( 1 );
	}

	// In a separate process, call the installation file directly setting up the expected request vars.
	$code = 'putenv( "DB_HOST=' . get_localhost_db_host() . '" ); ' .
	        '$_GET["step"] = 2; ' .
	        '$_POST["weblog_title"] = "Tric Test Site"; ' .
	        '$_POST["user_name"] = "admin"; ' .
	        '$_POST["admin_password"] = "password"; ' .
	        '$_POST["admin_password2"] = "password"; ' .
	        '$_POST["admin_email"] = "admin@wordpress.test"; ' .
	        '$_POST["blog_public"] = 1; ' .
	        'include "' . $install_file . '";';

	$command = escapeshellarg( PHP_BINARY ) . ' -r \'' . $code . '\'';
	exec( $command, $output, $status );

	if ( $status !== 0 ) {
		echo magenta( "WordPress installation failed: " . substr( implode( "\n", $output ), 0, 500 ) );
		exit( 1 );
	}

	return true;
}

/**
 * Fetch and return WordPress current latest version string.
 *
 * The result is cached in file for a day.
 *
 * @return string The current latest version, or `1.0.0` if the information
 *                could not be retrieved.
 */
function get_wordpress_latest_version() {
	static $current_latest_version;

	if ( $current_latest_version !== null ) {
		return $current_latest_version;
	}

	$cache_file = cache( 'wp_latest_version.txt' );

	// Invalidate after a day.
	if ( is_readable( $cache_file ) && ( time() - (int) filectime( $cache_file ) ) < 86400 ) {
		$current_latest_version = file_get_contents( $cache_file );

		return $current_latest_version;
	}

	$json = file_get_contents( 'https://api.wordpress.org/core/version-check/1.7/' );

	if ( $json === false ) {
		// We could not tell, return something that will trigger a refresh.
		return '1.0.0';
	}

	$decoded = json_decode( $json, true );

	if ( isset( $decoded['offers'][0]['current'] ) ) {
		$current_latest_version = $decoded['offers'][0]['current'];
		file_put_contents( $cache_file, $current_latest_version );

		return $current_latest_version;
	}

	// We could not tell, return something that will trigger a refresh.
	return '1.0.0';
}
