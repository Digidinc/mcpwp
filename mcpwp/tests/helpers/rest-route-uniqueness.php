<?php
/** Normalize equivalent numeric route patterns without matching literals to regexes. */
function mcpwp_test_canonical_rest_route( string $route ): string {
	$route = str_replace( '[\\d]', '\\d', $route );
	return preg_replace( '/\(\?P<[^>]+>/', '(?:', $route );
}

/** Shared route-table assertion for the unit harness and real WordPress. */
function mcpwp_test_duplicate_rest_handlers( array $routes ): array {
	$duplicates = array();
	$seen = array();
	foreach ( $routes as $route => $endpoints ) {
		if ( 0 !== strpos( $route, '/mcpwp/v1/' ) ) {
			continue;
		}
		// Equivalent numeric regexes and capture names describe the same URL.
		$canonical = mcpwp_test_canonical_rest_route( $route );
		foreach ( $endpoints as $endpoint ) {
			if ( ! isset( $endpoint['methods'] ) ) {
				continue;
			}
			foreach ( $endpoint['methods'] as $method => $enabled ) {
				if ( ! $enabled ) {
					continue;
				}
				if ( isset( $seen[ $canonical ][ $method ] ) ) {
					$duplicates[] = $method . ' ' . $route;
				}
				$seen[ $canonical ][ $method ] = true;
			}
		}
	}
	return $duplicates;
}
