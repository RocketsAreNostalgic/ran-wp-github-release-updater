<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class PlatformContractTest extends TestCase {
	public function testComposerDeclaresTheZipRuntimeRequirement(): void {
		$composer = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/composer.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		self::assertSame( '*', $composer['require']['ext-zip'] ?? null );
	}

	public function testReadmeExamplesMatchTheBootstrapAndSelectedRuntimeContract(): void {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/README.md' );

		self::assertStringContainsString(
			'composer require ran/wp-github-release-updater:^2.0@beta',
			$readme
		);
		self::assertStringContainsString(
			'At declaration time, only the returned bootstrap facade is',
			$readme
		);
		self::assertStringContainsString(
			'a callback at `PHP_INT_MAX`',
			$readme
		);
		self::assertStringContainsString(
			'use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;',
			$readme
		);
		self::assertStringContainsString(
			'providerRepositoryId: \'123456789\'',
			$readme
		);
		self::assertStringContainsString(
			'providerRepositoryId: \'987654321\'',
			$readme
		);
	}
}
