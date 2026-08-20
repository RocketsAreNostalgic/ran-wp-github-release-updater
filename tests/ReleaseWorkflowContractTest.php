<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowContractTest extends TestCase {

	public function testExpensiveLifecycleRunsOnlyAfterQualityAndUsesTheCurrentWordPressPatch(): void {
		$workflow = (string) file_get_contents( dirname( __DIR__ ) . '/.github/workflows/ci.yml' );

		self::assertStringContainsString( "cancel-in-progress: \${{ github.event_name == 'pull_request' }}", $workflow );
		self::assertMatchesRegularExpression( '/wordpress-lifecycle:\R(?:.*\R){0,3}\s+needs: quality/', $workflow );
		self::assertStringContainsString( '- "6.5"', $workflow );
		self::assertStringContainsString( '- "7.0.3"', $workflow );
		self::assertStringNotContainsString( '- "7.0.2"', $workflow );
		self::assertSame( 2, substr_count( $workflow, 'setup-php@f3e473d116dcccaddc5834248c87452386958240' ) );
	}

	public function testReleasePreparationAndExactPublisherHaveSeparateWiredOwners(): void {
		$workflow = (string) file_get_contents( dirname( __DIR__ ) . '/.github/workflows/release-please.yml' );
		$config   = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/release-please-config.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		self::assertStringContainsString( 'workflow_run:', $workflow );
		self::assertStringContainsString( "workflow_run.event == 'push'", $workflow );
		self::assertStringContainsString( "workflow_run.conclusion == 'success'", $workflow );
		self::assertStringContainsString( "workflow_run.head_branch == 'main'", $workflow );
		self::assertStringContainsString( 'workflow_run.head_repository.id == github.repository_id', $workflow );
		self::assertStringContainsString( 'workflow_run.head_repository.full_name == github.repository', $workflow );
		self::assertStringContainsString( 'permissions: {}', $workflow );
		self::assertStringContainsString( 'group: updater-exact-release-publisher', $workflow );
		self::assertStringContainsString( 'cancel-in-progress: false', $workflow );
		self::assertStringContainsString( 'timeout-minutes: 15', $workflow );
		self::assertStringContainsString( 'ref: ${{ github.event.workflow_run.head_sha }}', $workflow );
		self::assertStringContainsString( 'fetch-depth: 0', $workflow );
		self::assertStringContainsString( 'persist-credentials: false', $workflow );
		self::assertStringContainsString( 'actions/setup-node@48b55a011bda9f5d6aeb4c2d9c7362e8dae4041e', $workflow );
		self::assertStringContainsString( 'RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID: ${{ vars.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID }}', $workflow );
		self::assertStringContainsString( 'RAN_RELEASE_PUBLISHER_MUTATE: "1"', $workflow );
		self::assertStringContainsString( 'run: node scripts/release-publisher.mjs', $workflow );
		self::assertMatchesRegularExpression( '/release:\R(?:.*\R){0,14}\s+permissions:\R\s+contents: write\R\s+issues: write\R\s+pull-requests: write/', $workflow );
		self::assertTrue( $config['packages']['.']['skip-github-release'] );
	}

	public function testComposerArchiveExportsRuntimeAndMetadataWithoutReleaseTooling(): void {
		$attributes = (string) file_get_contents( dirname( __DIR__ ) . '/.gitattributes' );
		$excluded   = array(
			'/.github export-ignore',
			'/.gitattributes export-ignore',
			'/.prettierignore export-ignore',
			'/.release-please-manifest.json export-ignore',
			'/.stylelintignore export-ignore',
			'/composer.lock export-ignore',
			'/release-please-config.json export-ignore',
			'/scripts export-ignore',
			'/tests export-ignore',
			'/vendor export-ignore',
		);

		foreach ( $excluded as $rule ) {
			self::assertStringContainsString( $rule, $attributes );
		}
		self::assertStringNotContainsString( '/composer.json export-ignore', $attributes );
		self::assertStringNotContainsString( '/bootstrap.php export-ignore', $attributes );
		self::assertStringNotContainsString( '/runtime.php export-ignore', $attributes );
		self::assertStringNotContainsString( '/src export-ignore', $attributes );
	}
}
