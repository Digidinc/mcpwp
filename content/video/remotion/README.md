# Remotion Production

Remotion is the default surface for reproducible MCPWP motion graphics,
explainers, evidence overlays, captions, and channel variants.

## Contract

- A composition must identify its brief, script, source commit, frame rate,
  duration, dimensions, and output filename.
- Scene timing and animation must derive from frames rather than wall-clock CSS
  behavior.
- Channel variants should share one source composition and explicit props.
- External media must use approved asset records from `../assets/`.
- Captured product footage must preserve its original evidence reference.
- Rendering must not contact production services or embed credentials.

## Initial boundary

This directory contains conventions only. A Remotion application should be
added in a separate reviewed change after the first brief and script establish
the required compositions. That change must include deterministic render and
type-check commands.

## Recommended composition names

- `McpwpExplainerLandscape`
- `McpwpShortPortrait`
- `McpwpSquareProfile`
- `McpwpEvidenceWalkthrough`

Each composition should accept a versioned content object rather than hard-code
commercial claims inside visual components.

