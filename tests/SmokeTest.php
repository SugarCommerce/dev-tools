<?php
/**
 * Tests for the browser smoke checker's moving parts.
 *
 * @package   SugarCommerce\DevTools
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\DevTools\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\DevTools\Smoke;

/**
 * Class SmokeTest
 *
 * Everything but the browser itself, which needs a browser.
 */
final class SmokeTest extends TestCase {

	/* ─── Options ──────────────────────────────────────────────────── */

	public function test_no_arguments_gives_the_defaults(): void {
		$this->assertSame( Smoke::DEFAULTS, Smoke::options( array() ) );
	}

	public function test_options_are_read(): void {
		$options = Smoke::options( array(
			'--seed=php bin/seed.php --fresh',
			'--serve=php -S 127.0.0.1:{port} -t public',
			'--page=/',
			'/about',
			'--page=/d/abc?lang=de',
			'--pages-from=bin/pages.sh',
			'--env=DB_PATH={work}/smoke.db',
			'--env=LOCAL_DEV=1',
			'--php-errors={work}/php-errors',
			'--ignore=Stripe API 401',
			'--chrome=/opt/chrome',
			'--parallel=3',
			'--budget=5000',
			'--timeout=30',
			'--port=8123',
			'--keep',
		) );

		$this->assertSame( 'php bin/seed.php --fresh', $options['seed'] );
		$this->assertSame( 'php -S 127.0.0.1:{port} -t public', $options['serve'] );
		$this->assertSame( array( '/', '/about', '/d/abc?lang=de' ), $options['pages'] );
		$this->assertSame( 'bin/pages.sh', $options['pages_from'] );
		$this->assertSame( array( 'DB_PATH' => '{work}/smoke.db', 'LOCAL_DEV' => '1' ), $options['env'] );
		$this->assertSame( '{work}/php-errors', $options['php_errors'] );
		$this->assertSame( array( 'Stripe API 401' ), $options['ignore'] );
		$this->assertSame( '/opt/chrome', $options['chrome'] );
		$this->assertSame( 3, $options['parallel'] );
		$this->assertSame( 5000, $options['budget'] );
		$this->assertSame( 30, $options['timeout'] );
		$this->assertSame( 8123, $options['port'] );
		$this->assertTrue( $options['keep'] );
	}

