<?php
/**
 * Base registry for retained MCP tools.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Build MCP tool definitions and shared annotations.
 */
abstract class Mcpwp_MCP_Tool_Registry {
	/**
	 * Get public MCP tool definitions.
	 *
	 * @return array<int,array<string,mixed>> Tool definitions.
	 */
	abstract public function get_tools();

	/**
	 * Get the exact tool-to-route map.
	 *
	 * @return array<string,array<string,string>> Tool route mappings.
	 */
	abstract public function get_tool_map();

	/**
	 * Build one MCP tool definition and input schema.
	 *
	 * @param string              $name           Tool name.
	 * @param string              $description    Public tool description.
	 * @param array<string,mixed> $input_props    Input property schemas.
	 * @param array<string,mixed> $schema_options Additional object-schema options.
	 * @return array<string,mixed> MCP tool definition.
	 */
	protected function define_tool( $name, $description, $input_props, $schema_options = array() ) {
		$properties = array();
		$required   = array();
		foreach ( $input_props as $key => $definition ) {
			$properties[ $key ] = $definition;
			if ( ! empty( $definition['required'] ) ) {
				$required[] = $key; }
			unset( $properties[ $key ]['required'] );
		}
		$schema = array_merge(
			array(
				'type'                 => 'object',
				'properties'           => empty( $properties ) ? new stdClass() : $properties,
				'required'             => $required,
				'additionalProperties' => false,
			),
			$schema_options
		);
		return array(
			'name'        => $name,
			'description' => $description,
			'inputSchema' => $schema,
			'annotations' => $this->get_tool_annotations( $name ),
		);
	}
	/**
	 * Derive MCP behavior annotations for one tool.
	 *
	 * @param string $name Tool name.
	 * @return array<string,bool|string> Tool annotations.
	 */
	public function get_tool_annotations( $name ) {
		$mapping = $this->get_tool_map()[ $name ] ?? array();
		$method  = strtoupper( (string) ( $mapping['method'] ?? '' ) );
		return array(
			'readOnlyHint'    => in_array( $method, array( 'GET', 'HEAD' ), true ),
			'destructiveHint' => 'wp_delete_draft' === $name,
			'openWorldHint'   => false,
			'category'        => $this->get_tool_category( $name ),
		);
	}
	/**
	 * Get tool-to-category mappings supplied by a concrete registry.
	 *
	 * @return array<string,string> Tool category mappings.
	 */
	public function get_tool_categories() {
		return array(); }
	/**
	 * Resolve one tool's retained category.
	 *
	 * @param string $name Tool name.
	 * @return string Retained category slug.
	 */
	public function get_tool_category( $name ) {
		$map = $this->get_tool_categories();
		return $map[ $name ] ?? 'site'; }
	/**
	 * Get human-readable capability requirements supplied by a registry.
	 *
	 * @return array<string,string[]> Capability requirements by tool.
	 */
	public function get_required_capabilities() {
		return array(); }
}
