<?php

declare(strict_types=1);

class WP_Error {
	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
	}

	public function get_error_code(): string {
		return '';
	}

	public function get_error_message( string $code = '' ): string {
		return '';
	}

	public function get_error_data( string $code = '' ): mixed {
		return null;
	}
}

class WP_Upgrader {
}

function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	return true;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	return true;
}

function do_action( string $hook, mixed ...$args ): void {
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return $value;
}

function remove_action( string $hook, callable $callback, int $priority = 10 ): bool {
	return true;
}

function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
	return true;
}

function get_site_transient( string $key ): mixed {
	return false;
}

function set_site_transient( string $key, mixed $value, int $expiration = 0 ): bool {
	return true;
}

function delete_site_transient( string $key ): bool {
	return true;
}

function wp_safe_remote_get( string $url, array $args = array() ): array|WP_Error {
	return array();
}

function wp_http_validate_url( string $url ): string|false {
	return $url;
}

function wp_remote_retrieve_response_code( array|WP_Error $response ): int {
	return 0;
}

function wp_remote_retrieve_body( array|WP_Error $response ): string {
	return '';
}

function wp_remote_retrieve_headers( array|WP_Error $response ): array {
	return array();
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function wp_tempnam( string $filename = '', string $dir = '' ): string|false {
	return false;
}

function wp_delete_file( string $file ): void {
}

function plugin_basename( string $file ): string {
	return $file;
}

function get_plugin_data( string $pluginFile, bool $markup = true, bool $translate = true ): array {
	return array();
}

function get_file_data( string $file, array $defaultHeaders, string $context = '' ): array {
	return array();
}

function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
	return json_encode( $value, $flags, $depth );
}

function sanitize_key( string $key ): string {
	return $key;
}

function sanitize_text_field( string $text ): string {
	return $text;
}

function wp_unslash( mixed $value ): mixed {
	return $value;
}

function esc_attr( string $text ): string {
	return $text;
}

function esc_html( string $text ): string {
	return $text;
}

function current_user_can( string $capability ): bool {
	return true;
}

function is_multisite(): bool {
	return false;
}
