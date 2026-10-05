<?php
/**
 * Load pages in a real browser and fail on any script error.
 *
 * @package   SugarCommerce\DevTools
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\DevTools;

/**
 * Class Smoke
 *
 * Unit tests render templates and import modules, but neither runs a page's
 * scripts against its own markup. A variable that shadows a function only
 * throws in a browser, and an HTTP 200 says nothing about it — so this seeds
 * a scratch database, starts the application's own server, opens each page
 * in headless Chrome and reads the console.
 *
 * Everything application-specific comes in as an argument: the command that
 * seeds, the command that serves (with `{port}` where the port goes), and the
 * pages, given directly or printed by a command run after the seed — which is
 * where a page whose address depends on seeded data (a download token, a
 * shop's handle) comes from.
 *
 * The mistakes it is built around, each of them made at least once:
 *
 * - **A fixed port** tests whatever was left listening on it by an earlier
 *   run, against a database already deleted. The port is picked free.
 * - **A pipe from Chrome.** Its helper processes outlive the browser and hold
 *   the pipe open, so a reader never sees the end. Each page logs to a file.
 * - **Waiting for Chrome to exit.** `--dump-dom` prints when the virtual
 *   clock runs out, and headless Chrome can linger after it. Each page has a
 *   deadline, and anything still running with its profile is killed.
 * - **One shared profile.** Two Chromes on one profile wait on its lock.
 *   Each page gets its own, so pages load side by side.
 *
 * A PHP warning in the server's error log fails the run too: a page that
 * half-rendered can still have run every script it got without complaint.
 *
 * @since 1.0.0
 */
final class Smoke {

	/**
	 * What a console line must say to be a failure.
	 */
	public const string CONSOLE = '/CONSOLE.*(Uncaught|Error|Refused)/';

	/**
	 * Where Chrome lives on a Mac, tried after the CHROME variable.
	 */
	public const string MAC_CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

	/**
	 * Commands tried, in order, when no Chrome was named.
	 *
	 * @var string[]
	 */
	public const array CHROMES = array( 'chromium', 'chromium-browser', 'google-chrome', 'google-chrome-stable' );

	/**
	 * The defaults for every option.
	 *
	 * @var array<string, mixed>
	 */
	public const array DEFAULTS = array(
		'seed'       => '',
		'serve'      => '',
		'pages'      => array(),
		'pages_from' => '',
		'env'        => array(),
		'php_errors' => '',
		'ignore'     => array(),
		'chrome'     => '',
		'parallel'   => 6,
		'budget'     => 3000,
		'timeout'    => 20,
		'keep'       => false,
		'port'       => 0,
		'host'       => '127.0.0.1',
	);

	/**
	 * The scratch directory, once made.
	 */
	private string $work = '';

	/**
	 * The server process, while it runs.
	 *
	 * @var resource|null
	 */
	private $server = null;

	/**
	 * Mutable, unlike the family's value objects: a run owns a scratch
	 * directory and a server process, and has to remember both to clean
	 * them up.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $options From options(), or the DEFAULTS
	 *                                      keys set directly.
	 * @param resource             $out     Where the report goes.
	 * @param resource             $err     Where problems go.
	 */
	public function __construct(
		private readonly array $options,
		private $out = STDOUT,
		private $err = STDERR,
	) {}

	/* ─── Options ───────────────────────────────────────────────────── */

