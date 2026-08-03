<?php

declare(strict_types=1);

use Tests\Support\WordPressState;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		/** @var array<string, list<string>> */
		private array $errors = array();

		/** @var array<string, mixed> */
		private array $data = array();

		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( null !== $data ) {
					$this->data[ $code ] = $data;
				}
			}
		}

		public function get_error_code(): string {
			$codes = array_keys( $this->errors );
			return $codes[0] ?? '';
		}

		public function get_error_message( string $code = '' ): string {
			$resolvedCode = '' === $code ? $this->get_error_code() : $code;
			return $this->errors[ $resolvedCode ][0] ?? '';
		}

		public function get_error_data( string $code = '' ): mixed {
			$resolvedCode = '' === $code ? $this->get_error_code() : $code;
			return $this->data[ $resolvedCode ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_Upgrader' ) ) {
	class WP_Upgrader {
	}
}

if ( ! class_exists( 'WP_Screen' ) ) {
	class WP_Screen {

		public function __construct( public string $base ) {
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
		return WordPressState::addAction( $hook, $callback, $priority, $acceptedArgs );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
		return WordPressState::addFilter( $hook, $callback, $priority, $acceptedArgs );
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( string $hook, callable $callback, int $priority = 10 ): bool {
		return WordPressState::removeAction( $hook, $callback, $priority );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
		return WordPressState::removeFilter( $hook, $callback, $priority );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return WordPressState::didAction( $hook );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		WordPressState::doAction( $hook, ...$args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return WordPressState::applyFilters( $hook, $value, ...$args );
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( string $key ): mixed {
		return WordPressState::$siteTransients[ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_site_transient' ) ) {
	function set_site_transient( string $key, mixed $value, int $expiration = 0 ): bool {
		WordPressState::$siteTransients[ $key ]           = $value;
		WordPressState::$siteTransientExpirations[ $key ] = $expiration;
		return true;
	}
}

if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( string $key ): bool {
		unset(
			WordPressState::$siteTransients[ $key ],
			WordPressState::$siteTransientExpirations[ $key ]
		);
		return true;
	}
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( string $url, array $args = array() ): array|WP_Error {
		WordPressState::$httpRequests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		$response                       = array_shift( WordPressState::$httpResponses );
		return null === $response
			? new WP_Error( 'http_request_failed', 'No test HTTP response was queued.' )
			: $response;
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( string $url ): string|false {
		$parts = parse_url( $url );
		if ( ! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| 'https' !== strtolower( (string) $parts['scheme'] )
		) {
			return false;
		}

		return $url;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array|WP_Error $response ): int {
		return $response instanceof WP_Error
			? 0
			: (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array|WP_Error $response ): string {
		return $response instanceof WP_Error ? '' : (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	function wp_remote_retrieve_headers( array|WP_Error $response ): array {
		return $response instanceof WP_Error || ! is_array( $response['headers'] ?? null )
			? array()
			: $response['headers'];
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( string $filename = '', string $dir = '' ): string|false {
		$directory = '' === $dir ? sys_get_temp_dir() : $dir;
		return tempnam( $directory, 'ran-wp-gh-' );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): void {
		WordPressState::$deletedFiles[] = $file;
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		if ( isset( WordPressState::$pluginBasenames[ $file ] ) ) {
			return WordPressState::$pluginBasenames[ $file ];
		}

		$normalized = str_replace( '\\', '/', $file );
		$marker     = '/wp-content/plugins/';
		$position   = strpos( $normalized, $marker );
		return false === $position
			? basename( dirname( $normalized ) ) . '/' . basename( $normalized )
			: substr( $normalized, $position + strlen( $marker ) );
	}
}

if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( string $pluginFile, bool $markup = true, bool $translate = true ): array {
		unset( $markup, $translate );
		return WordPressState::$pluginData[ $pluginFile ] ?? array();
	}
}

if ( ! function_exists( 'get_file_data' ) ) {
	function get_file_data( string $file, array $defaultHeaders, string $context = '' ): array {
		unset( $defaultHeaders, $context );
		return WordPressState::$pluginData[ $file ] ?? array();
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		$text = strip_tags( $text );
		$text = preg_replace( '/[\\r\\n\\t ]+/', ' ', $text ) ?? '';
		return trim( $text );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen(): WP_Screen {
		return new WP_Screen( WordPressState::$screenBase );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		unset( $capability );
		return WordPressState::$currentUserCan;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return WordPressState::$multisite;
	}
}

if ( ! function_exists( 'get_current_network_id' ) ) {
	function get_current_network_id(): int {
		return WordPressState::$currentNetworkId;
	}
}

if ( ! function_exists( 'get_main_site_id' ) ) {
	function get_main_site_id( ?int $networkId = null ): int {
		unset( $networkId );
		return WordPressState::$mainSiteId;
	}
}
