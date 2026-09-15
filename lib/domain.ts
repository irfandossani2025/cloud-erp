// Indicative cross-rate: USD 2.6008 per OMR and AED/USD midpoint 3.6725.
// Sources and limitations: docs/currency.md. Editable business costing rate, not a bank quote.
export const DEFAULT_AED_OMR_RATE = 0.104699;
export type Product = {
  id: string;
  sku: string;
  name: string;
  description: string;
  category: string;
  image: string;
  supplier_id: string | null;
  supplier_aed: number | null;
  supplier_stock: number | null;
  supplier_sync: string | null;
  warehouse_stock: number;
  sale_baisa: number | null;
  cost_baisa: number;
};
export type SalesGoal = {
  id: string;
  agent_id: string;
  period: string;
  target_baisa: number;
  created: string;
  updated: string;
};
export type Company = {
  id: string;
  key: string;
  name: string;
  trading_name: string | null;
  vat_number: string | null;
  logo_path: string;
};
export type Line = {
  productId: string;
  name: string;
  sku: string;
  quantity: number;
  unitBaisa: number;
  costBaisa: number;
  branding: string;
};
export type Quote = {
  id: string;
  number: number;
  agent: string;
  company_id: string;
  customer: string;
  email: string;
  notes: string;
  status: string;
  rate: number;
  lines: Line[];
  total: number;
  created: string;
  updated: string;
  revision: number;
  mockup_status: string;
  mockup_generation_id: string | null;
  mockup_approved_at: string | null;
  pricing_status: string;
  priced_by: string | null;
  priced_at: string | null;
  price_unlocked_by_admin: boolean;
  outcome: string | null;
  outcome_reason: string | null;
  outcome_at: string | null;
};
export const CRM_STAGES = [
  "New Lead",
  "Contacted",
  "Qualified",
  "Proposal Sent",
  "Won",
  "Lost",
] as const;
export type Customer = {
  id: string;
  agent: string;
  company: string;
  contact_name: string;
  email: string | null;
  phone: string | null;
  address: string | null;
  stage: string;
  notes: string;
  follow_up_at: string | null;
  created: string;
  updated: string;
};
export type CustomerActivity = {
  id: string;
  customer_id: string;
  agent: string;
  type: string;
  notes: string;
  created: string;
};
export type DeliveryLine = {
  productId: string;
  name: string;
  sku: string;
  quantity: number;
};
export type DeliveryNote = {
  id: string;
  number: number;
  quote_id: string;
  agent: string;
  company_id: string;
  customer: string;
  address: string;
  po_number: string | null;
  lines: DeliveryLine[];
  notes: string;
  status: string;
  created: string;
  updated: string;
};
export type InvoiceLine = {
  productId: string;
  name: string;
  sku: string;
  quantity: number;
  unitBaisa: number;
};
export type Invoice = {
  id: string;
  number: number;
  quote_id: string;
  agent: string;
  company_id: string;
  customer: string;
  email: string;
  po_number: string | null;
  lines: InvoiceLine[];
  subtotal: number;
  vat_baisa: number;
  total: number;
  status: string;
  notes: string;
  due_date: string | null;
  paid_at: string | null;
  marked_paid_by: string | null;
  created: string;
  updated: string;
};
export const money = (baisa: number) =>
  new Intl.NumberFormat("en-OM", {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  }).format(baisa / 1000);
export const convertedCost = (p: Product, rate: number) =>
  p.supplier_aed === null
    ? p.cost_baisa
    : Math.round(p.supplier_aed * rate * 10);
export function calculateTotal(lines: Line[]) {
  let total = 0;
  for (const l of lines) {
    if (
      !Number.isSafeInteger(l.quantity) ||
      l.quantity < 1 ||
      l.quantity > 1000000 ||
      !Number.isSafeInteger(l.unitBaisa) ||
      l.unitBaisa < 0 ||
      l.unitBaisa > 1000000000
    )
      throw new Error("Invalid quantity or price");
    total += l.quantity * l.unitBaisa;
  }
  if (!Number.isSafeInteger(total) || total > 1000000000000)
    throw new Error("Quotation total exceeds supported range");
  return total;
}
export function normaliseSupplier(raw: unknown) {
  if (!Array.isArray(raw) || raw.length > 20000)
    throw new Error("Supplier returned an invalid catalogue");
  return raw.map((r) => {
    if (
      !r ||
      typeof r !== "object" ||
      !Number.isInteger(r.id) ||
      typeof r.name !== "string" ||
      !r.name.trim() ||
      typeof r.sku !== "string" ||
      !r.sku.trim()
    )
      throw new Error("Supplier returned an invalid product");
    const price =
      r.price === null || r.price === "" || r.price === undefined
        ? null
        : Number(r.price);
    if (
      price !== null &&
      (!Number.isFinite(price) || price < 0 || price > 10000000)
    )
      throw new Error("Supplier returned an invalid price");
    const stock =
      r.stock_quantity === null || r.stock_quantity === undefined
        ? null
        : Number(r.stock_quantity);
    if (stock !== null && (!Number.isSafeInteger(stock) || stock < 0))
      throw new Error("Supplier returned an invalid stock quantity");
    const image =
      Array.isArray(r.images) &&
      typeof r.images[0] === "string" &&
      r.images[0].startsWith("https://")
        ? r.images[0]
        : "";
    return {
      supplierId: String(r.id),
      sku: r.sku.trim(),
      name: r.name.trim(),
      description: String(r.description || "").slice(0, 5000),
      category: Array.isArray(r.categories)
        ? r.categories.join(", ").slice(0, 1000)
        : "",
      image,
      price: price === null ? null : Math.round(price * 100),
      stock,
    };
  });
}
