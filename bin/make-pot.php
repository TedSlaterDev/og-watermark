<?php
/**
 * One-off .pot extractor for OG Watermark.
 *
 * wp-cli is not available in this environment, so this token-based extractor
 * walks src/ and emits a valid gettext .pot covering every translatable string
 * that uses the 'og-watermark' text domain. It understands the standard
 * WordPress i18n functions, including context (_x) and plural (_n) variants,
 * and harvests the leading "translators:" comments.
 *
 * Usage:  php bin/make-pot.php
 *
 * @package OrchardGrove\OgWatermark
 */

declare( strict_types=1 );

const TEXT_DOMAIN = 'og-watermark';

// fn => [ which arg index (0-based) holds singular, plural, context ].
// null means "not used by this function".
$functions = array(
	'__'            => array( 'single' => 0, 'plural' => null, 'context' => null ),
	'_e'            => array( 'single' => 0, 'plural' => null, 'context' => null ),
	'esc_html__'    => array( 'single' => 0, 'plural' => null, 'context' => null ),
	'esc_html_e'    => array( 'single' => 0, 'plural' => null, 'context' => null ),
	'esc_attr__'    => array( 'single' => 0, 'plural' => null, 'context' => null ),
	'esc_attr_e'    => array( 'single' => 0, 'plural' => null, 'context' => null ),
	'_x'            => array( 'single' => 0, 'plural' => null, 'context' => 1 ),
	'_ex'           => array( 'single' => 0, 'plural' => null, 'context' => 1 ),
	'esc_html_x'    => array( 'single' => 0, 'plural' => null, 'context' => 1 ),
	'esc_attr_x'    => array( 'single' => 0, 'plural' => null, 'context' => 1 ),
	'_n'            => array( 'single' => 0, 'plural' => 1, 'context' => null ),
	'_nx'           => array( 'single' => 0, 'plural' => 1, 'context' => 3 ),
	'_n_noop'       => array( 'single' => 0, 'plural' => 1, 'context' => null ),
	'_nx_noop'      => array( 'single' => 0, 'plural' => 1, 'context' => 2 ),
);

$root    = dirname( __DIR__ );
$srcDir  = $root . '/src';
$entries = array();

$rii = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $srcDir, FilesystemIterator::SKIP_DOTS )
);

/** @var SplFileInfo $file */
foreach ( $rii as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$path     = $file->getPathname();
	$relative = ltrim( str_replace( $root, '', $path ), '/' );
	$source   = file_get_contents( $path );

	if ( false === $source ) {
		continue;
	}

	extractFromFile( $source, $relative, $functions, $entries );
}

ksort( $entries, SORT_STRING );

$pot = renderPot( $entries );

$outDir = $root . '/languages';
if ( ! is_dir( $outDir ) ) {
	mkdir( $outDir, 0755, true );
}

$outFile = $outDir . '/og-watermark.pot';
file_put_contents( $outFile, $pot );

fwrite( STDOUT, sprintf( "Wrote %d unique string(s) to %s\n", count( $entries ), $outFile ) );

/**
 * Token-walk a single file and collect translation entries.
 *
 * @param string               $source    File contents.
 * @param string               $relative  Path relative to plugin root.
 * @param array<string,array>  $functions Function arg map.
 * @param array<string,array>  $entries   Accumulator, keyed by context\004msgid.
 */
