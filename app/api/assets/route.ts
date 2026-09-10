import { env } from "cloudflare:workers";
import { localOnly, fail } from "@/lib/server";
export async function GET(request: Request) {
  try {
    localOnly(request);
    const path = new URL(request.url).searchParams.get("path") || "";
    if (!/^mockups\/[a-f0-9-]+\.png$/.test(path))
      return new Response("Not found", { status: 404 });
    const obj = await env.BUCKET?.get(path);
    if (!obj) return new Response("Not found", { status: 404 });
    return new Response(obj.body, {
      headers: {
        "Content-Type": "image/png",
        "Cache-Control": "private, max-age=3600",
        "X-Content-Type-Options": "nosniff",
      },
    });
  } catch (e) {
    return fail(e);
  }
}
