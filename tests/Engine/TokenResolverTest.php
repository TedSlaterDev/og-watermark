<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Engine;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Engine\TokenResolver;
use OrchardGrove\OgWatermark\Tests\TestCase;

final class TokenResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => 'name' === $key ? 'Conservatives Today' : ''
		);
		Functions\when( 'wp_date' )->alias(
			static fn( $format ) => 'Y' === $format ? '2026' : ''
		);
		Functions\when( 'home_url' )->justReturn( 'https://www.example.com/blog' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( $url, $component = -1 ) => parse_url( (string) $url, $component )
		);
	}

	public function testResolvesSiteToken(): void {
		$this->assertSame( 'Conservatives Today', TokenResolver::resolve( '{site}' ) );
	}

	public function testResolvesYearToken(): void {
		$this->assertSame( '2026', TokenResolver::resolve( '{year}' ) );
	}

	public function testResolvesCopyTokenToCopyrightGlyph(): void {
		$this->assertSame( "\u{00A9}", TokenResolver::resolve( '{copy}' ) );
	}

	public function testResolvesUrlTokenToHost(): void {
		// Host only — scheme and path are stripped.
		$this->assertSame( 'www.example.com', TokenResolver::resolve( '{url}' ) );
	}

	public function testResolvesMixedTemplate(): void {
		$this->assertSame(
			"\u{00A9} 2026 Conservatives Today",
			TokenResolver::resolve( '{copy} {year} {site}' )
		);
	}

	public function testUnknownTokensLeftIntact(): void {
		$this->assertSame(
			'{author} kept; © 2026',
			TokenResolver::resolve( '{author} kept; {copy} {year}' )
		);
	}

	public function testTemplateWithoutTokensReturnedVerbatim(): void {
		$this->assertSame( 'All Rights Reserved', TokenResolver::resolve( 'All Rights Reserved' ) );
	}

	public function testUnparseableHomeUrlYieldsEmptyHost(): void {
		Functions\when( 'home_url' )->justReturn( 'not a url' );
		// strtr replaces {url} with '' → just the trailing literal remains.
		$this->assertSame( 'site: ', TokenResolver::resolve( 'site: {url}' ) );
	}
}
