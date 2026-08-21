<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use RAN\WPGitHubReleaseUpdater\V1\Http\Request;
use RAN\WPGitHubReleaseUpdater\V1\Http\Response;
use RAN\WPGitHubReleaseUpdater\V1\Http\WordPressSafeHttpTransport;
use Tests\Support\WordPressState;

spl_autoload_register(
	static function ( string $className ): void {
		$prefix = 'RAN\\WPGitHubReleaseUpdater\\V1\\';
		if ( ! str_starts_with( $className, $prefix ) ) {
			return;
		}
		$path = dirname( __DIR__, 2 ) . '/src/'
			. str_replace( '\\', '/', substr( $className, strlen( $prefix ) ) )
			. '.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
);

final class WordPressSafeHttpTransportTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		WordPressState::reset();
	}

	public function testDelegatesOneBoundedNonRedirectingStreamToWordPressCore(): void {
		WordPressState::$httpResponses[] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'Content-Type' => 'application/octet-stream' ),
			'body'     => '',
		);
		$path                            = tempnam( sys_get_temp_dir(), 'ran-transport-' );
		$result                          = ( new WordPressSafeHttpTransport() )->get(
			new Request(
				'https://api.github.com/repos/owner/repository/releases/assets/1',
				array( 'Authorization' => 'Bearer redacted' ),
				12,
				123,
				$path,
				0
			)
		);

		$this->assertInstanceOf( Response::class, $result );
		$this->assertSame( 200, $result->statusCode() );
		$this->assertCount( 1, WordPressState::$httpRequests );
		$args = WordPressState::$httpRequests[0]['args'];
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 123, $args['limit_response_size'] );
		$this->assertSame( 12, $args['timeout'] );
		$this->assertTrue( $args['stream'] );
		$this->assertSame( $path, $args['filename'] );
		$this->assertSame( 'Bearer redacted', $args['headers']['Authorization'] );

		unlink( $path );
	}

	public function testNormalizesNumericStringResponseStatus(): void {
		WordPressState::$httpResponses[] = array(
			'response' => array( 'code' => '200' ),
			'headers'  => array(),
			'body'     => '',
		);

		$result = ( new WordPressSafeHttpTransport() )->get(
			new Request( 'https://api.github.com/repos/owner/repository/releases/latest' )
		);

		$this->assertInstanceOf( Response::class, $result );
		$this->assertSame( 200, $result->statusCode() );
	}

	public function testRejectsInvalidResponseStatus(): void {
		WordPressState::$httpResponses[] = array(
			'response' => array( 'code' => 'invalid' ),
			'headers'  => array(),
			'body'     => '',
		);

		$result = ( new WordPressSafeHttpTransport() )->get(
			new Request( 'https://api.github.com/repos/owner/repository/releases/latest' )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'github_updater_invalid_response_status', $result->get_error_code() );
	}

	public function testRejectsOutOfRangeResponseStatuses(): void {
		foreach ( array( 0, '0', 99, '99', 600, '600' ) as $statusCode ) {
			WordPressState::$httpResponses[] = array(
				'response' => array( 'code' => $statusCode ),
				'headers'  => array(),
				'body'     => '',
			);

			$result = ( new WordPressSafeHttpTransport() )->get(
				new Request( 'https://api.github.com/repos/owner/repository/releases/latest' )
			);

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'github_updater_invalid_response_status', $result->get_error_code() );
		}
	}
}
