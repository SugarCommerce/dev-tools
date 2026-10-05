<?php
/**
 * Find the Composer autoloader, wherever this package was installed.
 *
 * Composer's bin proxy says where it is. Run directly, it is either this
 * package's own (a checkout) or the project's three levels up (vendor/).
 *
 * @package   SugarCommerce\DevTools
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

foreach ( array( $GLOBALS['_composer_autoload_path'] ?? '', __DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php' ) as $autoload ) {
	if ( '' !== $autoload && is_file( $autoload ) ) {
		require_once $autoload;

		return;
	}
}

fwrite( STDERR, "Could not find vendor/autoload.php; run composer install.\n" );
exit( 2 );
