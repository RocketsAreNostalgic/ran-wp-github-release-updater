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

	public function testReleasePleaseAcceptsOnlySuccessfulSameRepositoryMainPushes(): void {
		$workflow = (string) file_get_contents( dirname( __DIR__ ) . '/.github/workflows/release-please.yml' );

		self::assertStringContainsString( 'workflow_run:', $workflow );
		self::assertStringContainsString( "workflow_run.event == 'push'", $workflow );
		self::assertStringContainsString( "workflow_run.conclusion == 'success'", $workflow );
		self::assertStringContainsString( "workflow_run.head_branch == 'main'", $workflow );
		self::assertStringContainsString( 'workflow_run.head_repository.full_name == github.repository', $workflow );
		self::assertStringContainsString( 'permissions: {}', $workflow );
		self::assertMatchesRegularExpression( '/release-please:\R(?:.*\R){0,12}\s+permissions:\R\s+contents: write\R\s+issues: write\R\s+pull-requests: write/', $workflow );
	}
}
