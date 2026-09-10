import test from "node:test";
import assert from "node:assert/strict";
import {
  calculateTotal,
  convertedCost,
  normaliseSupplier,
  money,
} from "../lib/domain.ts";
test("AED cents convert to OMR baisa with one final rounding", () => {
  assert.equal(
    convertedCost({ supplier_aed: 1100, cost_baisa: 0 }, 0.104699),
    1152,
  );
  assert.equal(
    convertedCost({ supplier_aed: null, cost_baisa: 1750 }, 0.104699),
    1750,
  );
  assert.equal(money(1152), "1.152");
});
test("quotation sums use integer baisa and reject invalid quantities and overflow", () => {
  assert.equal(
    calculateTotal([
      { quantity: 300, unitBaisa: 1152 },
      { quantity: 10, unitBaisa: 105 },
    ]),
    346650,
  );
  assert.throws(() => calculateTotal([{ quantity: 1.5, unitBaisa: 100 }]));
  assert.throws(() => calculateTotal([{ quantity: 1, unitBaisa: -1 }]));
  assert.throws(() =>
    calculateTotal([{ quantity: 1000000, unitBaisa: 1000000000 }]),
  );
});
test("supplier data preserves unknown availability and rejects malformed prices", () => {
  const base = {
    id: 1,
    name: "Bottle",
    sku: "BT01",
    price: "11.00",
    stock_quantity: null,
  };
  const p = normaliseSupplier([base])[0];
  assert.equal(p.price, 1100);
  assert.equal(p.stock, null);
  assert.throws(() => normaliseSupplier([{ ...base, price: "not a price" }]));
  assert.throws(() => normaliseSupplier([{ ...base, stock_quantity: -1 }]));
  assert.throws(() => normaliseSupplier([{ ...base, sku: "" }]));
  assert.throws(() => normaliseSupplier({ products: [base] }));
});
