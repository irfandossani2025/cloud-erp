import { sqliteTable, text, integer, real } from "drizzle-orm/sqlite-core";
export const products = sqliteTable("products", {
  id: text("id").primaryKey(),
  sku: text("sku").notNull().unique(),
  name: text("name").notNull(),
  description: text("description").notNull().default(""),
  category: text("category").notNull().default(""),
  image: text("image").notNull().default(""),
  supplierId: text("supplier_id").unique(),
  supplierAed: integer("supplier_aed"),
  supplierStock: integer("supplier_stock"),
  supplierSync: text("supplier_sync"),
  warehouseStock: integer("warehouse_stock").notNull().default(0),
  saleBaisa: integer("sale_baisa"),
  costBaisa: integer("cost_baisa").notNull().default(0),
});
export const settings = sqliteTable("settings", {
  id: integer("id").primaryKey(),
  rate: real("rate").notNull(),
  company: text("company").notNull(),
  updated: text("updated").notNull(),
});
export const quotes = sqliteTable("quotes", {
  id: text("id").primaryKey(),
  number: integer("number").notNull().unique(),
  agent: text("agent").notNull(),
  customer: text("customer").notNull(),
  email: text("email").notNull().default(""),
  notes: text("notes").notNull().default(""),
  status: text("status").notNull().default("Draft"),
  rate: real("rate").notNull(),
  lines: text("lines").notNull(),
  total: integer("total").notNull(),
  created: text("created").notNull(),
  updated: text("updated").notNull(),
  revision: integer("revision").notNull().default(1),
});
export const agents = sqliteTable("agents", {
  id: text("id").primaryKey(),
  name: text("name").notNull(),
});
export const generations = sqliteTable("generations", {
  id: text("id").primaryKey(),
  agent: text("agent").notNull(),
  kind: text("kind").notNull(),
  prompt: text("prompt").notNull(),
  result: text("result").notNull(),
  created: text("created").notNull(),
});
