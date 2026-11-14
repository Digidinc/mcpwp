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
 * Core first narrows the table to the namespace(s) the path starts with
 * (falling back to every route when none does), then tries routes in
 * registration order: the first route whose pattern matches the path AND
 * has a handler for the method wins. A route that matches the path but has
 * no handler for the method falls through to the next one, and HEAD falls
 * back to a GET handler.
 *
 * @param array    $routes     WP_REST_Server::get_routes()-shaped table.
 * @param string   $method     HTTP method.
 * @param string   $path       Request route, e.g. /mcpwp/v1/site-blueprints/extract.
 * @param string[] $namespaces Registered namespaces to narrow by.
 * @return array|null array( 'route', 'handler', 'params' ), or null (rest_no_route).
 */
function mcpwp_test_match_rest_route( array $routes, string $method, string $path, array $namespaces = array( 'mcpwp/v1' ) ) {
	$method   = strtoupper( $method );
	$narrowed = array();
	$prefixed = rtrim( ltrim( $path, '/' ), '/' ) . '/';
	foreach ( $namespaces as $namespace ) {
		if ( 0 !== strpos( $prefixed, $namespace . '/' ) ) {
			continue;
		}
		foreach ( $routes as $route => $handlers ) {
			if ( '/' . $namespace === $route || 0 === strpos( $route, '/' . $namespace . '/' ) ) {
				$narrowed[ $route ] = $handlers;
			}
		}
	}
	if ( $narrowed ) {
		$routes = $narrowed;
	}

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
 * Concrete request paths a route pattern itself accepts (possibly none).
 *
 * For sample set k, each named group becomes the k-th value from a fixed
 * list (numeric ids, slugs, hyphenated and underscored slugs, a mixed-case
 * string) that its own sub-pattern accepts; any other group collapses to
 * '1'. Every returned path is re-checked against the pattern, so it is one
 * WordPress could route to it. A literal route yields exactly one path.
 *
 * @return string[]
 */
function mcpwp_test_sample_rest_paths( string $route ): array {
	$values = array( '1', '42', 'abc', 'abc-def', 'abc_def', 'gravityforms', 'Zq9x', 'a1b2c3d4' );
	$paths  = array();
	foreach ( array_keys( $values ) as $k ) {
		$path = preg_replace_callback(
			'/\(\?P<\w+>([^()]*)\)/',
			static function ( $m ) use ( $values, $k ) {
				$accepted = array();
				foreach ( $values as $v ) {
					if ( preg_match( '@^(?:' . $m[1] . ')$@', $v ) ) {
						$accepted[] = $v;
					}
				}
				return $accepted ? $accepted[ $k % count( $accepted ) ] : '1';
			},
			$route
		);
		do {
			$prev = $path;
			$path = preg_replace( '/\([^()]*\)\??/', '1', $path );
		} while ( $prev !== $path );
		$path = str_replace( array( '^', '$' ), '', $path );
		if ( preg_match( '@^' . $route . '$@i', $path ) ) {
			$paths[ $path ] = true;
		}
	}
	return array_keys( $paths );
}

/**
 * mcpwp/v1 route/methods that an EARLIER registered route always wins
 * (#1104) — e.g. a literal /things/extract registered after
 * /things/(?P<id>[a-z0-9_-]+).
 *
 * HEURISTIC, NOT EXHAUSTIVE. It probes a handful of sample paths per
 * pattern (mcpwp_test_sample_rest_paths()) and flags a route/method only
 * when EVERY sample is won by another route, so two patterns that merely
 * overlap on some requests are not flagged. A clean result means every
 * route reaches its own handler for at least one sampled request; it does
 * not prove that no request the pattern accepts is captured elsewhere.
 *
 * @param array $routes WP_REST_Server::get_routes()-shaped table.
 * @return array<string,string> "METHOD /route" => the route(s) that win instead.
 */
function mcpwp_test_shadowed_rest_routes( array $routes ): array {
	$shadowed = array();
	foreach ( $routes as $route => $endpoints ) {
		if ( 0 !== strpos( $route, '/mcpwp/v1/' ) ) {
			continue;
		}
		$paths = mcpwp_test_sample_rest_paths( $route );
		if ( ! $paths ) {
			continue;
		}
		foreach ( $endpoints as $endpoint ) {
			foreach ( (array) ( $endpoint['methods'] ?? array() ) as $method => $enabled ) {
				if ( ! $enabled ) {
					continue;
				}
				$winners = array();
				foreach ( $paths as $path ) {
					$match = mcpwp_test_match_rest_route( $routes, $method, $path );
					if ( null !== $match && $match['route'] === $route ) {
						$winners = array();
						break; // One sample reaches its own handler: not shadowed.
					}
					$winners[ null === $match ? '(no route)' : $match['route'] ] = true;
				}
				if ( $winners ) {
					$shadowed[ $method . ' ' . $route ] = implode( ', ', array_keys( $winners ) );
				}
			}
		}
	}
	return $shadowed;
}

/**
 * Routes whose pattern the sampler cannot build any path for, so the shadow
 * sweep would silently skip them. Kept separate so a test can pin it empty.
 *
 * @param array $routes WP_REST_Server::get_routes()-shaped table.
 * @return array<string>
 */
function mcpwp_test_unsampled_rest_routes( array $routes ): array {
	$unsampled = array();
	foreach ( array_keys( $routes ) as $route ) {
		if ( 0 === strpos( $route, '/mcpwp/v1/' ) && ! mcpwp_test_sample_rest_paths( $route ) ) {
			$unsampled[] = $route;
		}
	}
	return $unsampled;
}
