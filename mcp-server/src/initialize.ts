/**
 * Carry WordPress's `initialize` instructions through the stdio proxy (#1074).
 *
 * The SDK answers the host's `initialize` itself, with no instructions, so the
 * host never saw the "Finding Tools" guidance for the `core` tool list. The
 * response is patched on its way out through the public Transport interface,
 * the same way inbound messages are observed.
 */

interface JsonRpcLike {
  id?: unknown;
  result?: Record<string, unknown>;
  error?: unknown;
}

/**
 * Add `instructions` to an initialize result. Any other message, an error
 * response, or an empty value is returned untouched.
 */
export function withUpstreamInstructions<T>(message: T, instructions: string | null): T {
  const msg = message as unknown as JsonRpcLike | null;
  if (!instructions || !msg || msg.error || !msg.result) return message;
  if (typeof msg.result.protocolVersion !== "string") return message;
  return { ...msg, result: { ...msg.result, instructions } } as unknown as T;
}
