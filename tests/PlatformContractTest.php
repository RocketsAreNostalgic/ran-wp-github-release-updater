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
}
