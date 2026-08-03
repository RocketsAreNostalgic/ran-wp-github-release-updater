<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseAsset;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\Repository;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\VerifiedArtifact;
use RAN\WPGitHubReleaseUpdater\V1\Http\WordPressTemporaryFileFactory;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\CandidateValidation;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\PackageIdentityTarget;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleasePackageIdentityValidator;
use ReflectionProperty;
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

final class ReleasePackageIdentityValidatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressState::reset();
	}

	public function testCanonicalVerdictParserRehydratesAPrerelease(): void {
		$validation = CandidateValidation::fromArray( $this->serializedVerdict() );

		self::assertInstanceOf( CandidateValidation::class, $validation );
		self::assertSame( $this->serializedVerdict(), $validation->toArray() );
	}

	/**
	 * @dataProvider inconsistentSerializedIdentityProvider
	 * @param array<string, mixed> $mutation
	 */
	public function testCanonicalVerdictParserRejectsInconsistentIdentity( array $mutation ): void {
		$verdict = array_replace_recursive( $this->serializedVerdict(), $mutation );

		self::assertNull( CandidateValidation::fromArray( $verdict ) );
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function inconsistentSerializedIdentityProvider(): array {
		return array(
			'release tag and version disagree'    => array(
				array( 'release_version' => '1.2.3-beta.1' ),
			),
			'identity tag and verdict disagree'   => array(
				array( 'identity' => array( 'tag' => 'v1.2.3-beta.1' ) ),
			),
			'ready verdict lacks PHP requirement' => array(
				array( 'requires_php' => null ),
			),
			'invalid WordPress requirement'       => array(
				array( 'requires_wordpress' => 'latest' ),
			),
		);
	}

	public function testPluginHeaderUsesTwoPartPatchZeroShorthand(): void {
		$descriptor = $this->descriptor();
		$target     = $this->pluginTarget();

		$result = ( new ReleasePackageIdentityValidator() )->validate(
			$this->archive( array( 'example-plugin/example-plugin.php' => $this->pluginHeader( '2.1' ) ) ),
			$descriptor,
			$target
		);

		self::assertTrue( $result->isReady() );
		self::assertSame( 'v2.1.0', $result->releaseTag() );
		self::assertSame( '2.1.0', $result->releaseVersion() );
		self::assertSame( 'newer', $result->relationshipTo( '2.0.9' ) );
		self::assertSame( 'same', $result->relationshipTo( '2.1' ) );
		self::assertSame( 'older', $result->relationshipTo( '2.1.1' ) );
		self::assertSame( 'invalid', $result->relationshipTo( 'not-a-version+' ) );
		self::assertSame( '2.1', $result->packageHeaderVersion() );
		self::assertSame( 'plugin', $result->identity()['package_type'] );
	}

	public function testThemeRootStyleSheetIsValidatedWithoutPluginParsing(): void {
		$descriptor = $this->descriptor();
		$target     = PackageIdentityTarget::forTheme(
			'example-theme',
			'https://github.com/RocketsAreNostalgic/example-plugin'
		);
		self::assertInstanceOf( PackageIdentityTarget::class, $target );

		$result = ( new ReleasePackageIdentityValidator() )->validate(
			$this->archive( array( 'example-theme/style.css' => $this->themeHeader() ) ),
			$descriptor,
			$target
		);

		self::assertTrue( $result->isReady() );
		self::assertSame( 'example-theme/style.css', $result->identity()['header_file'] );
	}

	public function testMissingZipExtensionProducesAnExactPlatformDiagnostic(): void {
		$validator = new ReleasePackageIdentityValidator();
		$available = new ReflectionProperty( $validator, 'zipAvailable' );
		$available->setValue( $validator, false );

		$result = $validator->validate(
			$this->archive( array( 'example-plugin/example-plugin.php' => $this->pluginHeader() ) ),
			$this->descriptor(),
			$this->pluginTarget()
		);

		self::assertFalse( $result->isReady() );
		self::assertSame( CandidateValidation::ZIP_EXTENSION_UNAVAILABLE, $result->code() );
		self::assertInstanceOf( CandidateValidation::class, CandidateValidation::fromArray( $result->toArray() ) );

		$prospective = $validator->validateProspective(
			$this->archive( array( 'example-plugin/example-plugin.php' => $this->pluginHeader() ) ),
			$this->descriptor(),
			'plugin',
			'https://github.com/RocketsAreNostalgic/example-plugin'
		);

		self::assertInstanceOf( \WP_Error::class, $prospective );
		self::assertSame( CandidateValidation::ZIP_EXTENSION_UNAVAILABLE, $prospective->get_error_code() );
		self::assertStringContainsString( 'PHP ext-zip platform requirement is unavailable', $prospective->get_error_message() );
	}

	/**
	 * @dataProvider updateUriProvider
	 */
	public function testExpectedUpdateUriIsRequiredAndCaseInsensitive(
		?string $header,
		string $expectedCode
	): void {
		$descriptor = $this->descriptor();
		$target     = $this->pluginTarget();
		$contents   = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 2.1.0\n"
			. ( null === $header ? '' : 'Update URI: ' . $header . "\n" )
			. "Requires PHP: 8.0\nRequires at least: 6.5\n"
			. "*/\n";

		$result = ( new ReleasePackageIdentityValidator() )->validate(
			$this->archive( array( 'example-plugin/example-plugin.php' => $contents ) ),
			$descriptor,
			$target
		);

		self::assertSame( $expectedCode, $result->code() );
	}

	/**
	 * @return array<string, array{0: ?string, 1: string}>
	 */
	public static function updateUriProvider(): array {
		return array(
			'canonical'      => array(
				'https://github.com/RocketsAreNostalgic/example-plugin',
				'release_identity_verified',
			),
			'case and slash' => array(
				'https://GITHUB.com/rocketsarenostalgic/EXAMPLE-plugin/',
				'release_identity_verified',
			),
			'missing'        => array( null, CandidateValidation::UPDATE_URI_MISSING ),
			'wrong'          => array(
				'https://github.com/RocketsAreNostalgic/other-plugin',
				CandidateValidation::UPDATE_URI_INVALID,
			),
		);
	}

	public function testThemeExpectedUpdateUriFailsClosedWhenMissingOrWrong(): void {
		$descriptor = $this->descriptor();
		$target     = PackageIdentityTarget::forTheme(
			'example-theme',
			'https://github.com/RocketsAreNostalgic/example-theme'
		);
		self::assertInstanceOf( PackageIdentityTarget::class, $target );

		foreach (
			array(
				"/*\nTheme Name: Example\nVersion: 2.1.0\nRequires PHP: 8.0\nRequires at least: 6.5\n*/" => CandidateValidation::UPDATE_URI_MISSING,
				"/*\nTheme Name: Example\nVersion: 2.1.0\nUpdate URI: https://github.com/example/wrong\nRequires PHP: 8.0\nRequires at least: 6.5\n*/" => CandidateValidation::UPDATE_URI_INVALID,
			) as $contents => $expectedCode
		) {
			$result = ( new ReleasePackageIdentityValidator() )->validate(
				$this->archive( array( 'example-theme/style.css' => $contents ) ),
				$descriptor,
				$target
			);
			self::assertSame( $expectedCode, $result->code() );
		}
	}

	public function testCompleteCanonicalPrereleaseVersionIsAcceptedInHeader(): void {
		$descriptor = $this->descriptor( '2.1.0-beta.2', true );
		$target     = $this->pluginTarget();

		$result = ( new ReleasePackageIdentityValidator() )->validate(
			$this->archive(
				array(
					'example-plugin/example-plugin.php' => $this->pluginHeader( '2.1.0-beta.2' ),
				)
			),
			$descriptor,
			$target
		);

		self::assertTrue( $result->isReady() );
		self::assertSame( '2.1.0-beta.2', $result->releaseVersion() );
	}

	public function testProspectivePluginDiscoversOneEligibleTopLevelHeader(): void {
		$result = ( new ReleasePackageIdentityValidator() )->validateProspective(
			$this->archive(
				array(
					'example-plugin/index.php'          => '<?php // Silence is golden.',
					'example-plugin/example-plugin.php' => $this->pluginHeader(),
					'example-plugin/includes/another-plugin.php' => $this->pluginHeader(),
				)
			),
			$this->descriptor(),
			PackageIdentityTarget::PLUGIN,
			'https://github.com/RocketsAreNostalgic/example-plugin'
		);

		self::assertInstanceOf( CandidateValidation::class, $result );
		self::assertTrue( $result->isReady() );
		self::assertSame( 'example-plugin/example-plugin.php', $result->identity()['header_file'] );
		self::assertSame( '8.0', $result->requiresPhp() );
		self::assertSame( '6.5', $result->requiresWordPress() );
	}

	public function testProspectivePluginRejectsMissingAndAmbiguousTopLevelHeaders(): void {
		$validator  = new ReleasePackageIdentityValidator();
		$descriptor = $this->descriptor();
		$missing    = $validator->validateProspective(
			$this->archive( array( 'example-plugin/index.php' => '<?php // No plugin header.' ) ),
			$descriptor,
			PackageIdentityTarget::PLUGIN,
			'https://github.com/RocketsAreNostalgic/example-plugin'
		);
		$ambiguous  = $validator->validateProspective(
			$this->archive(
				array(
					'example-plugin/first.php'  => $this->pluginHeader(),
					'example-plugin/second.php' => $this->pluginHeader(),
				)
			),
			$descriptor,
			PackageIdentityTarget::PLUGIN,
			'https://github.com/RocketsAreNostalgic/example-plugin'
		);

		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( CandidateValidation::HEADER_MISSING, $missing->get_error_code() );
		self::assertInstanceOf( \WP_Error::class, $ambiguous );
		self::assertSame( CandidateValidation::HEADER_AMBIGUOUS, $ambiguous->get_error_code() );
	}

	public function testProspectiveThemeUsesOnlyRootStyleSheet(): void {
		$result = ( new ReleasePackageIdentityValidator() )->validateProspective(
			$this->archive(
				array(
					'example-theme/style.css'      => $this->themeHeader(),
					'example-theme/inc/plugin.php' => $this->pluginHeader(),
				)
			),
			$this->descriptor(),
			PackageIdentityTarget::THEME,
			'https://github.com/RocketsAreNostalgic/example-plugin'
		);

		self::assertInstanceOf( CandidateValidation::class, $result );
		self::assertTrue( $result->isReady() );
		self::assertSame( 'theme', $result->identity()['package_type'] );
		self::assertSame( 'example-theme/style.css', $result->identity()['header_file'] );
	}

	public function testPackageNameAndCompatibilityHeadersFailClosed(): void {
		$validator  = new ReleasePackageIdentityValidator();
		$descriptor = $this->descriptor();
		$target     = $this->pluginTarget();
		$cases      = array(
			'missing plugin name'           => array(
				str_replace( 'Plugin Name: Example Plugin' . "\n", '', $this->pluginHeader() ),
				CandidateValidation::HEADER_MISSING,
			),
			'missing compatibility'         => array(
				str_replace( 'Requires PHP: 8.0' . "\n", '', $this->pluginHeader() ),
				CandidateValidation::COMPATIBILITY_MISSING,
			),
			'invalid PHP requirement'       => array(
				$this->pluginHeader( '2.1.0', requiresPhp: 'v8.0' ),
				CandidateValidation::COMPATIBILITY_INVALID,
			),
			'invalid WordPress requirement' => array(
				$this->pluginHeader( '2.1.0', requiresWordPress: 'latest' ),
				CandidateValidation::COMPATIBILITY_INVALID,
			),
			'incompatible PHP'              => array(
				$this->pluginHeader( '2.1.0', requiresPhp: '99.0' ),
				CandidateValidation::RELEASE_INCOMPATIBLE,
			),
			'incompatible WordPress'        => array(
				$this->pluginHeader( '2.1.0', requiresWordPress: '99.0' ),
				CandidateValidation::RELEASE_INCOMPATIBLE,
			),
		);

		foreach ( $cases as $case ) {
			$result = $validator->validate(
				$this->archive( array( 'example-plugin/example-plugin.php' => $case[0] ) ),
				$descriptor,
				$target
			);
			self::assertSame( $case[1], $result->code() );
		}
	}

	public function testProspectiveThemeRequiresThemeName(): void {
		$result = ( new ReleasePackageIdentityValidator() )->validateProspective(
			$this->archive(
				array(
					'example-theme/style.css' => str_replace(
						'Theme Name: Example Theme' . "\n",
						'',
						$this->themeHeader()
					),
				)
			),
			$this->descriptor(),
			PackageIdentityTarget::THEME,
			'https://github.com/RocketsAreNostalgic/example-plugin'
		);

		self::assertInstanceOf( CandidateValidation::class, $result );
		self::assertSame( CandidateValidation::HEADER_MISSING, $result->code() );
	}

	/**
	 * @dataProvider unsafeArchiveShapeProvider
	 * @param array<string, string> $entries
	 */
	public function testUnsafeDuplicateAndAmbiguousArchiveShapesFailClosed(
		array $entries,
		string $expectedCode
	): void {
		$descriptor = $this->descriptor();
		$target     = $this->pluginTarget();

		$result = ( new ReleasePackageIdentityValidator() )->validate(
			$this->archive( $entries ),
			$descriptor,
			$target
		);

		self::assertSame( $expectedCode, $result->code() );
	}

	/**
	 * @return array<string, array{array<string, string>, string}>
	 */
	public static function unsafeArchiveShapeProvider(): array {
		$header = "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 2.1.0\nUpdate URI: https://github.com/RocketsAreNostalgic/example-plugin\nRequires PHP: 8.0\nRequires at least: 6.5\n*/";

		return array(
			'parent traversal' => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/../escape.php'      => 'unsafe',
				),
				CandidateValidation::ARCHIVE_PATH_UNSAFE,
			),
			'second root'      => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'other-root/readme.txt'             => 'ambiguous',
				),
				CandidateValidation::ARCHIVE_ROOT_INVALID,
			),
			'case collision'   => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/readme.txt'         => 'first',
					'example-plugin/README.txt'         => 'second',
				),
				CandidateValidation::ARCHIVE_PATH_DUPLICATE,
			),
			'entry collision'  => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/EXAMPLE-PLUGIN.PHP' => $header,
				),
				CandidateValidation::ARCHIVE_ENTRY_DUPLICATE,
			),
			'root-level file'  => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin'                    => 'not a directory',
				),
				CandidateValidation::ARCHIVE_ROOT_INVALID,
			),
			'non-ASCII path'   => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/résumé.txt'         => 'normalization alias',
				),
				CandidateValidation::ARCHIVE_PATH_UNSAFE,
			),
			'trailing dot'     => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/readme.'            => 'platform alias',
				),
				CandidateValidation::ARCHIVE_PATH_UNSAFE,
			),
			'trailing space'   => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/readme '            => 'platform alias',
				),
				CandidateValidation::ARCHIVE_PATH_UNSAFE,
			),
			'reserved device'  => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/CON.txt'            => 'platform alias',
				),
				CandidateValidation::ARCHIVE_PATH_UNSAFE,
			),
			'reserved port'    => array(
				array(
					'example-plugin/example-plugin.php' => $header,
					'example-plugin/lPt9.log'           => 'platform alias',
				),
				CandidateValidation::ARCHIVE_PATH_UNSAFE,
			),
		);
	}

	public function testSymlinkEntryIsRejectedAsUnsafe(): void {
		$descriptor = $this->descriptor();
		$target     = $this->pluginTarget();
		$artifact   = $this->archive(
			array(
				'example-plugin/example-plugin.php' => $this->pluginHeader(),
				'example-plugin/link.php'           => '../outside.php',
			),
			array( 'example-plugin/link.php' )
		);

		$result = ( new ReleasePackageIdentityValidator() )->validate(
			$artifact,
			$descriptor,
			$target
		);

		self::assertSame( CandidateValidation::ARCHIVE_PATH_UNSAFE, $result->code() );
	}

	public function testExtractionSizeAcceptsExactLimitAndRejectsOneByteOver(): void {
		$descriptor = $this->descriptor();
		$target     = $this->pluginTarget();
		$header     = $this->pluginHeader();

		$exact = ( new ReleasePackageIdentityValidator() )->validate(
			$this->archiveWithDeclaredTotalSize(
				ReleasePackageIdentityValidator::MAX_EXTRACTION_SPACE,
				$header
			),
			$descriptor,
			$target
		);
		$over  = ( new ReleasePackageIdentityValidator() )->validate(
			$this->archiveWithDeclaredTotalSize(
				ReleasePackageIdentityValidator::MAX_EXTRACTION_SPACE + 1,
				$header
			),
			$descriptor,
			$target
		);

		self::assertTrue( $exact->isReady() );
		self::assertSame( CandidateValidation::ARCHIVE_TOO_LARGE, $over->code() );
	}

	public function testArchiveEntryCountIsBoundedBeforeInventory(): void {
		$descriptor = $this->descriptor();
		$target     = $this->pluginTarget();

		$result = ( new ReleasePackageIdentityValidator() )->validate(
			$this->archiveWithEntryCount( 10001 ),
			$descriptor,
			$target
		);

		self::assertSame( CandidateValidation::ARCHIVE_ENTRY_LIMIT, $result->code() );
	}

	public function testMismatchIsTypedDisplaySafeAndIncludesOnlySafeValues(): void {
		$descriptor = $this->descriptor();
		$target     = $this->pluginTarget();

		$result = ( new ReleasePackageIdentityValidator() )->validate(
			$this->archive( array( 'example-plugin/example-plugin.php' => $this->pluginHeader( '2.0.0' ) ) ),
			$descriptor,
			$target
		);

		self::assertFalse( $result->isReady() );
		self::assertSame( CandidateValidation::VERSION_MISMATCH, $result->code() );
		self::assertSame( '2.0.0', $result->packageHeaderVersion() );
		self::assertInstanceOf( CandidateValidation::class, CandidateValidation::fromArray( $result->toArray() ) );
	}

	/**
	 * @dataProvider invalidArchiveProvider
	 */
	public function testMissingInvalidAndUnreadableArchivesFailClosed(
		array $entries,
		string $expected
	): void {
		$descriptor = $this->descriptor();
		$target     = $this->pluginTarget();
		$result     = ( new ReleasePackageIdentityValidator() )->validate(
			$this->archive( $entries ),
			$descriptor,
			$target
		);

		self::assertFalse( $result->isReady() );
		self::assertSame( $expected, $result->code() );
	}

	/**
	 * @return array<string, array{array<string, string>, string}>
	 */
	public function invalidArchiveProvider(): array {
		return array(
			'missing header file'    => array( array( 'example-plugin/readme.txt' => 'No header.' ), CandidateValidation::HEADER_MISSING ),
			'missing version header' => array(
				array(
					'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nUpdate URI: https://github.com/RocketsAreNostalgic/example-plugin\nRequires PHP: 8.0\nRequires at least: 6.5\n*/",
				),
				CandidateValidation::HEADER_MISSING,
			),
			'invalid version header' => array(
				array(
					'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: v2.1.0\nUpdate URI: https://github.com/RocketsAreNostalgic/example-plugin\nRequires PHP: 8.0\nRequires at least: 6.5\n*/",
				),
				CandidateValidation::HEADER_INVALID,
			),
		);
	}

	private function descriptor(
		string $version = '2.1.0',
		bool $prerelease = false
	): ArtifactDescriptor {
		$repository = Repository::fromString( 'RocketsAreNostalgic/example-plugin' );
		self::assertInstanceOf( Repository::class, $repository );
		$query = new ReleaseQuery( $repository, ReleaseQuery::STABLE, '8.2', '6.5' );
		return new ArtifactDescriptor(
			$query,
			$repository,
			42,
			'v' . $version,
			$version,
			str_repeat( '1', 40 ),
			$prerelease,
			'https://github.com/RocketsAreNostalgic/example-plugin/releases/tag/v' . $version,
			new ReleaseAsset( 101, 'example-plugin-' . $version . '.zip', 123, str_repeat( 'a', 64 ) ),
			false
		);
	}

	private function pluginTarget(): PackageIdentityTarget {
		$target = PackageIdentityTarget::forPlugin(
			'example-plugin',
			'example-plugin.php',
			'https://github.com/RocketsAreNostalgic/example-plugin'
		);
		self::assertInstanceOf( PackageIdentityTarget::class, $target );

		return $target;
	}

	private function pluginHeader(
		string $version = '2.1.0',
		string $updateUri = 'https://github.com/RocketsAreNostalgic/example-plugin',
		string $requiresPhp = '8.0',
		string $requiresWordPress = '6.5'
	): string {
		return "<?php\n/*\nPlugin Name: Example Plugin\nVersion: {$version}\nUpdate URI: {$updateUri}\nRequires PHP: {$requiresPhp}\nRequires at least: {$requiresWordPress}\n*/";
	}

	private function themeHeader(
		string $version = '2.1.0',
		string $updateUri = 'https://github.com/RocketsAreNostalgic/example-plugin',
		string $requiresPhp = '8.0',
		string $requiresWordPress = '6.5'
	): string {
		return "/*\nTheme Name: Example Theme\nVersion: {$version}\nUpdate URI: {$updateUri}\nRequires PHP: {$requiresPhp}\nRequires at least: {$requiresWordPress}\n*/";
	}

	/**
	 * @param array<string, string> $entries
	 * @param list<string>          $symlinks
	 */
	private function archive( array $entries, array $symlinks = array() ): VerifiedArtifact {
		$temporaryFiles = new WordPressTemporaryFileFactory();
		$path           = $temporaryFiles->create( 'candidate.zip' );
		self::assertIsString( $path );
		$zip = new \ZipArchive();
		self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		foreach ( $entries as $name => $contents ) {
			self::assertTrue( $zip->addFromString( $name, $contents ) );
		}
		foreach ( $symlinks as $name ) {
			self::assertTrue(
				$zip->setExternalAttributesName(
					$name,
					\ZipArchive::OPSYS_UNIX,
					0120777 << 16
				)
			);
		}
		$zip->close();
		return $this->verifiedArtifact( $path, $temporaryFiles );
	}

	private function archiveWithDeclaredTotalSize( int $totalSize, string $header ): VerifiedArtifact {
		$temporaryFiles = new WordPressTemporaryFileFactory();
		$path           = $temporaryFiles->create( 'candidate-sized.zip' );
		self::assertIsString( $path );
		$zip = new \ZipArchive();
		self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		self::assertTrue( $zip->addFromString( 'example-plugin/example-plugin.php', $header ) );
		self::assertTrue( $zip->addFromString( 'example-plugin/payload.bin', 'x' ) );
		$zip->close();
		$declaredPayloadSize = $totalSize - strlen( $header );
		self::assertGreaterThan( 0, $declaredPayloadSize );
		$bytes = file_get_contents( $path );
		self::assertIsString( $bytes );
		$bytes = $this->replaceDeclaredSize(
			$bytes,
			'example-plugin/payload.bin',
			$declaredPayloadSize
		);
		self::assertSame( strlen( $bytes ), file_put_contents( $path, $bytes ) );

		return $this->verifiedArtifact( $path, $temporaryFiles );
	}

	private function archiveWithEntryCount( int $entryCount ): VerifiedArtifact {
		$temporaryFiles = new WordPressTemporaryFileFactory();
		$path           = $temporaryFiles->create( 'candidate-entries.zip' );
		self::assertIsString( $path );
		$zip = new \ZipArchive();
		self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		self::assertTrue(
			$zip->addFromString(
				'example-plugin/example-plugin.php',
				$this->pluginHeader()
			)
		);
		for ( $index = 1; $index < $entryCount; ++$index ) {
			if ( ! $zip->addEmptyDir( 'example-plugin/dir-' . $index ) ) {
				self::fail( 'Unable to create the bounded-entry ZIP fixture.' );
			}
		}
		$zip->close();

		return $this->verifiedArtifact( $path, $temporaryFiles );
	}

	private function replaceDeclaredSize( string $bytes, string $name, int $size ): string {
		$offset = 0;
		$local  = false;
		while ( true ) {
			$offset = strpos( $bytes, "PK\x03\x04", $offset );
			if ( false === $offset ) {
				break;
			}
			$nameLength = unpack( 'v', substr( $bytes, $offset + 26, 2 ) )[1];
			if ( substr( $bytes, $offset + 30, $nameLength ) === $name ) {
				$bytes = substr_replace( $bytes, pack( 'V', $size ), $offset + 22, 4 );
				$local = true;
				break;
			}
			$offset += 4;
		}
		$offset  = 0;
		$central = false;
		while ( true ) {
			$offset = strpos( $bytes, "PK\x01\x02", $offset );
			if ( false === $offset ) {
				break;
			}
			$nameLength = unpack( 'v', substr( $bytes, $offset + 28, 2 ) )[1];
			if ( substr( $bytes, $offset + 46, $nameLength ) === $name ) {
				$bytes   = substr_replace( $bytes, pack( 'V', $size ), $offset + 24, 4 );
				$central = true;
				break;
			}
			$offset += 4;
		}
		self::assertTrue( $local && $central );

		return $bytes;
	}

	private function verifiedArtifact(
		string $path,
		WordPressTemporaryFileFactory $temporaryFiles
	): VerifiedArtifact {
		$identity = VerifiedArtifact::fileIdentity( $path );
		self::assertIsArray( $identity );
		return new VerifiedArtifact(
			$path,
			hash_file( 'sha256', $path ),
			$temporaryFiles,
			$identity
		);
	}

	/**
	 * @return array{
	 *     state: string,
	 *     code: string,
	 *     release_tag: string,
	 *     release_version: string,
	 *     package_header_version: string,
	 *     requires_php: string,
	 *     requires_wordpress: string,
	 *     identity: array{
	 *         release_id: int,
	 *         tag: string,
	 *         zip_asset_id: int,
	 *         sha256: string,
	 *         package_type: string,
	 *         header_file: string
	 *     }
	 * }
	 */
	private function serializedVerdict(): array {
		return array(
			'state'                  => CandidateValidation::READY,
			'code'                   => 'release_identity_verified',
			'release_tag'            => 'v1.2.3-beta.2',
			'release_version'        => '1.2.3-beta.2',
			'package_header_version' => '1.2.3-beta.2',
			'requires_php'           => '8.0',
			'requires_wordpress'     => '6.5',
			'identity'               => array(
				'release_id'   => 42,
				'tag'          => 'v1.2.3-beta.2',
				'zip_asset_id' => 101,
				'sha256'       => str_repeat( 'a', 64 ),
				'package_type' => 'plugin',
				'header_file'  => 'example-plugin/example-plugin.php',
			),
		);
	}
}