	public function test_an_unknown_option_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Smoke::options( array( '--pagse=/' ) );
	}

	public function test_a_number_that_is_not_one_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Smoke::options( array( '--parallel=many' ) );
	}

	public function test_an_env_without_a_value_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Smoke::options( array( '--env=DB_PATH' ) );
	}

	public function test_parallel_is_at_least_one(): void {
		$this->assertSame( 1, Smoke::options( array( '--parallel=0' ) )['parallel'] );
	}

	/* ─── Pages and placeholders ───────────────────────────────────── */

	public function test_pages_are_read_one_per_line(): void {
		$this->assertSame( array( '/', '/s/shop', '/d/abc' ), Smoke::pages( "/\n\n  /s/shop  \n/d/abc\n" ) );
	}

	public function test_placeholders_are_filled(): void {
		$this->assertSame(
			'php -S 127.0.0.1:8080 -d error_log=/tmp/w/php-errors',
			Smoke::expand( 'php -S 127.0.0.1:{port} -d error_log={work}/php-errors', array( 'work' => '/tmp/w', 'port' => 8080 ) )
		);
		$this->assertSame( '{unknown}', Smoke::expand( '{unknown}', array( 'work' => '/tmp/w' ) ) );
	}

	/* ─── Discovery ────────────────────────────────────────────────── */

	public function test_a_named_chrome_comes_first(): void {
		$this->assertSame( '/opt/chrome', Smoke::chrome( '/opt/chrome', '/env/chrome', static fn (): bool => true, static fn (): string => '/usr/bin/chromium' ) );
	}

	public function test_then_the_variable_then_the_mac_path(): void {
		$only = static fn ( string $allowed ): \Closure => static fn ( string $path ): bool => $path === $allowed;
		$none = static fn (): string => '';

		$this->assertSame( '/env/chrome', Smoke::chrome( '/missing', '/env/chrome', $only( '/env/chrome' ), $none ) );
		$this->assertSame( Smoke::MAC_CHROME, Smoke::chrome( '', '', $only( Smoke::MAC_CHROME ), $none ) );
	}

	public function test_then_whatever_is_on_the_path(): void {
		$which = static fn ( string $command ): string => 'google-chrome' === $command ? '/usr/bin/google-chrome' : '';

		$this->assertSame( '/usr/bin/google-chrome', Smoke::chrome( '', '', static fn (): bool => false, $which ) );
	}

	public function test_no_chrome_is_null(): void {
		$this->assertNull( Smoke::chrome( '', '', static fn (): bool => false, static fn (): string => '' ) );
	}

	public function test_a_free_port_can_be_listened_on(): void {
		$port   = Smoke::free_port();
		$socket = stream_socket_server( 'tcp://127.0.0.1:' . $port );

		$this->assertGreaterThan( 0, $port );
		$this->assertNotFalse( $socket );

		fclose( $socket );
	}

	/* ─── Reading logs ─────────────────────────────────────────────── */

	public function test_console_errors_are_found_and_trimmed(): void {
		$log = implode( "\n", array(
			'[1006/101112.131415:INFO:CONSOLE(42)] "Uncaught TypeError: items is not a function", source: http://127.0.0.1/assets/cart.js (42)',
			'[1006/101112.131416:INFO:CONSOLE(1)] "Refused to load the script because it violates CSP", source: http://127.0.0.1/ (1)',
			'[1006/101112.131417:INFO:CONSOLE(7)] "hello from a log line", source: http://127.0.0.1/ (7)',
			'[1006/101112.131418:WARNING:gpu_init.cc(12)] GPU Error unrelated to the page',
		) );

		$this->assertSame(
			array(
				'"Uncaught TypeError: items is not a function", source: http://127.0.0.1/assets/cart.js (42)',
				'"Refused to load the script because it violates CSP", source: http://127.0.0.1/ (1)',
			),
			Smoke::console_errors( $log )
		);
	}

	public function test_a_clean_log_has_no_errors(): void {
		$this->assertSame( array(), Smoke::console_errors( '' ) );
	}

	public function test_expected_php_errors_are_ignored(): void {
		$log = "PHP Warning: Undefined variable \$x\n\nStripe API 401 for acct_1\nPHP Notice: something\n";

		$this->assertSame(
			array( 'PHP Notice: something' ),
			Smoke::php_errors( $log, array( 'Stripe API 401', '/^PHP Warning:/' ) )
		);
		$this->assertCount( 3, Smoke::php_errors( $log ) );
	}

	public function test_php_errors_are_tallied_commonest_first(): void {
		$this->assertSame(
			array( '   2 b', '   1 a' ),
			Smoke::tally( array( 'a', 'b', 'b' ) )
		);
		$this->assertCount( 1, Smoke::tally( array( 'a', 'b' ), 1 ) );
	}

	/* ─── The browser's arguments ──────────────────────────────────── */

	public function test_each_page_gets_its_own_profile_and_a_budget(): void {
		$args = Smoke::browser( '/opt/chrome', 'http://127.0.0.1:8080/', '/tmp/w/chrome-3', 3000 );

		$this->assertSame( '/opt/chrome', $args[0] );
		$this->assertSame( 'http://127.0.0.1:8080/', end( $args ) );
		$this->assertContains( '--headless=new', $args );
		$this->assertContains( '--user-data-dir=/tmp/w/chrome-3', $args );
		$this->assertContains( '--virtual-time-budget=3000', $args );
		$this->assertContains( '--enable-logging=stderr', $args );
		$this->assertContains( '--dump-dom', $args );
	}

	/* ─── Running ──────────────────────────────────────────────────── */

	public function test_a_run_seeds_serves_loads_and_reports(): void {
		$dir = sys_get_temp_dir() . '/smoke-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/public', 0777, true );
		file_put_contents( $dir . '/public/index.php', '<?php echo "page";' );

		// A stand-in browser: it fetches the page, so the server has to be
		// up, and reports a console error for any page with "bad" in it.
		file_put_contents( $dir . '/chrome', "#!/bin/sh\nfor a; do url=\"\$a\"; done\ncurl -sf \"\$url\" > /dev/null || echo 'CONSOLE(1)] \"Uncaught unreachable\"' >&2\ncase \"\$url\" in *bad*) echo '[x:INFO:CONSOLE(3)] \"Uncaught Error: broke\", source: x' >&2;; esac\n" );
		chmod( $dir . '/chrome', 0755 );

		$out  = fopen( 'php://memory', 'w+' );
		$err  = fopen( 'php://memory', 'w+' );
		$code = ( new Smoke(
			Smoke::options( array(
				'--chrome=' . $dir . '/chrome',
				'--seed=echo seeded; echo "$GREETING" > ' . $dir . '/greeting',
				'--env=GREETING=hello from {work}',
				'--serve=php -d log_errors=1 -d error_log={work}/php-errors -S 127.0.0.1:{port} -t ' . $dir . '/public',
				'--pages-from=grep -q seeded "$SMOKE_SEED" && printf "/bad\\n"',
				'--page=/',
				'--php-errors={work}/php-errors',
				'--timeout=10',
			) ),
			$out,
			$err
		) )->run();

		rewind( $out );
		$report = (string) stream_get_contents( $out );

		$this->assertSame( 1, $code, $report );
		$this->assertStringContainsString( "ok   /\n", $report );
		$this->assertStringContainsString( "FAIL /bad\n     \"Uncaught Error: broke\", source: x\n", $report );
		$this->assertStringStartsWith( 'hello from ' . sys_get_temp_dir(), (string) file_get_contents( $dir . '/greeting' ) );

		unlink( $dir . '/greeting' );
		unlink( $dir . '/chrome' );
		unlink( $dir . '/public/index.php' );
		rmdir( $dir . '/public' );
		rmdir( $dir );
	}
}
