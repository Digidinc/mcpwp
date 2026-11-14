<?php
/**
 * Server-owned raw source evidence for preview decisions.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mcpwp_Raw_Parameter_Source {
	/** @var object Shared source and lossless intent-occurrence cache. */
	public $document;
	/** @var string Source channel: query or body. */
	public $channel;
	/** @var array Selected message/operation path in the original bytes. */
	public $path = array();

	public function __construct( $format, $raw, $channel ) {
		$this->document = (object) array( 'format' => $format, 'raw' => $raw, 'keys' => null, 'values' => array() );
		$this->channel = $channel;
	}

	/** PHP's SAPI selects its body handler before WordPress parses MIME parameters. */
	public static function php_media_type( $header ) {
		$header = trim( (string) $header );
		return strtolower( trim( substr( $header, 0, strcspn( $header, ';, ' ) ) ) );
	}

	/** Select a fragment without re-encoding or discarding duplicate keys. */
	public function at( $path ) {
		$source = clone $this;
		$source->path = array_merge( $this->path, $path );
		return $source;
	}
}