	/**
	 * Read command-line arguments into options.
	 *
	 * `--name=value` throughout. `--page`, `--env` and `--ignore` repeat; a
	 * bare argument is a page too, so `smoke … / /about` works.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $args Arguments, without the script name.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException On an option it does not know, or a
	 *                                   number that is not one.
	 */
	public static function options( array $args ): array {
		$options = self::DEFAULTS;

		foreach ( $args as $arg ) {
			if ( ! str_starts_with( $arg, '--' ) ) {
				$options['pages'][] = $arg;
				continue;
			}

			[ $name, $value ] = array_pad( explode( '=', substr( $arg, 2 ), 2 ), 2, null );
			$key              = str_replace( '-', '_', $name );

			switch ( $key ) {
				case 'keep':
					$options['keep'] = true;
					break;

				case 'page':
				case 'ignore':
					$options[ 'page' === $key ? 'pages' : 'ignore' ][] = (string) $value;
					break;

				case 'env':
					if ( null === $value || ! str_contains( $value, '=' ) ) {
						throw new \InvalidArgumentException( '--env needs NAME=value' );
					}

					[ $var, $setting ]        = explode( '=', $value, 2 );
					$options['env'][ $var ] = $setting;
					break;

				case 'parallel':
				case 'budget':
				case 'timeout':
				case 'port':
					if ( null === $value || ! ctype_digit( $value ) ) {
						throw new \InvalidArgumentException( '--' . $name . ' needs a number' );
					}

					$options[ $key ] = (int) $value;
					break;

				case 'seed':
				case 'serve':
				case 'pages_from':
				case 'php_errors':
				case 'chrome':
				case 'host':
					$options[ $key ] = (string) $value;
					break;

				default:
					throw new \InvalidArgumentException( 'Unknown option --' . $name );
			}
		}

		$options['parallel'] = max( 1, $options['parallel'] );

		return $options;
	}

