import { env } from "cloudflare:workers";
import { ZodError } from "zod";
export function db() {
  if (!env.DB) throw new Error("Database is not configured");
  return env.DB;
}
export function secret(name: string) {
  return (
    (env as unknown as Record<string, string>)[name] || process.env[name] || ""
  );
}
export function localOnly(request: Request) {
  const u = new URL(request.url);
  if (!["localhost", "127.0.0.1", "[::1]"].includes(u.hostname))
    throw new Error(
      "This development version is restricted to localhost. Configure staff authentication before deployment.",
    );
  const origin = request.headers.get("origin");
  if (origin && origin !== u.origin)
    throw new Error("Cross-origin request rejected");
  if (request.headers.get("sec-fetch-site") === "cross-site")
    throw new Error("Cross-site request rejected");
}
export async function jsonBody(request: Request) {
  const text = await request.text();
  if (text.length > 200000) throw new Error("Request too large");
  return JSON.parse(text);
}
export function fail(error: unknown) {
  if (error instanceof ZodError) {
    const issue = error.issues[0];
    return Response.json(
      {
        error: `Please check ${issue.path.join(" ") || "the form"}: ${issue.message}`,
      },
      { status: 400 },
    );
  }
  const msg = error instanceof Error ? error.message : "Request failed";
  const safe =
    msg.includes("D1_") || msg.includes("SQLITE_")
      ? "Could not save the record. Check for a duplicate SKU and try again."
      : msg;
  return Response.json({ error: safe }, { status: 400 });
}
