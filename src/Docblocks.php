<?php
/**
 * Find every declaration with no docblock above it.
 *
 * @package   SugarCommerce\DevTools
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\DevTools;

/**
 * Class Docblocks
 *
 * Answers one question for a whole tree at once — which classes, interfaces,
 * traits, enums, functions and methods have nothing written above them — so
 * "add docblocks" is a finite list rather than a habit, and a build can fail
 * the day the list stops being empty.
 *
 * Not a style checker. It does not read what a docblock says, only whether
 * there is one. It tokenises rather than pattern-matches, because the things
 * that fool a regular expression are exactly the things a codebase is full
 * of:
 *
 * - `Foo::class` contains the `class` keyword and declares nothing;
 * - `use function Foo\{ bar, baz };` tokenises as `function` and declares
 *   nothing;
 * - an attribute such as `#[\Override]` or `#[\NoDiscard( '…' )]` sits
 *   between a docblock and the method it documents, brackets and all;
 * - a closure is a `function` with no name, and nobody documents those.
 *
 * @since 1.0.0
 */
final readonly class Docblocks {

	/**
	 * Tokens that may sit between a docblock and what it documents.
	 *
	 * @var list<int>
	 */
	private const array SKIPPABLE = array(
		T_WHITESPACE,
		T_PUBLIC,
		T_PRIVATE,
		T_PROTECTED,
		T_STATIC,
		T_ABSTRACT,
		T_FINAL,
		T_READONLY,
	);

	/**
	 * Tokens that declare something worth documenting.
	 *
	 * @var list<int>
	 */
	private const array DECLARATIONS = array( T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM );

	/**
	 * Every undocumented declaration under some directories or files.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $roots Directories (read recursively) or single files.
	 *                        Only `.php` files are read.
	 *
	 * @return list<array{file: string, line: int, name: string}> Sorted
	 *         the way report() prints them.
	 */
	public static function scan( array $roots ): array {
		$missing = array();

		foreach ( self::files( $roots ) as $file ) {
			foreach ( self::in( (string) file_get_contents( $file ) ) as $found ) {
				$missing[] = array( 'file' => $file, 'line' => $found['line'], 'name' => $found['name'] );
			}
		}

		usort( $missing, static fn ( array $a, array $b ): int => strcmp( self::line( $a ), self::line( $b ) ) );

		return $missing;
	}

	/**
	 * The undocumented declarations in one piece of PHP source.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source PHP source, opening tag and all.
	 *
	 * @return list<array{line: int, name: string}>
	 */
	public static function in( string $source ): array {
		// `use function Foo\{ bar, baz };` tokenises as T_FUNCTION but
		// declares nothing, so it goes before tokenising rather than being
		// special-cased.
		$source = (string) preg_replace_callback(
			'/^use function [^;]+;/ms',
			// Kept as blank lines, so every line number after it is still true.
			static fn ( array $m ): string => str_repeat( "\n", substr_count( $m[0], "\n" ) ),
			$source
		);
		$tokens = token_get_all( $source );
		$count  = count( $tokens );
		$found  = array();

		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], self::DECLARATIONS, true ) ) {
				continue;
			}

			// `Foo::class` is the class keyword, but it names a class rather
			// than declaring one.
			$before = $tokens[ $i - 1 ] ?? null;

			if ( T_CLASS === $token[0] && is_array( $before ) && T_DOUBLE_COLON === $before[0] ) {
				continue;
			}

			// `new class … {}` is an anonymous class: what follows the keyword
			// is what it extends, not its name.
			if ( T_CLASS === $token[0] && self::anonymous( $tokens, $i ) ) {
				continue;
			}

			$name = self::name( $tokens, $i, $count );

			if ( null === $name || self::documented( $tokens, $i ) ) {
				continue;
			}

			$found[] = array( 'line' => $token[2], 'name' => $name );
		}

		return $found;
	}

	/**
	 * The report bin/docblocks prints: one line per miss, then the count.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{file: string, line: int, name: string}> $missing From scan().
	 *
	 * @return string
	 */
	public static function report( array $missing ): string {
		$lines = array_map( self::line( ... ), $missing );

		return ( $lines ? implode( PHP_EOL, $lines ) . PHP_EOL . PHP_EOL : '' ) . count( $missing ) . ' missing' . PHP_EOL;
	}

	/**
	 * One miss as a line: `path:line  name`.
	 *
	 * @param array{file: string, line: int, name: string} $miss A miss.
	 *
	 * @return string
	 */
	private static function line( array $miss ): string {
		return sprintf( '%s:%d  %s', $miss['file'], $miss['line'], $miss['name'] );
	}

	/**
	 * The name a declaration gives, or null for a closure.
	 *
	 * @param list<string|array{0: int, 1: string, 2: int}> $tokens Every token.
	 * @param int               $at     The declaring keyword's index.
	 * @param int               $count  How many tokens there are.
	 *
	 * @return string|null
	 */
	private static function name( array $tokens, int $at, int $count ): ?string {
		for ( $j = $at + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				return $tokens[ $j ][1];
			}

			// An anonymous function or class reaches its parameters, or its
			// body, before it reaches a name.
			if ( '(' === $tokens[ $j ] || '{' === $tokens[ $j ] ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Whether the `class` keyword at an index follows `new`.
	 *
	 * @param list<string|array{0: int, 1: string, 2: int}> $tokens Every token.
	 * @param int               $at     The keyword's index.
	 *
	 * @return bool
	 */
	private static function anonymous( array $tokens, int $at ): bool {
		for ( $j = $at - 1; $j >= 0; $j-- ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_READONLY ), true ) ) {
				continue;
			}

			return is_array( $tokens[ $j ] ) && T_NEW === $tokens[ $j ][0];
		}

		return false;
	}

	/**
	 * Whether a docblock sits above the declaration at an index.
	 *
	 * Walks back over modifiers, whitespace and attributes; anything else
	 * means there is none.
	 *
	 * @param list<string|array{0: int, 1: string, 2: int}> $tokens Every token.
	 * @param int               $at     The declaring keyword's index.
	 *
	 * @return bool
	 */
	private static function documented( array $tokens, int $at ): bool {
		for ( $j = $at - 1; $j >= 0; $j-- ) {
			$prev = $tokens[ $j ];

			if ( is_array( $prev ) && T_DOC_COMMENT === $prev[0] ) {
				return true;
			}

			if ( is_array( $prev ) && in_array( $prev[0], self::SKIPPABLE, true ) ) {
				continue;
			}

			// An attribute — #[\Override], #[\NoDiscard( '…' )] — sits between
			// the docblock and what it documents. Step back over the whole of
			// it, nested brackets and all, to its opening `#[`.
			if ( ']' === $prev ) {
				for ( $depth = 0; $j >= 0; $j-- ) {
					$depth += match ( true ) {
						']' === $tokens[ $j ]                                          => 1,
						'[' === $tokens[ $j ],
						is_array( $tokens[ $j ] ) && T_ATTRIBUTE === $tokens[ $j ][0] => -1,
						default                                                        => 0,
					};

					if ( 0 === $depth ) {
						break;
					}
				}

				continue;
			}

			return false;
		}

		return false;
	}

	/**
	 * The PHP files under some roots.
	 *
	 * @param string[] $roots Directories or files.
	 *
	 * @return list<string>
	 */
	private static function files( array $roots ): array {
		$files = array();

		foreach ( $roots as $root ) {
			if ( is_file( $root ) ) {
				$files[] = $root;
				continue;
			}

			if ( ! is_dir( $root ) ) {
				continue;
			}

			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}
}
