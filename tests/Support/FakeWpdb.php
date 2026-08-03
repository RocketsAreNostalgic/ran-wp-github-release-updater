<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Exact option-row SQL harness used by release-operation unit tests.
 */
final class FakeWpdb {
	public string $options = 'wp_options';

	public int $now = 1000;

	/** @var array<string, array<string, string>> */
	public array $rows = array();

	public function prepare( string $query, mixed ...$arguments ): string {
		while ( str_contains( $query, '%i' ) ) {
			$identifier = array_shift( $arguments );
			$query      = preg_replace( '/%i/', (string) $identifier, $query, 1 ) ?? '';
		}
		return '__ran_fake_sql__' . base64_encode(
			(string) json_encode( array( $query, $arguments ), JSON_UNESCAPED_SLASHES )
		);
	}

	public function get_blog_prefix( int $blogId ): string {
		return 1 === $blogId ? 'wp_' : 'wp_' . $blogId . '_';
	}

	public function get_var( string $statement ): mixed {
		if ( 'SELECT UNIX_TIMESTAMP()' === $statement ) {
			return $this->now;
		}
		[ $query, $arguments ] = $this->statement( $statement );
		if ( 1 !== preg_match( '/\ASELECT option_value FROM ([A-Za-z0-9_]+)/', $query, $match ) ) {
			return null;
		}
		$name = $arguments[0] ?? null;
		return is_string( $name ) ? ( $this->rows[ $match[1] ][ $name ] ?? null ) : null;
	}

	public function query( string $statement ): int|false {
		[ $query, $arguments ] = $this->statement( $statement );
		if ( 1 === preg_match( '/\AINSERT IGNORE INTO ([A-Za-z0-9_]+)/', $query, $match ) ) {
			[ $name, $value ] = $arguments;
			if ( isset( $this->rows[ $match[1] ][ $name ] ) ) {
				return 0;
			}
			$this->rows[ $match[1] ][ $name ] = $value;
			return 1;
		}
		if ( 1 === preg_match( '/\AUPDATE ([A-Za-z0-9_]+)/', $query, $match ) ) {
			[ $next, $name, $observed ] = $arguments;
			if ( ( $this->rows[ $match[1] ][ $name ] ?? null ) !== $observed ) {
				return 0;
			}
			if ( $next === $observed ) {
				return 0;
			}
			$this->rows[ $match[1] ][ $name ] = $next;
			return 1;
		}

		return false;
	}

	/** @return array{string, list<mixed>} */
	private function statement( string $statement ): array {
		$prefix = '__ran_fake_sql__';
		if ( ! str_starts_with( $statement, $prefix ) ) {
			return array( $statement, array() );
		}
		$decoded = json_decode( (string) base64_decode( substr( $statement, strlen( $prefix ) ), true ), true );
		return is_array( $decoded ) && is_string( $decoded[0] ?? null ) && is_array( $decoded[1] ?? null )
			? array( $decoded[0], array_values( $decoded[1] ) )
			: array( '', array() );
	}
}
