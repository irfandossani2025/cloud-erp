import { env } from "cloudflare:workers";
import { db, secret, localOnly, fail } from "@/lib/server";
import type { Product } from "@/lib/domain";
const allowed = ["image/png", "image/jpeg", "image/webp"];
async function checkImage(blob: Blob) {
  if (
    !allowed.includes(blob.type) ||
    blob.size > 8 * 1024 * 1024 ||
    blob.size < 12
  )
    throw new Error("Use PNG, JPG or WebP images under 8 MB.");
  const b = new Uint8Array(await blob.slice(0, 12).arrayBuffer());
  const valid =
    (blob.type === "image/png" &&
      b[0] === 137 &&
      b[1] === 80 &&
      b[2] === 78 &&
      b[3] === 71) ||
    (blob.type === "image/jpeg" &&
      b[0] === 255 &&
      b[1] === 216 &&
      b[2] === 255) ||
    (blob.type === "image/webp" &&
      String.fromCharCode(...b.slice(0, 4)) === "RIFF" &&
      String.fromCharCode(...b.slice(8)) === "WEBP");
  if (!valid)
    throw new Error("The image file type does not match its content.");
}
export async function GET(request: Request) {
  try {
    localOnly(request);
    const agent = new URL(request.url).searchParams.get("agent") || "";
    const r = await db()
      .prepare(
        "SELECT id,result,created FROM generations WHERE agent=? AND kind='mockup' ORDER BY created DESC LIMIT 30",
      )
      .bind(agent)
      .all();
    return Response.json(
      r.results.map((r) => ({ ...r, ...JSON.parse(String(r.result)) })),
    );
  } catch (e) {
    return fail(e);
  }
}
export async function POST(request: Request) {
  try {
    localOnly(request);
    if (Number(request.headers.get("content-length") || 0) > 18 * 1024 * 1024)
      throw new Error("Upload is too large.");
    const form = await request.formData();
    const agent = String(form.get("agent") || "");
    if (
      !(await db()
        .prepare("SELECT id FROM agents WHERE id=?")
        .bind(agent)
        .first())
    )
      throw new Error("Select a sales agent first.");
    const product = await db()
      .prepare("SELECT * FROM products WHERE id=?")
      .bind(String(form.get("productId") || ""))
      .first<Product>();
    if (!product) throw new Error("Select an inventory product.");
    const logo = form.get("logo");
    if (!(logo instanceof File)) throw new Error("Upload a logo first.");
    await checkImage(logo);
    let photo: Blob | FormDataEntryValue | null = form.get("photo");
    if (!(photo instanceof File) || !photo.size) {
      if (!product.image)
        throw new Error(
          "This product has no photograph. Upload one to continue.",
        );
      const url = new URL(product.image);
      if (
        url.protocol !== "https:" ||
        !["luxurytrd.com", "www.luxurytrd.com"].includes(url.hostname)
      )
        throw new Error("Upload a product photograph for this product.");
      const r = await fetch(url, {
        redirect: "error",
        signal: AbortSignal.timeout(20000),
      });
      if (!r.ok)
        throw new Error(
          "Could not load the product photograph. Upload it instead.",
        );
      if (Number(r.headers.get("content-length") || 0) > 8 * 1024 * 1024)
        throw new Error("Product photograph is too large.");
      photo = await r.blob();
    }
    await checkImage(photo as Blob);
    const key = secret("OPENAI_API_KEY");
    if (!key)
      throw new Error(
        "AI mockups need an OpenAI API key in the local environment.",
      );
    if (!env.BUCKET) throw new Error("Image storage is not configured.");
    const instruction = String(form.get("instruction") || "").slice(0, 1500);
    const payload = new FormData();
    payload.append(
      "model",
      secret("OPENAI_IMAGE_MODEL") || "gpt-image-2.5-flare",
    );
    payload.append("image[]", photo as Blob, "product.png");
    payload.append("image[]", logo, "logo.png");
    payload.append(
      "prompt",
      `Create one realistic product branding mockup. First image is the actual ${product.name}, second is the customer's logo. Preserve the exact product shape, material and colour. Place the supplied logo naturally on the product, following its surface perspective, lighting and texture. Preserve the logo's lettering, colours and proportions as faithfully as possible. Use a clean studio background. Do not add extra branding or unrelated objects. Requested placement and printing finish: ${instruction || "Centred on the front, professional printed finish"}.`,
    );
    payload.append("size", "1024x1024");
    payload.append("quality", "medium");
    payload.append("output_format", "png");
    const response = await fetch("https://api.openai.com/v1/images/edits", {
      method: "POST",
      headers: { Authorization: `Bearer ${key}` },
      body: payload,
      signal: AbortSignal.timeout(180000),
    });
    if (!response.ok)
      throw new Error(
        `Mockup generation failed (${response.status}). You can retry with the same images.`,
      );
    const result = (await response.json()) as {
      data?: { b64_json?: string }[];
    };
    const base64 = result.data?.[0]?.b64_json;
    if (!base64) throw new Error("No mockup image was returned.");
    const id = crypto.randomUUID(),
      path = `mockups/${id}.png`;
    const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    await env.BUCKET.put(path, bytes, {
      httpMetadata: { contentType: "image/png" },
    });
    const record = { productId: product.id, productName: product.name, path };
    await db()
      .prepare(
        "INSERT INTO generations (id,agent,kind,prompt,result,created) VALUES (?,?,?,?,?,?)",
      )
      .bind(
        id,
        agent,
        "mockup",
        instruction,
        JSON.stringify(record),
        new Date().toISOString(),
      )
      .run();
    return Response.json({ id, ...record });
  } catch (e) {
    return fail(e);
  }
}
