import { db, secret, localOnly, jsonBody, fail } from "@/lib/server";
import {
  calculateTotal,
  convertedCost,
  normaliseSupplier,
  DEFAULT_AED_OMR_RATE,
  type Product,
  type Line,
} from "@/lib/domain";
import { z } from "zod";
export const dynamic = "force-dynamic";
const short = z.string().trim().min(1).max(200);
const amount = z.number().int().min(0).max(1000000000);
const productSchema = z.object({
  name: short,
  sku: short,
  description: z.string().max(5000).default(""),
  category: z.string().max(1000).default(""),
  warehouseStock: z.number().int().min(0).max(1000000),
  saleBaisa: amount.nullable(),
  costBaisa: amount,
  image: z
    .string()
    .max(2000)
    .refine((v) => !v || v.startsWith("https://"), "Image must be an HTTPS URL")
    .default(""),
});
async function allProducts() {
  return (
    await db()
      .prepare("SELECT * FROM products ORDER BY name COLLATE NOCASE")
      .all<Product>()
  ).results;
}
export async function GET(request: Request) {
  try {
    localOnly(request);
    const [products, quotes, agents, settings] = await Promise.all([
      allProducts(),
      db().prepare("SELECT * FROM quotes ORDER BY number DESC").all(),
      db().prepare("SELECT * FROM agents ORDER BY name").all(),
      db().prepare("SELECT * FROM settings WHERE id=1").first(),
    ]);
    return Response.json({
      products,
      quotes: quotes.results.map((q) => ({
        ...q,
        lines: JSON.parse(String(q.lines)),
      })),
      agents: agents.results,
      settings: settings || {
        rate: DEFAULT_AED_OMR_RATE,
        company: "Cloud ERP",
        updated: null,
      },
      supplierConfigured: !!(
        secret("LUXURY_API_USERNAME") && secret("LUXURY_API_PASSWORD")
      ),
      aiConfigured: !!secret("GEMINI_API_KEY"),
    });
  } catch (e) {
    return fail(e);
  }
}
export async function POST(request: Request) {
  try {
    localOnly(request);
    const b = await jsonBody(request);
    const now = new Date().toISOString();
    if (b.action === "product") {
      const p = productSchema.parse(b.product);
      const id = crypto.randomUUID();
      await db()
        .prepare(
          "INSERT INTO products (id,sku,name,description,category,image,warehouse_stock,sale_baisa,cost_baisa) VALUES (?,?,?,?,?,?,?,?,?)",
        )
        .bind(
          id,
          p.sku,
          p.name,
          p.description,
          p.category,
          p.image,
          p.warehouseStock,
          p.saleBaisa,
          p.costBaisa,
        )
        .run();
      return Response.json({ id });
    }
    if (b.action === "stock") {
      const v = z
        .object({
          id: short,
          warehouseStock: z.number().int().min(0).max(1000000),
          saleBaisa: amount.nullable(),
        })
        .parse(b);
      await db()
        .prepare(
          "UPDATE products SET warehouse_stock=?,sale_baisa=? WHERE id=?",
        )
        .bind(v.warehouseStock, v.saleBaisa, v.id)
        .run();
      return Response.json({ ok: true });
    }
    if (b.action === "agent") {
      const name = short.parse(b.name);
      const id = crypto.randomUUID();
      await db()
        .prepare("INSERT INTO agents (id,name) VALUES (?,?)")
        .bind(id, name)
        .run();
      return Response.json({ id });
    }
    if (b.action === "settings") {
      const v = z
        .object({ rate: z.number().positive().max(100), company: short })
        .parse(b);
      await db()
        .prepare(
          "INSERT INTO settings (id,rate,company,updated) VALUES (1,?,?,?) ON CONFLICT(id) DO UPDATE SET rate=excluded.rate,company=excluded.company,updated=excluded.updated",
        )
        .bind(v.rate, v.company, now)
        .run();
      return Response.json({ ok: true });
    }
    if (b.action === "sync") {
      const username = secret("LUXURY_API_USERNAME"),
        password = secret("LUXURY_API_PASSWORD");
      if (!username || !password)
        throw new Error(
          "Add supplier credentials to the local environment to enable sync.",
        );
      const response = await fetch(
        "https://luxurytrd.com/wp-json/api/v1/products",
        {
          headers: {
            Authorization: `Basic ${btoa(`${username}:${password}`)}`,
            "Content-Type": "application/json",
          },
          signal: AbortSignal.timeout(45000),
        },
      );
      if (!response.ok)
        throw new Error(
          `Supplier connection failed (${response.status}). Existing products are unchanged.`,
        );
      const rows = normaliseSupplier(await response.json());
      if (!rows.length)
        throw new Error(
          "Supplier returned an empty catalogue. Existing products are unchanged.",
        );
      const ids = new Set(),
        skus = new Set();
      for (const r of rows) {
        if (ids.has(r.supplierId) || skus.has(r.sku))
          throw new Error(
            "Supplier returned duplicate products. Existing catalogue unchanged.",
          );
        ids.add(r.supplierId);
        skus.add(r.sku);
      }
      const existing = await allProducts();
      const statements = [];
      for (const p of rows) {
        const bySupplier = existing.find((e) => e.supplier_id === p.supplierId);
        const bySku = existing.find((e) => e.sku === p.sku);
        if (bySku && bySku.id !== bySupplier?.id)
          throw new Error(
            `SKU ${p.sku} already exists locally. Resolve the duplicate before syncing.`,
          );
        statements.push(
          db()
            .prepare(
              "INSERT INTO products (id,sku,name,description,category,image,supplier_id,supplier_aed,supplier_stock,supplier_sync) VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(supplier_id) DO UPDATE SET sku=excluded.sku,name=excluded.name,description=excluded.description,category=excluded.category,image=excluded.image,supplier_aed=excluded.supplier_aed,supplier_stock=excluded.supplier_stock,supplier_sync=excluded.supplier_sync",
            )
            .bind(
              crypto.randomUUID(),
              p.sku,
              p.name,
              p.description,
              p.category,
              p.image,
              p.supplierId,
              p.price,
              p.stock,
              now,
            ),
        );
      }
      await db().batch(statements);
      return Response.json({ count: rows.length });
    }
    if (b.action === "quote") {
      const q = z
        .object({
          id: z.string().optional(),
          revision: z.number().int().optional(),
          agent: short,
          customer: short,
          email: z.union([z.literal(""), z.string().email()]),
          notes: z.string().max(5000),
          rate: z.number().positive().max(100),
          lines: z
            .array(
              z.object({
                productId: short,
                quantity: z.number().int().min(1).max(1000000),
                unitBaisa: amount,
                branding: z.string().max(1000),
              }),
            )
            .min(1)
            .max(200),
        })
        .parse(b.quote);
      if (
        !(await db()
          .prepare("SELECT id FROM agents WHERE id=?")
          .bind(q.agent)
          .first())
      )
        throw new Error("Select a sales agent.");
      const saved = q.id
        ? await db()
            .prepare("SELECT rate,lines FROM quotes WHERE id=?")
            .bind(q.id)
            .first<{ rate: number; lines: string }>()
        : null;
      if (q.id && !saved) throw new Error("Quotation not found");
      const rate = saved?.rate ?? q.rate;
      const previous: Line[] = saved ? JSON.parse(saved.lines) : [];
      const products = await allProducts();
      const lines: Line[] = q.lines.map((l) => {
        const p = products.find((p) => p.id === l.productId);
        if (!p) throw new Error("A quotation product no longer exists.");
        const old = previous.find((x) => x.productId === l.productId);
        return {
          ...l,
          name: old?.name ?? p.name,
          sku: old?.sku ?? p.sku,
          costBaisa: old?.costBaisa ?? convertedCost(p, rate),
        };
      });
      const total = calculateTotal(lines);
      if (q.id) {
        const result = await db()
          .prepare(
            "UPDATE quotes SET customer=?,email=?,notes=?,lines=?,total=?,updated=?,revision=revision+1 WHERE id=? AND revision=? AND agent=? AND status=?",
          )
          .bind(
            q.customer,
            q.email,
            q.notes,
            JSON.stringify(lines),
            total,
            now,
            q.id,
            q.revision || 0,
            q.agent,
            "Draft",
          )
          .run();
        if (!result.meta.changes)
          throw new Error(
            "Quotation changed or is no longer a draft. Reopen it before editing.",
          );
        return Response.json({ id: q.id });
      }
      const id = crypto.randomUUID();
      await db()
        .prepare(
          "INSERT INTO quotes (id,number,agent,customer,email,notes,rate,lines,total,created,updated) VALUES (?,(SELECT COALESCE(MAX(number),0)+1 FROM quotes),?,?,?,?,?,?,?,?,?)",
        )
        .bind(
          id,
          q.agent,
          q.customer,
          q.email,
          q.notes,
          q.rate,
          JSON.stringify(lines),
          total,
          now,
          now,
        )
        .run();
      return Response.json({ id });
    }
    if (b.action === "status") {
      const v = z
        .object({
          id: short,
          status: z.enum(["Draft", "Reviewed", "Accepted", "Declined"]),
          revision: z.number().int(),
        })
        .parse(b);
      const r = await db()
        .prepare(
          "UPDATE quotes SET status=?,revision=revision+1,updated=? WHERE id=? AND revision=?",
        )
        .bind(v.status, now, v.id, v.revision)
        .run();
      if (!r.meta.changes)
        throw new Error("Quotation changed. Refresh and try again.");
      return Response.json({ ok: true });
    }
    throw new Error("Unknown action");
  } catch (e) {
    return fail(e);
  }
}
