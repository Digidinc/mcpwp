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
			'Explicitly submit up to 100 of this site\'s own URLs to the shared IndexNow endpoint (relays to Bing, Yandex, Naver, Seznam, Yep, Amazon — not Google). Requires an admin-scoped key and is limited to one call per minute. Every URL must resolve to a currently-published, indexable post on this site with no query string — a URL that fails validation is reported back in "rejected", not silently dropped. Refused (whole call, nothing sent) if IndexNow is disabled, this site\'s "Discourage search engines" setting is on, the environment is non-production and not forced on, or Rank Math\'s own Instant Indexing module is configured to auto-submit — call wp_indexnow_status first to see which, if any, applies.',
			array(
				'urls' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Up to 100 URLs to submit. Each must belong to this site, carry no query string, and resolve to a currently-indexable post.',
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
