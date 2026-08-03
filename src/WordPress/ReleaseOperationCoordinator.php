<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

/**
 * Database-authoritative, per-target release coordination and bounded state.
 *
 * @internal
 */
final class ReleaseOperationCoordinator {
	public const MANAGED_STATE = 'managed_preflight';
	public const NATIVE_STATE  = 'native_state';

	private const SCHEMA = 1;

	/** @return ReleaseOperationClaim|\WP_Error */
	public function acquire( string $target, string $operation, int $ttl ) {
		if ( '' === $target || '' === $operation || strlen( $operation ) > 160 || $ttl < 1 || $ttl > 3600 ) {
			return $this->error( 'invalid', 'The release-operation claim is invalid.' );
		}
		$database = $this->database();
		if ( $database instanceof \WP_Error ) {
			return $database;
		}
		[ $wpdb, $table ] = $database;
		$name             = $this->name( $target );
		$tombstone        = $this->encode( $target, '', '', 0, 0, 0, array() );
		if ( null === $tombstone ) {
			return $this->error( 'state_too_large', 'The release-operation state cannot be encoded safely.' );
		}
		$inserted = $this->query(
			$wpdb,
			$this->prepare(
				$wpdb,
				"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$table,
				$name,
				$tombstone
			)
		);
		if ( false === $inserted ) {
			return $this->error( 'database', 'The release-operation authority row could not be initialized.' );
		}

		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$now = $this->databaseTime( $wpdb );
			$raw = $this->read( $wpdb, $table, $name );
			$row = is_string( $raw ) ? $this->decode( $raw, $target ) : null;
			if ( null === $row || null === $now ) {
				return $this->error( 'corrupt', 'The release-operation authority row is invalid.' );
			}
			if ( '' !== $row['owner'] && $row['expires_at'] > $now ) {
				return $this->error( 'busy', 'Another release operation owns this target.' );
			}
			if ( PHP_INT_MAX === $row['generation'] ) {
				return $this->error( 'generation', 'The release-operation generation is exhausted.' );
			}
			try {
				$owner = bin2hex( random_bytes( 32 ) );
			} catch ( \Throwable ) {
				return $this->error( 'entropy', 'A release-operation owner token could not be created.' );
			}
			$generation = $row['generation'] + 1;
			$expiresAt  = $now + $ttl;
			$next       = $this->encode(
				$target,
				$operation,
				$owner,
				$generation,
				$now,
				$expiresAt,
				$row['results']
			);
			if ( null === $next ) {
				return $this->error( 'state_too_large', 'The release-operation state exceeds its fixed bound.' );
			}
			if ( 1 === $this->compareAndSwap( $wpdb, $table, $name, $raw, $next ) ) {
				return new ReleaseOperationClaim(
					$table,
					$name,
					$target,
					$operation,
					$owner,
					$generation,
					$now,
					$expiresAt,
					$row['results'],
					$next
				);
			}
		}
		return $this->error( 'contended', 'The release-operation authority row changed concurrently.' );
	}

	/** @return ReleaseOperationClaim|\WP_Error */
	public function renew( ReleaseOperationClaim $claim, int $ttl ) {
		$database = $this->databaseFor( $claim );
		if ( $database instanceof \WP_Error || $ttl < 1 || $ttl > 3600 ) {
			return $database instanceof \WP_Error ? $database : $this->lost();
		}
		[ $wpdb, $table ] = $database;
		$now              = $this->databaseTime( $wpdb );
		if ( null === $now || $claim->expiresAt() <= $now ) {
			return $this->lost();
		}
		// MySQL reports zero changed rows when an UPDATE writes identical bytes.
		// Advance the expiry by at least one second so every successful renewal is
		// an observable exact-row CAS, even across rapid checkpoints in one DB
		// second.
		$expiresAt = max( $claim->expiresAt() + 1, $now + $ttl );
		$next      = $this->encode(
			$claim->target(),
			$claim->operation(),
			$claim->owner(),
			$claim->generation(),
			$claim->acquiredAt(),
			$expiresAt,
			$claim->results()
		);
		if ( null === $next ) {
			return $this->error( 'state_too_large', 'The release-operation state exceeds its fixed bound.' );
		}
		if ( 1 !== $this->compareAndSwap( $wpdb, $table, $claim->name(), $claim->raw(), $next ) ) {
			return $this->lost();
		}
		return new ReleaseOperationClaim(
			$table,
			$claim->name(),
			$claim->target(),
			$claim->operation(),
			$claim->owner(),
			$claim->generation(),
			$claim->acquiredAt(),
			$expiresAt,
			$claim->results(),
			$next
		);
	}

	/** @param array<string, mixed> $state @return true|\WP_Error */
	public function publish( ReleaseOperationClaim $claim, string $slot, array $state ): bool|\WP_Error {
		if ( ! in_array( $slot, array( self::MANAGED_STATE, self::NATIVE_STATE ), true ) ) {
			return $this->error( 'invalid', 'The release-operation state slot is invalid.' );
		}
		$database = $this->databaseFor( $claim );
		if ( $database instanceof \WP_Error ) {
			return $database;
		}
		[ $wpdb, $table ] = $database;
		$now              = $this->databaseTime( $wpdb );
		if ( null === $now || $claim->expiresAt() <= $now ) {
			return $this->lost();
		}
		$results          = $claim->results();
		$results[ $slot ] = $state;
		$tombstone        = $this->encode(
			$claim->target(),
			'',
			'',
			$claim->generation(),
			0,
			0,
			$results
		);
		if ( null === $tombstone ) {
			return $this->error( 'state_too_large', 'The release-operation result exceeds its fixed bound.' );
		}
		return 1 === $this->compareAndSwap( $wpdb, $table, $claim->name(), $claim->raw(), $tombstone )
			? true
			: $this->lost();
	}

	/** @return array<string, mixed> */
	public function state( string $target, string $slot ): array {
		if ( ! in_array( $slot, array( self::MANAGED_STATE, self::NATIVE_STATE ), true ) ) {
			return array();
		}
		$database = $this->database();
		if ( $database instanceof \WP_Error ) {
			return array();
		}
		[ $wpdb, $table ] = $database;
		$raw              = $this->read( $wpdb, $table, $this->name( $target ) );
		$row              = is_string( $raw ) ? $this->decode( $raw, $target ) : null;
		return null === $row ? array() : ( $row['results'][ $slot ] ?? array() );
	}

	public function release( ReleaseOperationClaim $claim ): bool {
		$database = $this->databaseFor( $claim );
		if ( $database instanceof \WP_Error ) {
			return false;
		}
		[ $wpdb, $table ] = $database;
		$tombstone        = $this->encode(
			$claim->target(),
			'',
			'',
			$claim->generation(),
			0,
			0,
			$claim->results()
		);
		if ( null === $tombstone ) {
			return false;
		}
		return 1 === $this->compareAndSwap( $wpdb, $table, $claim->name(), $claim->raw(), $tombstone );
	}

	/** @return array{object, string}|\WP_Error */
	private function database() {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $wpdb )
			|| ! is_callable( array( $wpdb, 'prepare' ) )
			|| ! is_callable( array( $wpdb, 'query' ) )
			|| ! is_callable( array( $wpdb, 'get_var' ) )
		) {
			return $this->error( 'database', 'WordPress database access is unavailable.' );
		}
		$table = $wpdb->options ?? null;
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$network = function_exists( 'get_current_network_id' ) ? get_current_network_id() : null;
			$main    = function_exists( 'get_main_site_id' ) ? get_main_site_id( $network ) : null;
			$table   = is_int( $main ) && is_callable( array( $wpdb, 'get_blog_prefix' ) )
				? $wpdb->get_blog_prefix( $main ) . 'options'
				: null;
		}
		if ( ! is_string( $table ) || 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $table ) ) {
			return $this->error( 'database', 'The WordPress authority table is invalid.' );
		}
		return array( $wpdb, $table );
	}

	/** @return array{object, string}|\WP_Error */
	private function databaseFor( ReleaseOperationClaim $claim ) {
		$database = $this->database();
		return is_array( $database ) && $database[1] !== $claim->table() ? $this->lost() : $database;
	}

	private function databaseTime( object $wpdb ): ?int {
		$now = $this->getVar( $wpdb, 'SELECT UNIX_TIMESTAMP()' );
		return is_string( $now ) && ctype_digit( $now ) ? (int) $now : ( is_int( $now ) ? $now : null );
	}

	private function read( object $wpdb, string $table, string $name ): mixed {
		return $this->getVar(
			$wpdb,
			$this->prepare( $wpdb, 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $table, $name )
		);
	}

	private function compareAndSwap(
		object $wpdb,
		string $table,
		string $name,
		string $observed,
		string $next
	): int|false {
		return $this->query(
			$wpdb,
			$this->prepare(
				$wpdb,
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s',
				$table,
				$next,
				$name,
				$observed
			)
		);
	}

	private function prepare( object $wpdb, string $query, mixed ...$arguments ): string {
		/** @var callable $prepare */
		$prepare  = array( $wpdb, 'prepare' );
		$prepared = $prepare( $query, ...$arguments );
		return is_string( $prepared ) ? $prepared : '';
	}

	private function query( object $wpdb, string $query ): int|false {
		/** @var callable $execute */
		$execute = array( $wpdb, 'query' );
		$result  = $execute( $query );
		return is_int( $result ) ? $result : false;
	}

	private function getVar( object $wpdb, string $query ): mixed {
		/** @var callable $getVar */
		$getVar = array( $wpdb, 'get_var' );
		return $getVar( $query );
	}

	private function name( string $target ): string {
		return 'ran_wp_gh_op_v1_' . substr( hash( 'sha256', $target ), 0, 32 );
	}

	/** @param array<string, array<string, mixed>> $results */
	private function encode(
		string $target,
		string $operation,
		string $owner,
		int $generation,
		int $acquiredAt,
		int $expiresAt,
		array $results
	): ?string {
		$encoded = wp_json_encode(
			array(
				'schema'      => self::SCHEMA,
				'target'      => hash( 'sha256', $target ),
				'operation'   => $operation,
				'owner'       => $owner,
				'generation'  => $generation,
				'acquired_at' => $acquiredAt,
				'expires_at'  => $expiresAt,
				'results'     => $results,
			),
			JSON_UNESCAPED_SLASHES
		);
		return is_string( $encoded ) && strlen( $encoded ) <= 65535 ? $encoded : null;
	}

	/** @return array{owner: string, generation: int, expires_at: int, results: array<string, array<string, mixed>>}|null */
	private function decode( string $raw, string $target ): ?array {
		$row    = strlen( $raw ) <= 65535 ? json_decode( $raw, true ) : null;
		$idle   = '' === ( $row['owner'] ?? null )
			&& '' === ( $row['operation'] ?? null )
			&& 0 === ( $row['acquired_at'] ?? null )
			&& 0 === ( $row['expires_at'] ?? null );
		$active = is_string( $row['owner'] ?? null )
			&& 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $row['owner'] )
			&& is_string( $row['operation'] ?? null )
			&& '' !== $row['operation']
			&& strlen( $row['operation'] ) <= 160
			&& is_int( $row['acquired_at'] ?? null )
			&& $row['acquired_at'] > 0
			&& is_int( $row['expires_at'] ?? null )
			&& $row['expires_at'] > $row['acquired_at'];
		if ( ! is_array( $row )
			|| self::SCHEMA !== ( $row['schema'] ?? null )
			|| ! is_string( $row['target'] ?? null )
			|| ! hash_equals( hash( 'sha256', $target ), $row['target'] )
			|| ! is_string( $row['operation'] ?? null )
			|| ! is_string( $row['owner'] ?? null )
			|| ! is_int( $row['generation'] ?? null )
			|| ! is_int( $row['acquired_at'] ?? null )
			|| ! is_int( $row['expires_at'] ?? null )
			|| ! is_array( $row['results'] ?? null )
			|| $row['generation'] < 0
			|| ( ! $idle && ! $active )
		) {
			return null;
		}
		foreach ( $row['results'] as $slot => $state ) {
			if ( ! in_array( $slot, array( self::MANAGED_STATE, self::NATIVE_STATE ), true ) || ! is_array( $state ) ) {
				return null;
			}
		}
		return array(
			'owner'      => $row['owner'],
			'generation' => $row['generation'],
			'expires_at' => $row['expires_at'],
			'results'    => $row['results'],
		);
	}

	private function lost(): \WP_Error {
		return $this->error( 'fence_lost', 'The release-operation ownership fence was lost.' );
	}

	private function error( string $suffix, string $message ): \WP_Error {
		return new \WP_Error( 'github_updater_operation_' . $suffix, $message );
	}
}