	/**
	 * The page paths a command printed: one per line, blanks dropped.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Output.
	 *
	 * @return string[]
	 */
	public static function pages( string $text ): array {
		return array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), static fn ( string $line ): bool => '' !== $line ) );
	}

	/**
	 * Fill `{work}`, `{port}` and any other `{name}` placeholders.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $text Text with placeholders.
	 * @param array<string, string|int> $vars Name => value.
	 *
	 * @return string
	 */
	public static function expand( string $text, array $vars ): string {
		$pairs = array();

		foreach ( $vars as $name => $value ) {
			$pairs[ '{' . $name . '}' ] = (string) $value;
		}

		return strtr( $text, $pairs );
	}

	/* ─── Discovery ─────────────────────────────────────────────────── */

	/**
	 * The Chrome to run, or null when there is none.
	 *
	 * Named, then the CHROME variable, then where a Mac keeps it, then
	 * whichever Chromium command is on the PATH.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $given      `--chrome`, or ''.
	 * @param string        $variable   The CHROME environment variable, or ''.
	 * @param \Closure|null $executable fn( string $path ): bool. Defaults to
	 *                                  is_executable().
	 * @param \Closure|null $which      fn( string $command ): string, the
	 *                                  path or ''. Defaults to `command -v`.
	 *
	 * @return string|null
	 */
	public static function chrome( string $given = '', string $variable = '', ?\Closure $executable = null, ?\Closure $which = null ): ?string {
		$executable ??= static fn ( string $path ): bool => is_file( $path ) && is_executable( $path );
		$which      ??= static fn ( string $command ): string => trim( (string) shell_exec( 'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null' ) );

		foreach ( array( $given, $variable, self::MAC_CHROME ) as $path ) {
			if ( '' !== $path && $executable( $path ) ) {
				return $path;
			}
		}

		foreach ( self::CHROMES as $command ) {
			$path = $which( $command );

			if ( '' !== $path ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * A port nothing is listening on.
	 *
	 * Asked of the operating system rather than guessed, so two runs side by
	 * side, or a server left over from an earlier one, cannot collide.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host Interface to bind.
	 *
	 * @return int
	 *
	 * @throws \RuntimeException When no socket can be opened at all.
	 */
	public static function free_port( string $host = '127.0.0.1' ): int {
		$socket = @stream_socket_server( 'tcp://' . $host . ':0', $code, $message );

		if ( false === $socket ) {
			throw new \RuntimeException( 'No free port: ' . $message );
		}

		$name = (string) stream_socket_get_name( $socket, false );

		fclose( $socket );

		return (int) substr( $name, (int) strrpos( $name, ':' ) + 1 );
	}

	/* ─── Reading logs ──────────────────────────────────────────────── */

	/**
	 * The console errors in a Chrome log, each trimmed to its message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $log Chrome's stderr with `--enable-logging=stderr`.
	 *
	 * @return string[]
	 */
	public static function console_errors( string $log ): array {
		$errors = array();

		foreach ( explode( "\n", $log ) as $line ) {
			if ( 1 === preg_match( self::CONSOLE, $line ) ) {
				// Everything up to the quoted message is Chrome's prefix:
				// process, time, source file.
				$errors[] = (string) preg_replace( '/.*CONSOLE[^"]*/', '', $line );
			}
		}

		return $errors;
	}

	/**
	 * The lines of a PHP error log worth failing on.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $log    The log.
	 * @param string[] $ignore Patterns for lines that are expected — a demo
	 *                         with no API key logging every 401, say. Plain
	 *                         text, or a delimited regular expression.
	 *
	 * @return string[]
	 */
	public static function php_errors( string $log, array $ignore = array() ): array {
		$kept = array();

		foreach ( explode( "\n", $log ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			foreach ( $ignore as $pattern ) {
				if ( self::matches( $pattern, $line ) ) {
					continue 2;
				}
			}

			$kept[] = $line;
		}

		return $kept;
	}

	/**
	 * Lines counted and the commonest first: `uniq -c | sort -rn | head`.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $lines Lines.
	 * @param int      $limit How many to keep.
	 *
	 * @return string[]
	 */
	public static function tally( array $lines, int $limit = 20 ): array {
		$counts = array_count_values( $lines );

		uksort( $counts, static fn ( string $a, string $b ): int => $counts[ $b ] <=> $counts[ $a ] ?: strcmp( $a, $b ) );

		$out = array();

		foreach ( array_slice( $counts, 0, $limit, true ) as $line => $count ) {
			$out[] = sprintf( '%4d %s', $count, $line );
		}

		return $out;
	}

	/**
	 * The arguments that open one page in headless Chrome.
	 *
	 * @since 1.0.0
	 *
	 * @param string $chrome  Chrome's path.
	 * @param string $url     Page.
	 * @param string $profile A user data directory of its own.
	 * @param int    $budget  Virtual time, in milliseconds.
	 *
	 * @return string[]
	 */
	public static function browser( string $chrome, string $url, string $profile, int $budget ): array {
		return array(
			$chrome,
			'--headless=new',
			'--disable-gpu',
			'--no-first-run',
			'--no-default-browser-check',
			'--user-data-dir=' . $profile,
			'--enable-logging=stderr',
			'--v=0',
			'--virtual-time-budget=' . $budget,
			'--dump-dom',
			$url,
		);
	}

	/* ─── Running ───────────────────────────────────────────────────── */

	/**
	 * Seed, serve, load every page, and report.
	 *
	 * @since 1.0.0
	 *
	 * @return int 0 when every page was clean, 1 on any failure, 2 when it
	 *             could not run at all.
	 */
	public function run(): int {
		$chrome = self::chrome( (string) $this->options['chrome'], (string) getenv( 'CHROME' ) );

		if ( null === $chrome ) {
			fwrite( $this->err, "Chrome not found; set CHROME.\n" );

			return 2;
		}

		$this->work = self::scratch();

		try {
			return $this->within( $chrome );
		} finally {
			$this->stop();

			if ( $this->options['keep'] ) {
				fwrite( $this->out, 'Kept ' . $this->work . "\n" );
			} else {
				self::remove( $this->work );
			}
		}
	}

	/**
	 * The run, once there is a scratch directory to clean up after.
	 *
	 * @param string $chrome Chrome's path.
	 *
	 * @return int
	 */
	private function within( string $chrome ): int {
		$port = (int) $this->options['port'] ?: self::free_port( (string) $this->options['host'] );
		$vars = array( 'work' => $this->work, 'port' => $port );
		$env  = $this->environment( $vars );

		if ( '' !== $this->options['seed'] ) {
			$seed = $this->work . '/seed.txt';

			if ( 0 !== self::execute( self::expand( (string) $this->options['seed'], $vars ), $env, $seed ) ) {
				fwrite( $this->out, (string) @file_get_contents( $seed ) );

				return 1;
			}
		}

		$pages = array_map( static fn ( string $page ): string => self::expand( $page, $vars ), (array) $this->options['pages'] );

		if ( '' !== $this->options['pages_from'] ) {
			$listed = $this->work . '/pages.txt';

			if ( 0 !== self::execute( self::expand( (string) $this->options['pages_from'], $vars ), $env, $listed ) ) {
				fwrite( $this->err, "The pages command failed.\n" );

				return 1;
			}

			$pages = array_merge( $pages, self::pages( (string) file_get_contents( $listed ) ) );
		}

		if ( '' !== $this->options['serve'] && ! $this->start( self::expand( (string) $this->options['serve'], $vars ), $env, $port ) ) {
			fwrite( $this->err, "The server did not start:\n" . (string) @file_get_contents( $this->work . '/server.log' ) );

			return 2;
		}

		$base   = 'http://' . $this->options['host'] . ':' . $port;
		$failed = 0;

		foreach ( $this->load( $chrome, $base, $pages ) as [ $page, $errors ] ) {
			if ( $errors ) {
				fwrite( $this->out, 'FAIL ' . $page . "\n" );

				foreach ( $errors as $error ) {
					fwrite( $this->out, '     ' . $error . "\n" );
				}

				$failed = 1;
			} else {
				fwrite( $this->out, 'ok   ' . $page . "\n" );
			}
		}

		if ( '' !== $this->options['php_errors'] ) {
			$file   = self::expand( (string) $this->options['php_errors'], $vars );
			$logged = self::php_errors( is_file( $file ) ? (string) file_get_contents( $file ) : '', (array) $this->options['ignore'] );

			if ( $logged ) {
				fwrite( $this->out, "PHP logged:\n" );

				foreach ( self::tally( $logged ) as $line ) {
					fwrite( $this->out, '     ' . $line . "\n" );
				}

				$failed = 1;
			}
		}

		return $failed;
	}

	/**
	 * Open each page, a batch at a time, and collect its console errors.
	 *
	 * @param string   $chrome Chrome's path.
	 * @param string   $base   Scheme, host and port.
	 * @param string[] $pages  Paths.
	 *
	 * @return array<int, array{0: string, 1: string[]}> Each page with its
	 *                                                 errors, in the order
	 *                                                 given.
	 */
	private function load( string $chrome, string $base, array $pages ): array {
		$results = array();

		foreach ( array_chunk( $pages, (int) $this->options['parallel'], true ) as $batch ) {
			$running = array();

			foreach ( $batch as $n => $page ) {
				$profile = $this->work . '/chrome-' . $n;
				$process = proc_open(
					self::browser( $chrome, $base . $page, $profile, (int) $this->options['budget'] ),
					array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', $this->work . '/log-' . $n, 'w' ) ),
					$pipes
				);

				$running[ $n ] = array( $process, $profile, microtime( true ) );
			}

			while ( $running ) {
				foreach ( $running as $n => [ $process, $profile, $started ] ) {
					$alive = is_resource( $process ) && proc_get_status( $process )['running'];

					if ( $alive && microtime( true ) - $started < (int) $this->options['timeout'] ) {
						continue;
					}

					if ( $alive ) {
						proc_terminate( $process, 9 );
					}

					if ( is_resource( $process ) ) {
						proc_close( $process );
					}

					// Helper processes outlive the browser; they are the ones
					// holding its profile.
					exec( 'pkill -f -- ' . escapeshellarg( '--user-data-dir=' . $profile ) . ' 2>/dev/null' );

					unset( $running[ $n ] );
				}

				if ( $running ) {
					usleep( 100_000 );
				}
			}
		}

		foreach ( $pages as $n => $page ) {
			$log = $this->work . '/log-' . $n;

			$results[] = array( $page, self::console_errors( is_file( $log ) ? (string) file_get_contents( $log ) : '' ) );
		}

		return $results;
	}

	/**
	 * Start the server and wait for it to accept a connection.
	 *
	 * @param string                $command Shell command.
	 * @param array<string, string> $env     Environment.
	 * @param int                   $port    Where it should listen.
	 *
	 * @return bool
	 */
	private function start( string $command, array $env, int $port ): bool {
		// `exec`, so the process we hold is the server and not a shell that
		// would survive being told to stop while its child kept serving.
		$this->server = proc_open(
			'exec ' . $command,
			array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $this->work . '/server.log', 'w' ), 2 => array( 'file', $this->work . '/server.log', 'a' ) ),
			$pipes,
			null,
			$env
		);

		for ( $tries = 0; $tries < 50; $tries++ ) {
			if ( ! is_resource( $this->server ) || ! proc_get_status( $this->server )['running'] ) {
				return false;
			}

			$socket = @fsockopen( (string) $this->options['host'], $port, $code, $message, 0.2 );

			if ( false !== $socket ) {
				fclose( $socket );

				return true;
			}

			usleep( 100_000 );
		}

		return false;
	}

	/**
	 * Stop the server, if one was started.
	 *
	 * @return void
	 */
	private function stop(): void {
		if ( is_resource( $this->server ) ) {
			proc_terminate( $this->server );
			proc_close( $this->server );
		}

		$this->server = null;
	}

	/**
	 * The environment commands run with: ours, the options', and SMOKE_*.
	 *
	 * @param array<string, string|int> $vars Placeholder values.
	 *
	 * @return array<string, string>
	 */
	private function environment( array $vars ): array {
		$env = getenv();

		foreach ( (array) $this->options['env'] as $name => $value ) {
			$env[ (string) $name ] = self::expand( (string) $value, $vars );
		}

		$env['SMOKE_WORK'] = $this->work;
		$env['SMOKE_SEED'] = $this->work . '/seed.txt';
		$env['SMOKE_PORT'] = (string) $vars['port'];

		return $env;
	}

	/**
	 * Run a shell command to completion, its output to a file.
	 *
	 * @param string                $command Shell command.
	 * @param array<string, string> $env     Environment.
	 * @param string                $output  Where stdout goes. Stderr is left
	 *                                       on the terminal.
	 *
	 * @return int Exit code.
	 */
	private static function execute( string $command, array $env, string $output ): int {
		$process = proc_open(
			$command,
			array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $output, 'w' ), 2 => STDERR ),
			$pipes,
			null,
			$env
		);

		return is_resource( $process ) ? proc_close( $process ) : 1;
	}

	/**
	 * A new scratch directory.
	 *
	 * @return string
	 */
	private static function scratch(): string {
		$dir = rtrim( sys_get_temp_dir(), '/' ) . '/smoke-' . bin2hex( random_bytes( 6 ) );

		mkdir( $dir, 0700, true );

		return $dir;
	}

	/**
	 * Delete a directory and everything in it.
	 *
	 * @param string $dir Directory.
	 *
	 * @return void
	 */
	private static function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}

		@rmdir( $dir );
	}

	/**
	 * Whether a line matches an ignore pattern.
	 *
	 * @param string $pattern Plain text, or a regular expression with
	 *                        delimiters.
	 * @param string $line    Line.
	 *
	 * @return bool
	 */
	private static function matches( string $pattern, string $line ): bool {
		$regex = 1 === preg_match( '/^([\/#~]).+\1[imsxu]*$/', $pattern );

		return $regex ? 1 === @preg_match( $pattern, $line ) : str_contains( $line, $pattern );
	}
}
