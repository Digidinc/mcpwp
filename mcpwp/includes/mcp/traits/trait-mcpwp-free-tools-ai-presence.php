<?php
/**
 * Free-tier tool definitions — AI crawler policy + IndexNow.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Free_Tools_AI_Presence_Trait {

	/**
	 * @return array Map of tool_name => category_slug.
	 */
	private function get_ai_presence_tool_categories() {
		return array(
			'wp_get_ai_crawler_policy' => 'seo',
			'wp_set_ai_crawler_policy' => 'seo',
			'wp_indexnow_status'       => 'seo',
			'wp_indexnow_submit'       => 'seo',
		);
	}

	/**
	 * @return array Tool definitions.
	 */
	private function get_ai_presence_tools() {
		$tools = array();

		$tools[] = $this->define_tool(
			'wp_get_ai_crawler_policy',
			'Get the current AI crawler allow/deny policy (GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended, CCBot, Bytespider) plus whether each is a SEARCH bot (denying it removes this site from that AI product\'s live answers) or a TRAINING bot (denying it only affects future model training, not current answers). Also reports whether a physical robots.txt file exists, which would prevent this policy from taking effect.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_set_ai_crawler_policy',
			'Set per-bot allow/deny for AI crawlers. Requires an admin-scoped key. IMPORTANT: denying a SEARCH bot (OAI-SearchBot, ChatGPT-User, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User) removes this site from that AI product\'s live, citable answers. Denying a TRAINING bot (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended, CCBot, Bytespider) only opts the site out of future model training and has no effect on current answers. Default for every bot is "allow" — call wp_get_ai_crawler_policy first to see the current state and each bot\'s type before changing anything.',
			array(
				'bots' => array(
					'type'        => 'object',
					'description' => 'Object of bot-name => "allow"|"deny", e.g. {"GPTBot": "deny"}. Unknown bot names or values are rejected individually and reported back.',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_indexnow_status',
			'Get IndexNow status: whether automatic submission is enabled, the environment gate (production only, unless forced), the site\'s key-file URL, whether Rank Math\'s own IndexNow module is active (MCPWP defers to it when so), how many URLs are queued in the current debounce window, and the last submission result.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_indexnow_submit',
			'Explicitly submit one or more of this site\'s own URLs to the shared IndexNow endpoint (relays to Bing, Yandex, Naver, Seznam, Yep, Amazon — not Google). Refused if Rank Math\'s own IndexNow module is active on this site (avoids double-submitting) or if IndexNow is disabled in MCPWP settings. Unlike the automatic publish-hook path, this explicit call is not blocked by the production-only environment gate.',
			array(
				'urls' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'URLs to submit. Must belong to this site; others are silently dropped.',
					'required'    => true,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array Tool mappings.
	 */
	private function get_ai_presence_tool_map() {
		return array(
			'wp_get_ai_crawler_policy' => array(
				'method' => 'GET',
				'route'  => '/ai-crawlers/policy',
			),
			'wp_set_ai_crawler_policy' => array(
				'method' => 'PUT',
				'route'  => '/ai-crawlers/policy',
			),
			'wp_indexnow_status'       => array(
				'method' => 'GET',
				'route'  => '/indexnow/status',
			),
			'wp_indexnow_submit'       => array(
				'method' => 'POST',
				'route'  => '/indexnow/submit',
			),
		);
	}
}
