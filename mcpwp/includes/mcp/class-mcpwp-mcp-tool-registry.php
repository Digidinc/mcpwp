<?php
/**
 * MCP Tool Registry Base Class
 *
 * Abstract base class for MCP tool registries.
 * Provides common functionality for defining tools and their annotations.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for MCP tool registries.
 *
 * Subclasses implement get_tools() and get_tool_map() to provide
 * tool definitions and route mappings.
 */
abstract class Mcpwp_MCP_Tool_Registry {

	/**
	 * JSON Schema constraint keys copied verbatim from a tool's parameter
	 * definition into the emitted inputSchema.
	 *
	 * `type`, `description`, `default` and `required` are handled separately
	 * because they are not pass-through.
	 *
	 * @var string[]
	 */
	const SCHEMA_CONSTRAINT_KEYS = array(
		'items',
		'enum',
		'properties',
		'format',
		'minimum',
		'maximum',
		'minItems',
		'maxItems',
		'additionalProperties',
	);

	/**
	 * Get tool definitions.
	 *
	 * Subclasses must implement this to return an array of tool definitions.
	 *
	 * @return array Tool definitions.
	 */
	abstract public function get_tools();

	/**
	 * Get tool map (tool name -> REST route mapping).
	 *
	 * Subclasses must implement this to return tool-to-route mappings.
	 *
	 * @return array Tool mappings.
	 */
	abstract public function get_tool_map();

	/**
	 * Define a tool for MCP.
	 *
	 * @param string $name        Tool name.
	 * @param string $description Tool description.
	 * @param array  $input_props Input schema properties.
	 * @return array Tool definition.
	 */
	protected function define_tool( $name, $description, $input_props ) {
		$properties = array();
		$required   = array();

		foreach ( $input_props as $prop_name => $prop_def ) {
			$properties[ $prop_name ] = array(
				'type'        => isset( $prop_def['type'] ) ? $prop_def['type'] : 'string',
				'description' => isset( $prop_def['description'] ) ? $prop_def['description'] : '',
			);

			if ( isset( $prop_def['default'] ) ) {
				$properties[ $prop_name ]['default'] = $prop_def['default'];
			}

			// Carry the remaining JSON Schema constraints through verbatim. Dropping
			// them here made every authored `items`/`enum` invisible to clients, so an
			// array parameter advertised no item type and callers sent whatever they
			// liked — see #707, where JSON strings reached a handler expecting objects.
			foreach ( self::SCHEMA_CONSTRAINT_KEYS as $constraint ) {
				if ( isset( $prop_def[ $constraint ] ) ) {
					$properties[ $prop_name ][ $constraint ] = $prop_def[ $constraint ];
				}
			}

			if ( ! empty( $prop_def['required'] ) ) {
				$required[] = $prop_name;
			}
		}

		$schema = array(
			'type'       => 'object',
			'properties' => empty( $properties ) ? new \stdClass() : $properties,
		);

		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}