function extractFromFile( string $source, string $relative, array $functions, array &$entries ): void {
	$tokens = token_get_all( $source );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}

		$fn = $token[1];
		if ( ! isset( $functions[ $fn ] ) ) {
			continue;
		}

		// Must be a function call: not a method/property/object operator before it.
		$prev = previousSignificant( $tokens, $i );
		if ( null !== $prev && is_array( $prev ) ) {
			if ( in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
				continue;
			}
		}

		// Next significant token must be an opening paren.
		$open = nextSignificantIndex( $tokens, $i );
		if ( null === $open || '(' !== $tokens[ $open ] ) {
			continue;
		}

		$line = $token[2];
		$args = parseArguments( $tokens, $open );
		$spec = $functions[ $fn ];

		// The domain must match. Domain is always the last positional argument:
		// __(text,domain); _x(text,context,domain); _n(s,p,number,domain); _nx(s,p,number,context,domain).
		$domain = lastStringArg( $args );
		if ( TEXT_DOMAIN !== $domain ) {
			continue;
		}

		$singular = stringArg( $args, $spec['single'] );
		if ( null === $singular ) {
			continue;
		}

		$plural  = null !== $spec['plural'] ? stringArg( $args, $spec['plural'] ) : null;
		$context = null !== $spec['context'] ? stringArg( $args, $spec['context'] ) : null;

		$key = ( null !== $context ? $context : '' ) . "\004" . $singular . "\004" . ( null !== $plural ? $plural : '' );

		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'context'    => $context,
				'msgid'      => $singular,
				'msgid_plural' => $plural,
				'references' => array(),
				'comments'   => array(),
			);
		}

		$entries[ $key ]['references'][] = $relative . ':' . $line;

		$comment = leadingTranslatorComment( $tokens, $i );
		if ( null !== $comment && ! in_array( $comment, $entries[ $key ]['comments'], true ) ) {
			$entries[ $key ]['comments'][] = $comment;
		}
	}
}

/**
 * Parse the argument list following an opening paren index.
 * Returns a flat list of arguments; each arg is the list of its tokens.
 *
 * @param array $tokens All tokens.
 * @param int   $open   Index of the '(' token.
 * @return array<int,array> One entry per top-level argument.
 */
function parseArguments( array $tokens, int $open ): array {
	$depth = 0;
	$args  = array();
	$cur   = array();
	$count = count( $tokens );

	for ( $i = $open; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		$s = is_array( $t ) ? $t[1] : $t;

		if ( '(' === $s ) {
			++$depth;
			if ( 1 === $depth ) {
				continue; // Skip the outermost opening paren itself.
			}
		} elseif ( ')' === $s ) {
			--$depth;
			if ( 0 === $depth ) {
				$args[] = $cur;
				break;
			}
		} elseif ( ',' === $s && 1 === $depth ) {
			$args[] = $cur;
			$cur    = array();
			continue;
		}

		$cur[] = $t;
	}

	return $args;
}

/**
 * Resolve a single argument to a literal string if (and only if) it is a
 * single string literal (optionally with adjacent '.' concatenation of
 * literals). Returns null for anything dynamic.
 *
 * @param array $args Parsed arguments.
 * @param int   $idx  Argument index.
 */
function stringArg( array $args, int $idx ): ?string {
	if ( ! isset( $args[ $idx ] ) ) {
		return null;
	}

	$value      = '';
	$sawLiteral = false;

	foreach ( $args[ $idx ] as $t ) {
		if ( is_array( $t ) ) {
			if ( T_WHITESPACE === $t[0] || T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) {
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
				$value     .= decodeStringLiteral( $t[1] );
				$sawLiteral = true;
				continue;
			}
			// Any other token (variable, function call, etc.) => not a static literal.
			return null;
		}

		if ( '.' === $t ) {
			continue; // Concatenation of literals is fine.
		}

		return null;
	}

	return $sawLiteral ? $value : null;
}

/**
 * The text domain is always the final positional string argument.
 *
 * @param array $args Parsed arguments.
 */
function lastStringArg( array $args ): ?string {
	for ( $i = count( $args ) - 1; $i >= 0; $i-- ) {
		$v = stringArg( $args, $i );
		if ( null !== $v ) {
			return $v;
		}
	}
	return null;
}

/**
 * Decode a PHP single- or double-quoted string literal to its raw value.
 */
function decodeStringLiteral( string $raw ): string {
	$quote = $raw[0];
	$inner = substr( $raw, 1, -1 );

	if ( "'" === $quote ) {
		return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $inner );
	}

	// Double-quoted: handle common escapes.
	return stripcslashes( $inner );
}

