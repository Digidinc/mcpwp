# Video Asset Governance

This directory records assets used by MCPWP videos: character art, screenshots,
footage, logos, music, sound effects, fonts, and motion elements.

## Asset states

- **Source:** original material with creator, origin, license, and capture date.
- **Working:** editable or generated variants that are not approved for use.
- **Approved:** a reviewed asset with a stable digest and declared usage scope.
- **Retired:** an asset retained for provenance but prohibited from new work.

## Required record

Every promoted asset must identify:

- filename and SHA-256 digest;
- creator or generator;
- source location;
- creation or capture date;
- license or permission basis;
- edits applied;
- approval owner and date;
- allowed channels and expiration, when applicable.

## Lumen character assets

Lumen’s graphite head, amber eyes, teal left-temple aperture, director headset,
and restrained bronze details are identity invariants. New renders must be
reviewed against the canonical character sheet before promotion to Approved.
Binary character files should enter through a dedicated asset pull request so
their provenance and repository impact can be reviewed separately.

