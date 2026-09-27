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
	 * @param string     $name          Tool name.
	 * @param string     $description   Tool description.
	 * @param array      $input_props   Input schema properties.
	 * @param array|null $output_schema Optional JSON Schema for this tool's
	 *                                  result data (MCP spec 2025-06-18+
	 *                                  `outputSchema` — #906). Pass only when
	 *                                  the tool's REST result has a stable,
	 *                                  verified shape: a caller-selectable
	 *                                  `fields`/`_mcpwp_fields` filter, for
	 *                                  example, means no per-item property
	 *                                  can be `required`. Stored on the tool
	 *                                  definition unconditionally; whether it
	 *                                  is actually emitted to a given client
	 *                                  is decided per-request in
	 *                                  Mcpwp_REST_MCP against the negotiated
	 *                                  protocol version, not here.
	 * @return array Tool definition.
	 */
	protected function define_tool( $name, $description, $input_props, $output_schema = null ) {
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

		$tool = array(
			'name'        => $name,
			'title'       => Mcpwp_MCP_Tool_Annotations::get_title( $name ),
			'description' => $description,
			'inputSchema' => $schema,
			'annotations' => $this->get_tool_annotations( $name ),
		);

		if ( null !== $output_schema ) {
			$tool['outputSchema'] = self::with_error_envelope( $output_schema );
		}

		return $tool;
	}

	/**
	 * Admit the plugin-wide isError envelope into a tool's declared
	 * outputSchema (#906).
	 *
	 * Every failed tools/call carries `structuredContent: {error: {code,
	 * message, ...}}` (#936), whichever tool failed. The reference MCP SDK
	 * client validates structuredContent against the tool's outputSchema
	 * even when isError is true, so a schema that described only the
	 * success shape would turn every failure of that tool into a client-side
	 * "does not match the tool's output schema" exception — the model would
	 * never see the teaching text. Composed here, once, so no tool can
	 * declare a schema that forgets it.
	 *
	 * The result is an object schema whose `anyOf` accepts either the
	 * success shape (its own `required` keys) or the error envelope. The
	 * success properties keep their types; `error` is added to them.
	 *
	 * @param array $schema Success-shape object schema as the tool authored it.
	 * @return array
	 */
	public static function with_error_envelope( array $schema ) {
		// Idempotent: a schema already carrying the envelope (a tool list
		// normalized twice, or a third party that composed it itself) is
		// returned as-is rather than nested.
		if ( self::has_error_envelope( $schema ) ) {
			return $schema;
		}

		$success_required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? array_values( $schema['required'] ) : array();
		unset( $schema['required'] );

		$properties = array();
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			$properties = $schema['properties'];
		} elseif ( isset( $schema['properties'] ) && is_object( $schema['properties'] ) ) {
			// A third party may pass `new stdClass()` / a decoded object.
			$properties = get_object_vars( $schema['properties'] );
		}
		$properties['error'] = array(
			'type'                 => 'object',
			'description'          => 'Present only on an isError result: the machine-readable failure (#936).',
			'properties'           => array(
				'code'    => array( 'type' => array( 'string', 'integer' ) ),
				'message' => array( 'type' => 'string' ),
			),
			'required'             => array( 'code', 'message' ),
			'additionalProperties' => true,
		);

		// Success branch: its own required keys, and never an `error` key —
		// so a success-shaped result that also carries `error` is rejected
		// instead of passing as either branch.
		$success_branch = array( 'not' => array( 'required' => array( 'error' ) ) );
		if ( ! empty( $success_required ) ) {
			$success_branch = array_merge( array( 'required' => $success_required ), $success_branch );
		}

		$schema['type']       = 'object';
		$schema['properties'] = $properties;
		$schema['anyOf']      = array(
			$success_branch,
			array( 'required' => array( 'error' ) ),
		);

		return $schema;
	}

	/**
	 * Whether a schema already admits the error envelope: an `error`
	 * property and an anyOf branch requiring it. Detected structurally,
	 * not by a vendor keyword — a strict-mode Ajv rejects unknown keywords.
	 *
	 * @param array $schema Output schema.
	 * @return bool
	 */
	private static function has_error_envelope( array $schema ) {
		if ( ! isset( $schema['properties']['error'], $schema['anyOf'] ) || ! is_array( $schema['anyOf'] ) ) {
			return false;
		}
		foreach ( $schema['anyOf'] as $branch ) {
			if ( is_array( $branch ) && isset( $branch['required'] ) && array( 'error' ) === $branch['required'] ) {
				return true;
			}
		}
		return false;
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
	 * Per the MCP spec: readOnlyHint true means no modification of the
	 * environment at all; destructiveHint true means "may perform destructive
	 * updates," false means "only additive updates." Any tool that overwrites
	 * or deletes an existing value is destructive — so the order here is:
	 *
	 * 1. Explicit override wins (see is_read_only_tool()).
	 * 2. A registry's own get_destructive_tools() list, or a DELETE method —
	 *    an explicit declaration (first-party list, or a custom registration's
	 *    `destructive => true`) always wins, even over a GET/read-only
	 *    method; a registrant declaring both is a contradiction this method
	 *    resolves in favor of the explicit, human-authored signal.
	 * 3. A read-only tool changes nothing, so absent an explicit declaration
	 *    it can't be destructive.
	 * 4. Otherwise: default_destructive_for_write_tools() decides. First-party
	 *    registries (free/pro/Figma/AI) default this true — a write tool is
	 *    presumed destructive unless its name is a known purely-additive verb
	 *    (create/add/upload/clone/import/submit/duplicate/bulk_create/
	 *    bulk_upload — see Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name()).
	 *    Mcpwp_Custom_Tool_Registry overrides this back to false: its public
	 *    `mcpwp_register_tools` contract documents destructive as opt-in
	 *    (default false), and third-party registrants set it explicitly.
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
		$method   = isset( $tool_map[ $name ]['method'] ) ? strtoupper( (string) $tool_map[ $name ]['method'] ) : '';
		if ( 'DELETE' === $method ) {
			return true;
		}

		// A tool that modifies nothing cannot be destructive — but only
		// reached once an explicit declaration above has had its say.
		if ( $this->is_read_only_tool( $name ) ) {
			return false;
		}

		if ( ! $this->default_destructive_for_write_tools() ) {
			return false;
		}

		return ! Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name( $name );
	}

	/**
	 * Whether an un-overridden, non-read-only tool with no explicit
	 * destructive declaration defaults to destructiveHint=true.
	 *
	 * True for first-party registries (the strict, connector-directory-ready
	 * reading). Mcpwp_Custom_Tool_Registry overrides this to false to honor
	 * its documented `destructive` (default false) contract for third-party
	 * `mcpwp_register_tools` registrants.
	 *
	 * @return bool
	 */
	protected function default_destructive_for_write_tools() {
		return true;
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