		return array(
			'name'        => $name,
			'title'       => Mcpwp_MCP_Tool_Annotations::get_title( $name ),
			'description' => $description,
			'inputSchema' => $schema,
			'annotations' => $this->get_tool_annotations( $name ),
		);
	}

	/**
	 * Get tool annotations for MCP compatibility.
	 *
	 * `title` is duplicated into annotations for clients predating the MCP
	 * 2025-06-18 tool-level `title` field, which read display names from
	 * `annotations.title` instead.
	 *
	 * @param string $name Tool name.
	 * @return array<string,bool|string> Tool annotation hints.
	 */
	public function get_tool_annotations( $name ) {
		return array(
			'title'           => Mcpwp_MCP_Tool_Annotations::get_title( $name ),
			'readOnlyHint'    => $this->is_read_only_tool( $name ),
			'openWorldHint'   => $this->is_open_world_tool( $name ),
			'destructiveHint' => $this->is_destructive_tool( $name ),
			'idempotentHint'  => $this->is_idempotent_tool( $name ),
			'category'        => $this->get_tool_category( $name ),
		);
	}

	/**
	 * Determine whether a tool is read-only.
	 *
	 * Consults Mcpwp_MCP_Tool_Annotations::ANNOTATION_OVERRIDES first — see
	 * that class for why the HTTP-method heuristic below is wrong for a
	 * known set of tools (e.g. a POST-mapped validator that never writes, or
	 * a GET-mapped tool that conditionally persists a result).
	 *
	 * @param string $name Tool name.
	 * @return bool True when tool does not modify data.
	 */
	protected function is_read_only_tool( $name ) {
		$override = Mcpwp_MCP_Tool_Annotations::get_override( $name );
		if ( null !== $override && isset( $override['readOnlyHint'] ) ) {
			return (bool) $override['readOnlyHint'];
		}

		$tool_map = $this->get_tool_map();
		if ( empty( $tool_map[ $name ]['method'] ) ) {
			return false;
		}

		$method = strtoupper( (string) $tool_map[ $name ]['method'] );
		return in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );
	}

	/**
	 * Determine whether a tool can access external systems.
	 *
	 * Consults the override table first (see is_read_only_tool()), then each
	 * registry's own get_open_world_tools() list.
	 *
	 * @param string $name Tool name.
	 * @return bool True when tool may interact with external services.
	 */
	protected function is_open_world_tool( $name ) {
		$override = Mcpwp_MCP_Tool_Annotations::get_override( $name );
		if ( null !== $override && isset( $override['openWorldHint'] ) ) {
			return (bool) $override['openWorldHint'];
		}

		$open_world_tools = $this->get_open_world_tools();
		return in_array( $name, $open_world_tools, true );
	}

	/**
	 * Determine whether a tool performs destructive actions.
	 *
	 * Consults the override table first (see is_read_only_tool()), then each
	 * registry's own get_destructive_tools() list, then falls back to the
	 * DELETE-method heuristic.
	 *
	 * @param string $name Tool name.
	 * @return bool True when tool can delete/revoke/reset/overwrite data.
	 */
	protected function is_destructive_tool( $name ) {
		$override = Mcpwp_MCP_Tool_Annotations::get_override( $name );
		if ( null !== $override && isset( $override['destructiveHint'] ) ) {
			return (bool) $override['destructiveHint'];
		}

		$destructive_tools = $this->get_destructive_tools();

		if ( in_array( $name, $destructive_tools, true ) ) {
			return true;
		}

		$tool_map = $this->get_tool_map();
		if ( empty( $tool_map[ $name ]['method'] ) ) {
			return false;
		}

		return 'DELETE' === strtoupper( (string) $tool_map[ $name ]['method'] );
	}

	/**
	 * Determine whether calling a tool twice with the same arguments leaves
	 * the site in the same state as calling it once.
	 *
	 * Consults the override table first. Absent an override, a read-only
	 * tool is idempotent by construction (it changes nothing); any other
	 * tool is not asserted either way and defaults to false — MCP clients
	 * should not assume a retry is safe unless this is explicitly true.
	 *
	 * @param string $name Tool name.
	 * @return bool True when repeat calls with the same arguments are safe.
	 */
	protected function is_idempotent_tool( $name ) {
		$override = Mcpwp_MCP_Tool_Annotations::get_override( $name );
		if ( null !== $override && isset( $override['idempotentHint'] ) ) {
			return (bool) $override['idempotentHint'];
		}

		return $this->is_read_only_tool( $name );
	}

	/**
	 * Get list of destructive tool names.
	 *
	 * Subclasses override this to declare which tools are destructive.
	 *
	 * @return array Tool names that perform destructive actions.
	 */
	protected function get_destructive_tools() {
		return array();
	}

	/**
	 * Get list of open world tool names.
	 *
	 * Subclasses override this to declare which tools access external systems.
	 *
	 * @return array Tool names that may interact with external services.
	 */
	protected function get_open_world_tools() {
		return array();
	}

	/**
	 * Get tool category mappings.
	 *
	 * Subclasses override this to declare which category each tool belongs to.
	 *
	 * @return array Map of tool_name => category_slug.
	 */
	public function get_tool_categories() {
		return array();
	}

	/**
	 * Get the category for a specific tool.
	 *
	 * @param string $name Tool name.
	 * @return string Category slug, or '' when the tool has none. Never an
	 *                invented 'site': every gate in the dispatcher resolves
	 *                the same way (#802 round 5).
	 */
	public function get_tool_category( $name ) {
		$categories = $this->get_tool_categories();
		return isset( $categories[ $name ] ) ? (string) $categories[ $name ] : '';
	}

	/**
	 * Get required capabilities for tools.
	 *
	 * Returns a map of tool name => capability key. Tools whose capability
	 * is not active on this site will be hidden from tools/list and rejected
	 * on tools/call with a helpful message.
	 *
	 * @return array Map of tool_name => capability_key.
	 */
	public function get_required_capabilities() {
		return array();
	}
}
