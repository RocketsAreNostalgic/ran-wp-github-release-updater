<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Small in-memory WordPress hook and state harness for package tests.
 */
final class WordPressState {

	/** @var array<string, array<int, list<array{callback: callable, accepted_args: int}>>> */
	public static array $actions = array();

	/** @var array<string, array<int, list<array{callback: callable, accepted_args: int}>>> */
	public static array $filters = array();

	/** @var array<string, int> */
	public static array $didActions = array();

	/** @var array<string, mixed> */
	public static array $siteTransients = array();

	/** @var array<string, int> */
	public static array $siteTransientExpirations = array();

	/** @var list<array{url: string, args: array<string, mixed>}> */
	public static array $httpRequests = array();

	/** @var list<mixed> */
	public static array $httpResponses = array();

	/** @var array<string, array<string, mixed>> */
	public static array $pluginData = array();

	/** @var array<string, string> */
	public static array $pluginBasenames = array();

	/** @var list<string> */
	public static array $deletedFiles = array();

	public static bool $currentUserCan = true;

	public static bool $multisite = false;

	public static int $currentNetworkId = 1;

	public static int $mainSiteId = 1;

	public static string $screenBase = 'plugins';

	public static function reset(): void {
		self::$actions                  = array();
		self::$filters                  = array();
		self::$didActions               = array();
		self::$siteTransients           = array();
		self::$siteTransientExpirations = array();
		self::$httpRequests             = array();
		self::$httpResponses            = array();
		self::$pluginData               = array();
		self::$pluginBasenames          = array();
		self::$deletedFiles             = array();
		self::$currentUserCan           = true;
		self::$multisite                = false;
		self::$currentNetworkId         = 1;
		self::$mainSiteId               = 1;
		self::$screenBase               = 'plugins';
		$GLOBALS['wpdb']                = new FakeWpdb();
		unset( $GLOBALS['wp_filesystem'] );
		$_GET = array();
	}

	public static function addAction(
		string $hook,
		callable $callback,
		int $priority = 10,
		int $acceptedArgs = 1
	): bool {
		self::$actions[ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => max( 0, $acceptedArgs ),
		);

		return true;
	}

	public static function addFilter(
		string $hook,
		callable $callback,
		int $priority = 10,
		int $acceptedArgs = 1
	): bool {
		self::$filters[ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => max( 0, $acceptedArgs ),
		);

		return true;
	}

	public static function removeAction(
		string $hook,
		callable $callback,
		int $priority = 10
	): bool {
		return self::removeHook( self::$actions, $hook, $callback, $priority );
	}

	public static function removeFilter(
		string $hook,
		callable $callback,
		int $priority = 10
	): bool {
		return self::removeHook( self::$filters, $hook, $callback, $priority );
	}

	public static function didAction( string $hook ): int {
		return self::$didActions[ $hook ] ?? 0;
	}

	public static function doAction( string $hook, mixed ...$args ): void {
		self::$didActions[ $hook ] = self::didAction( $hook ) + 1;
		foreach ( self::callbacks( self::$actions, $hook ) as $entry ) {
			( $entry['callback'] )( ...array_slice( $args, 0, $entry['accepted_args'] ) );
		}
	}

	public static function applyFilters( string $hook, mixed $value, mixed ...$args ): mixed {
		foreach ( self::callbacks( self::$filters, $hook ) as $entry ) {
			$arguments = array_slice(
				array_merge( array( $value ), $args ),
				0,
				$entry['accepted_args']
			);
			$value     = ( $entry['callback'] )( ...$arguments );
		}

		return $value;
	}

	public static function hookCount( string $hook ): int {
		$count = 0;
		foreach ( array( self::$actions, self::$filters ) as $hooks ) {
			foreach ( $hooks[ $hook ] ?? array() as $entries ) {
				$count += count( $entries );
			}
		}

		return $count;
	}

	/**
	 * @param array<string, array<int, list<array{callback: callable, accepted_args: int}>>> $hooks
	 * @return list<array{callback: callable, accepted_args: int}>
	 */
	private static function callbacks( array $hooks, string $hook ): array {
		$priorities = $hooks[ $hook ] ?? array();
		ksort( $priorities, SORT_NUMERIC );
		$callbacks = array();
		foreach ( $priorities as $entries ) {
			foreach ( $entries as $entry ) {
				$callbacks[] = $entry;
			}
		}

		return $callbacks;
	}

	/**
	 * @param array<string, array<int, list<array{callback: callable, accepted_args: int}>>> $hooks
	 */
	private static function removeHook(
		array &$hooks,
		string $hook,
		callable $callback,
		int $priority
	): bool {
		$entries = $hooks[ $hook ][ $priority ] ?? array();
		foreach ( $entries as $index => $entry ) {
			if ( $entry['callback'] !== $callback ) {
				continue;
			}

			unset( $hooks[ $hook ][ $priority ][ $index ] );
			$hooks[ $hook ][ $priority ] = array_values( $hooks[ $hook ][ $priority ] );
			return true;
		}

		return false;
	}
}
