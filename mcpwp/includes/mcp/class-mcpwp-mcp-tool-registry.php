<?php
/** MCP tool registry base. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Mcpwp_MCP_Tool_Registry {
	abstract public function get_tools();
	abstract public function get_tool_map();
	protected function define_tool( $name, $description, $input_props ) {
		$properties = array(); $required = array();
		foreach ( $input_props as $key => $definition ) {
			$properties[ $key ] = array( 'type' => $definition['type'] ?? 'string', 'description' => $definition['description'] ?? '' );
			if ( ! empty( $definition['required'] ) ) { $required[] = $key; }
		}
		$schema = array( 'type' => 'object', 'properties' => empty( $properties ) ? new stdClass() : $properties );
		if ( ! empty( $required ) ) { $schema['required'] = $required; }
		return array( 'name' => $name, 'description' => $description, 'inputSchema' => $schema, 'annotations' => $this->get_tool_annotations( $name ) );
	}
	public function get_tool_annotations( $name ) {
		$mapping = $this->get_tool_map()[ $name ] ?? array();
		$method  = strtoupper( (string) ( $mapping['method'] ?? '' ) );
		return array( 'readOnlyHint' => in_array( $method, array( 'GET', 'HEAD' ), true ), 'openWorldHint' => false, 'destructiveHint' => 'DELETE' === $method, 'category' => $this->get_tool_category( $name ) );
	}
	public function get_tool_categories() { return array(); }
	public function get_tool_category( $name ) { $map = $this->get_tool_categories(); return $map[ $name ] ?? 'site'; }
	public function get_required_capabilities() { return array(); }
}