/**
 * Return the previous significant (non-whitespace, non-comment) token.
 *
 * @param array $tokens All tokens.
 * @param int   $i      Current index.
 * @return array|string|null
 */
function previousSignificant( array $tokens, int $i ) {
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		$t = $tokens[ $j ];
		if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $t;
	}
	return null;
}

/**
 * Index of the next significant token.
 */
function nextSignificantIndex( array $tokens, int $i ): ?int {
	$count = count( $tokens );
	for ( $j = $i + 1; $j < $count; $j++ ) {
		$t = $tokens[ $j ];
		if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $j;
	}
	return null;
}

/**
 * Find a leading "translators:" comment immediately preceding the call.
 *
 * @param array $tokens All tokens.
 * @param int   $i      Index of the function-name token.
 */
function leadingTranslatorComment( array $tokens, int $i ): ?string {
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		$t = $tokens[ $j ];

		if ( is_array( $t ) && T_WHITESPACE === $t[0] ) {
			continue;
		}

		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$text = trim( $t[1] );
			$text = preg_replace( '~^(/\*+|//|#)\s*~', '', $text );
			$text = preg_replace( '~\s*\*+/$~', '', (string) $text );
			$text = trim( (string) $text );

			if ( 0 === stripos( $text, 'translators:' ) ) {
				return $text;
			}
			return null;
		}

		// Some calls are wrapped (sprintf( /* translators */ _n( ... ) )): the
		// comment may sit before the function name across an open-paren. Skip a
		// single '(' to reach it.
		if ( '(' === $t ) {
			continue;
		}

		return null;
	}

	return null;
}

/**
 * Render the full .pot document.
 *
 * @param array<string,array> $entries Collected entries.
 */
function renderPot( array $entries ): string {
	$date = gmdate( 'Y-m-d H:iO' );

	$header = <<<POT
# Copyright (C) Orchard Grove Media, LLC
# This file is distributed under the GPL-2.0-or-later license.
msgid ""
msgstr ""
"Project-Id-Version: OG Watermark 1.0.0\\n"
"Report-Msgid-Bugs-To: https://orchardgrove.com/\\n"
"POT-Creation-Date: {$date}\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"Language: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
"X-Domain: og-watermark\\n"

POT;

	$blocks = array();

	foreach ( $entries as $entry ) {
		$lines = array();

		foreach ( $entry['comments'] as $comment ) {
			$lines[] = '#. ' . $comment;
		}

		// Wrap references at a sensible width.
		$refLine = '';
		foreach ( $entry['references'] as $ref ) {
			$candidate = '' === $refLine ? $ref : $refLine . ' ' . $ref;
			if ( strlen( '#: ' . $candidate ) > 76 && '' !== $refLine ) {
				$lines[] = '#: ' . $refLine;
				$refLine = $ref;
			} else {
				$refLine = $candidate;
			}
		}
		if ( '' !== $refLine ) {
			$lines[] = '#: ' . $refLine;
		}

		if ( null !== $entry['context'] ) {
			$lines[] = 'msgctxt ' . poString( $entry['context'] );
		}

		$lines[] = 'msgid ' . poString( $entry['msgid'] );

		if ( null !== $entry['msgid_plural'] ) {
			$lines[] = 'msgid_plural ' . poString( $entry['msgid_plural'] );
			$lines[] = 'msgstr[0] ""';
			$lines[] = 'msgstr[1] ""';
		} else {
			$lines[] = 'msgstr ""';
		}

		$blocks[] = implode( "\n", $lines );
	}

	return $header . "\n" . implode( "\n\n", $blocks ) . "\n";
}

/**
 * Encode a value as a .po quoted string.
 */
function poString( string $value ): string {
	$escaped = str_replace(
		array( '\\', '"', "\t", "\n" ),
		array( '\\\\', '\\"', '\\t', '\\n' ),
		$value
	);

	return '"' . $escaped . '"';
}
