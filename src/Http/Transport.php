<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Http;

/**
 * Injectable safe HTTP transport.
 */
interface Transport {
	/**
	 * @return Response|\WP_Error
	 */
	public function get( Request $request );
}
