#!/usr/bin/env bash
# Draft-leak proof for the site graph read surface (#943 S2), over real HTTP /mcp.
#
# Run against a THROWAWAY rig with a backfilled graph and rate limiting off:
#
#   BASE=http://localhost:9143 READ_KEY=mcpwp_... WRITE_KEY=mcpwp_... \
#     [DESIGNER_KEY=mcpwp_...] [EXPECTED_ORPHANS=81] bash graph-read-surface-http.sh
#
# DESIGNER_KEY (a read+write key with role=designer, which has no `content`
# category) checks review BLOCK-1: it must be refused on every graph surface.
#
# 1. The write key (positive control) lists every non-public post node with
#    wp_graph_query status=any, and every published node with an edge to one.
# 2. The read key then goes through all four surfaces (resources/read
#    mcpwp://graph, wp_graph_query in every status, wp_graph_node on every hidden
#    node, wp_graph_neighbors depth 2 in every direction from every published
#    node touching a hidden one) and the script greps every response for any
#    hidden node_key or title.
# 3. wp_graph_query {orphan:true}, paginated over HTTP, is compared with
#    EXPECTED_ORPHANS (the brute-force count graph-read-surface-rig.php prints).
#
# Exits non-zero on any leak or mismatch.

set -uo pipefail
: "${BASE:?}" "${READ_KEY:?}" "${WRITE_KEY:?}"
MCP="$BASE/?rest_route=/mcpwp/v1/mcp"
fail=0

rpc() { # key method params-json
	curl -s -X POST "$MCP" -H "X-API-Key: $1" -H 'Content-Type: application/json' \
		-d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$2\",\"params\":$3}"
}
tool() { # key name args-json -> the tool's JSON payload
	rpc "$1" tools/call "{\"name\":\"$2\",\"arguments\":$3}" | jq -c '.result.structuredContent // (.result.content[0].text | fromjson? ) // .'
}
query_all() { # key args-json -> all result rows, one JSON per line
	local key=$1 args=$2 cursor="" page
	while :; do
		if [[ -n $cursor ]]; then
			page=$(tool "$key" wp_graph_query "$(jq -c --arg c "$cursor" '. + {cursor:$c, limit:200}' <<<"$args")")
		else
			page=$(tool "$key" wp_graph_query "$(jq -c '. + {limit:200}' <<<"$args")")
		fi
		jq -c '.results[]?' <<<"$page"
		cursor=$(jq -r '.next_cursor // empty' <<<"$page")
		[[ -z $cursor ]] && break
	done
}

# ---- 1. Positive control: the write key sees the hidden nodes. ----
hidden=$(query_all "$WRITE_KEY" '{"status":"any","kind":["post","page"]}' | jq -c 'select(.status != "publish")')
hidden_count=$(grep -c . <<<"$hidden")
echo "write key: $hidden_count non-public post nodes visible (positive control)"
[[ $hidden_count -gt 0 ]] || { echo "FAIL no hidden nodes found; seed the rig first"; exit 1; }

needles=$(mktemp)
jq -r '.node_key' <<<"$hidden" | sed 's/.*/"&"/' >"$needles"
jq -r '.label' <<<"$hidden" | grep -v '^$' | jq -R . >>"$needles"

leak_check() { # label payload
	local hits
	hits=$(grep -F -o -f "$needles" <<<"$2" | sort -u | head -5)
	if [[ -n $hits ]]; then
		echo "FAIL $1 leaked: $(tr '\n' ' ' <<<"$hits")"
		fail=1
	fi
}

# ---- 2. Read key, all four surfaces. ----
summary=$(rpc "$READ_KEY" resources/read '{"uri":"mcpwp://graph"}' | jq -r '.result.contents[0].text')
leak_check "resources/read mcpwp://graph" "$summary"
echo "surface 1 resources/read mcpwp://graph: $(jq -c '{nodes_by_kind, orphans_total}' <<<"$summary")"

for status in "" draft private pending future trash any; do
	rows=$(query_all "$READ_KEY" "{\"status\":\"$status\"}")
	leak_check "wp_graph_query status=$status" "$rows"
	bad=$(jq -r 'select(.status != "publish" and (.node_key|startswith("post:")))' <<<"$rows" | head -1)
	[[ -z $bad ]] || { echo "FAIL wp_graph_query status=$status returned a non-publish post node"; fail=1; }
done
echo "surface 2 wp_graph_query: 7 status values x all pages checked"

