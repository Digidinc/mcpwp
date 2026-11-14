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

/**
 * WP_REST_Server::match_request_to_handler(), as core runs it (#1104).
 *
 * Routes are tried in registration order; the first route whose pattern
 * matches the path AND has a handler for the method wins. A route that
 * matches the path but has no handler for the method falls through to the
 * next one, and HEAD falls back to a GET handler.
 *
 * @param array  $routes WP_REST_Server::get_routes()-shaped table.
 * @param string $method HTTP method.
 * @param string $path   Request route, e.g. /mcpwp/v1/site-blueprints/extract.
 * @return array|null array( 'route', 'handler', 'params' ), or null (rest_no_route).
 */
function mcpwp_test_match_rest_route( array $routes, string $method, string $path ) {
	$method = strtoupper( $method );
	foreach ( $routes as $route => $handlers ) {
		if ( ! preg_match( '@^' . $route . '$@i', $path, $matches ) ) {
			continue;
		}
		$params = array();
		foreach ( $matches as $name => $value ) {
			if ( ! is_int( $name ) ) {
				$params[ $name ] = $value;
			}
		}
		foreach ( $handlers as $handler ) {
			$checked = $method;
			if ( 'HEAD' === $method && empty( $handler['methods']['HEAD'] ) ) {
				$checked = 'GET';
			}
			if ( empty( $handler['methods'][ $checked ] ) ) {
				continue;
			}
			return array(
				'route'   => $route,
				'handler' => $handler,
				'params'  => $params,
			);
		}
	}
	return null;
}

/**
 * A concrete request path a route pattern itself accepts, or null.
 *
 * Each named group becomes the first sample value its own sub-pattern
 * accepts (a numeric id, a slug, a hyphenated slug); any other group
 * collapses to '1'. The result is re-checked against the pattern, so a
 * returned path is always one WordPress could route to it.
 */
function mcpwp_test_sample_rest_path( string $route ) {
	$path = preg_replace_callback(
		'/\(\?P<\w+>([^()]*)\)/',
		static function ( $m ) {
			foreach ( array( '1', 'abc', 'abc-def', 'abc_def', 'gravityforms' ) as $sample ) {
				if ( preg_match( '@^(?:' . $m[1] . ')$@', $sample ) ) {
					return $sample;
				}
			}
			return '1';
		},
		$route
	);
	do {
		$prev = $path;
		$path = preg_replace( '/\([^()]*\)\??/', '1', $path );
	} while ( $prev !== $path );
	$path = str_replace( array( '^', '$' ), '', $path );
	return preg_match( '@^' . $route . '$@i', $path ) ? $path : null;
}

/**
 * Every mcpwp/v1 route/method WordPress can never reach, because an EARLIER
 * registered route matches the same request first (#1104) — e.g. a literal
 * /things/extract registered after /things/(?P<id>[a-z0-9_-]+).
 *
 * @param array $routes WP_REST_Server::get_routes()-shaped table.
 * @return array<string,string> "METHOD /route" => the route that wins instead.
 */
function mcpwp_test_shadowed_rest_routes( array $routes ): array {
	$shadowed = array();
	foreach ( $routes as $route => $endpoints ) {
		if ( 0 !== strpos( $route, '/mcpwp/v1/' ) ) {
			continue;
		}
		$path = mcpwp_test_sample_rest_path( $route );
		if ( null === $path ) {
			continue;
		}
		foreach ( $endpoints as $endpoint ) {
			foreach ( (array) ( $endpoint['methods'] ?? array() ) as $method => $enabled ) {
				if ( ! $enabled ) {
					continue;
				}
				$match = mcpwp_test_match_rest_route( $routes, $method, $path );
				if ( null !== $match && $match['route'] !== $route ) {
					$shadowed[ $method . ' ' . $route ] = $match['route'];
				}
			}
		}
	}
	return $shadowed;
}

/**
 * Routes whose pattern the sampler cannot build a path for, so the shadow
 * sweep would silently skip them. Kept separate so a test can pin it empty.
 *
 * @param array $routes WP_REST_Server::get_routes()-shaped table.
 * @return array<string>
 */
function mcpwp_test_unsampled_rest_routes( array $routes ): array {
	$unsampled = array();
	foreach ( array_keys( $routes ) as $route ) {
		if ( 0 === strpos( $route, '/mcpwp/v1/' ) && null === mcpwp_test_sample_rest_path( $route ) ) {
			$unsampled[] = $route;
		}
	}
	return $unsampled;
}