n=0
while read -r key; do
	out=$(tool "$READ_KEY" wp_graph_node "{\"node_key\":\"$key\"}")
	leak_check "wp_graph_node $key" "$out"
	id=${key#post:}
	out2=$(tool "$READ_KEY" wp_graph_node "{\"post_id\":$id}")
	leak_check "wp_graph_node post_id=$id" "$out2"
	n=$((n + 1))
done < <(jq -r '.node_key' <<<"$hidden")
echo "surface 3 wp_graph_node: $n hidden nodes x (node_key, post_id) -> $(jq -c '{error: (.error.code // .code // .)}' <<<"$out" | head -c 120)"

centers=$(while read -r key; do
	tool "$WRITE_KEY" wp_graph_neighbors "{\"node_key\":\"$key\",\"direction\":\"both\",\"limit\":100}" |
		jq -r --arg k "$key" '.edges[]? | if .from_key == $k then .to_key else .from_key end'
done < <(jq -r '.node_key' <<<"$hidden") | sort -u | grep '^post:')
c=0
for key in $centers; do
	for dir in out in both; do
		out=$(tool "$READ_KEY" wp_graph_neighbors "{\"node_key\":\"$key\",\"direction\":\"$dir\",\"depth\":2,\"limit\":100}")
		leak_check "wp_graph_neighbors $key $dir depth2" "$out"
	done
	c=$((c + 1))
done
echo "surface 4 wp_graph_neighbors: $c centers adjacent to hidden nodes x 3 directions x depth 2"

# ---- 3. Orphans over HTTP. ----
orphans=$(query_all "$READ_KEY" '{"orphan":true}' | jq -r .node_key | sort)
orphan_count=$(grep -c . <<<"$orphans")
dups=$(uniq -d <<<"$orphans" | grep -c .)
echo "wp_graph_query {orphan:true} over HTTP, paginated: $orphan_count (dups=$dups)"
if [[ -n ${EXPECTED_ORPHANS:-} && $orphan_count != "$EXPECTED_ORPHANS" ]]; then
	echo "FAIL orphan count $orphan_count != brute force $EXPECTED_ORPHANS"; fail=1
fi
[[ $dups == 0 ]] || { echo "FAIL duplicate orphan rows across pages"; fail=1; }

# ---- Injection over HTTP. ----
inj=$(tool "$READ_KEY" wp_graph_query '{"order_by":"(SELECT user_pass FROM wp_users LIMIT 1)"}')
jq -e 'tostring | test("invalid_filter")' <<<"$inj" >/dev/null || { echo "FAIL order_by injection not refused: $inj"; fail=1; }
inj2=$(tool "$READ_KEY" wp_graph_query '{"metric":{"name":"clicks","op":"> 0 OR 1=1 OR 0 >","value":1}}')
jq -e 'tostring | test("invalid_filter")' <<<"$inj2" >/dev/null || { echo "FAIL metric.op injection not refused: $inj2"; fail=1; }
echo "injection over HTTP: order_by and metric.op payloads refused (invalid_filter)"

# ---- 4. BLOCK-1: a designer-role key (no content category) is refused. ----
if [[ -n ${DESIGNER_KEY:-} ]]; then
	for r in "graph" "graph/query" "graph/node&post_id=1" "graph/neighbors&post_id=1"; do
		code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?rest_route=/mcpwp/v1/$r" -H "X-API-Key: $DESIGNER_KEY")
		[[ $code == 403 ]] || { echo "FAIL designer REST /$r answered $code"; fail=1; }
	done
	d1=$(tool "$DESIGNER_KEY" wp_graph_query '{"status":"any"}')
	jq -e 'tostring | test("scope_denied|forbidden_category")' <<<"$d1" >/dev/null || { echo "FAIL designer wp_graph_query not refused: $(head -c 200 <<<"$d1")"; fail=1; }
	d2=$(rpc "$DESIGNER_KEY" resources/read '{"uri":"mcpwp://graph"}')
	jq -e 'tostring | test("forbidden_category")' <<<"$d2" >/dev/null || { echo "FAIL designer resources/read mcpwp://graph not refused: $(head -c 200 <<<"$d2")"; fail=1; }
	leak_check "designer surfaces" "$d1 $d2"
	echo "designer key: REST /graph* 403, wp_graph_query refused, resources/read mcpwp://graph refused"
fi

rm -f "$needles"
echo "fail=$fail"
exit $fail
