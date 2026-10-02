import { useState, type FormEvent } from "react";
import { toast } from "sonner";
import {
  AlertTriangle,
  ArrowLeft,
  Check,
  FileText,
  Paperclip,
  Receipt,
  Truck,
} from "lucide-react";
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableCell,
  TableHead,
} from "@/components/ui/table";
import {
  Empty,
  EmptyHeader,
  EmptyTitle,
  EmptyDescription,
} from "@/components/ui/empty";
import {
  money,
  ORDER_STAGES,
  type Company,
  type DeliveryNote,
  type Invoice,
  type Order,
  type OrderEvent,
  type Quote,
} from "@/lib/domain";
import { request } from "./http";

type Props = {
  orders: Order[];
  events: OrderEvent[];
  quotes: Quote[];
  deliveryNotes: DeliveryNote[];
  invoices: Invoice[];
  companies: Company[];
  vatRate: number;
  selectedId: string | null;
  onSelect: (id: string | null) => void;
  canAct: (order: Order) => boolean;
  agentName: (id: string) => string;
  onRefresh: () => Promise<unknown>;
  onOpenQuote: (quote: Quote) => void;
  onOpenDeliveryNote: (note: DeliveryNote) => void;
  onOpenInvoice: (invoice: Invoice) => void;
};

const pad = (n: number) => String(n).padStart(4, "0");
const stageIndex = (key: string) =>
  ORDER_STAGES.findIndex((s) => s.key === key);
const stageLabel = (key: string) =>
  ORDER_STAGES.find((s) => s.key === key)?.label ?? key;
const when = (iso: string) =>
  new Date(iso).toLocaleString("en-OM", {
    dateStyle: "medium",
    timeStyle: "short",
  });
const size = (bytes: number | null) =>
  bytes === null
    ? ""
    : bytes > 1048576
      ? `${(bytes / 1048576).toFixed(1)} MB`
      : `${Math.max(1, Math.round(bytes / 1024))} KB`;

async function failure(r: Response) {
  const d = (await r.json().catch(() => ({}))) as {
    error?: string;
    message?: string;
    errors?: Record<string, string[]>;
  };
  return (
    (d.errors && Object.values(d.errors).flat()[0]) ||
    d.error ||
    d.message ||
    "Something went wrong"
  );
}

function StepForm({
  order,
  onDone,
}: {
  order: Order;
  onDone: () => Promise<unknown>;
}) {
  const index = stageIndex(order.stage);
  const stage = ORDER_STAGES[index];
  const next = ORDER_STAGES[index + 1];
  const [note, setNote] = useState("");
  const [poNumber, setPoNumber] = useState(order.po_number ?? "");
  const [poAmount, setPoAmount] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);

  async function send(path: string, body: FormData) {
    setBusy(true);
    try {
      const r = await request(`/api/orders/${order.id}/${path}`, {
        method: "POST",
        body,
      });
      if (!r.ok) throw new Error(await failure(r));
      await onDone();
      return true;
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "Something went wrong");
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function advance(e: FormEvent) {
    e.preventDefault();
    const body = new FormData();
    body.set("stage", order.stage);
    if (note.trim()) body.set("note", note.trim());
    if (file) body.set("file", file);
    if (order.stage === "sales_order") {
      if (poNumber.trim()) body.set("poNumber", poNumber.trim());
      if (poAmount.trim())
        body.set("poAmountBaisa", String(Math.round(Number(poAmount) * 1000)));
    }
    if (await send("advance", body)) toast.success(`${stage.label} complete`);
  }

  async function addNote() {
    const body = new FormData();
    if (note.trim()) body.set("note", note.trim());
    if (file) body.set("file", file);
    if (await send("note", body)) toast.success("Added to the timeline");
  }

  return (
    <form className="order-step-form" onSubmit={advance}>
      <div>
        <p className="eyebrow">Current step</p>
        <h3>{stage.label}</h3>
        <p className="helper">{stage.hint}</p>
      </div>
      {order.stage === "sales_order" && (
        <div className="form-grid">
          <label className="field">
            <span>Customer PO number (optional)</span>
            <input
              value={poNumber}
              onChange={(e) => setPoNumber(e.target.value)}
              maxLength={100}
            />
          </label>
          <label className="field">
            <span>PO amount · OMR (optional)</span>
            <input
              type="number"
              min="0"
              step="0.001"
              value={poAmount}
              onChange={(e) => setPoAmount(e.target.value)}
            />
          </label>
        </div>
      )}
      <label className="field">
        <span>
          {stage.file === "any"
            ? "Purchase order or email confirmation (required)"
            : stage.file === "image"
              ? "Photo (required)"
              : "Attach a file or photo (optional)"}
        </span>
        <input
          key={order.stage}
          type="file"
          accept={
            stage.file === "image"
              ? "image/png,image/jpeg,image/webp"
              : undefined
          }
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        />
      </label>
      <label className="field">
        <span>Note (optional)</span>
        <textarea
          rows={2}
          maxLength={2000}
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
      </label>
      <div className="actions">
        <button className="primary" disabled={busy}>
          <Check size={16} />
          {busy ? "Saving…" : `Complete step · move to ${next.label}`}
        </button>
        <button
          type="button"
          className="secondary"
          disabled={busy || (!note.trim() && !file)}
          onClick={() => void addNote()}
        >
          <Paperclip size={16} /> Add to timeline only
        </button>
      </div>
    </form>
  );
}

export default function OrdersPanel(props: Props) {
  const [filter, setFilter] = useState<"active" | "completed" | "all">(
    "active",
  );
  const selected = props.orders.find((o) => o.id === props.selectedId) ?? null;
  const companyName = (id: string | null) => {
    const c = props.companies.find((x) => x.id === id);
    return c ? c.trading_name || c.name : "—";
  };

  if (selected) {
    const quote = props.quotes.find((q) => q.id === selected.quote_id);
    const note = props.deliveryNotes.find((n) => n.quote_id === selected.quote_id);
    const invoice = props.invoices.find((i) => i.quote_id === selected.quote_id);
    const current = stageIndex(selected.stage);
    const events = props.events
      .filter((e) => e.order_id === selected.id)
      .slice()
      .reverse();
    const withVat = quote ? Math.round(quote.total * (1 + props.vatRate)) : 0;
    const poMismatch =
      quote &&
      selected.po_amount_baisa !== null &&
      Math.abs(selected.po_amount_baisa - quote.total) > 1 &&
      Math.abs(selected.po_amount_baisa - withVat) > 1;

    return (
      <section className="panel">
        <div className="section-head">
          <button className="text-button" onClick={() => props.onSelect(null)}>
            <ArrowLeft size={16} /> All orders
          </button>
          <div className="actions">
            {quote && (
              <button className="secondary" onClick={() => props.onOpenQuote(quote)}>
                <FileText size={16} /> Quotation
              </button>
            )}
            {note && (
              <button
                className="secondary"
                onClick={() => props.onOpenDeliveryNote(note)}
              >
                <Truck size={16} /> Delivery note
              </button>
            )}
            {invoice && (
              <button
                className="secondary"
                onClick={() => props.onOpenInvoice(invoice)}
              >
                <Receipt size={16} /> Invoice · {invoice.status}
              </button>
            )}
          </div>
        </div>
        <div className="order-head">
          <div>
            <p className="eyebrow">Order SO-{pad(selected.number)}</p>
            <h2>{selected.customer}</h2>
            <p className="helper">
              {companyName(selected.company_id)} · {props.agentName(selected.agent)}
              {selected.po_number ? ` · PO ${selected.po_number}` : ""}
              {quote ? ` · OMR ${money(quote.total)} excl. VAT` : ""}
            </p>
          </div>
          <span
            className={
              "badge" + (selected.stage === "completed" ? " badge-won" : "")
            }
          >
            {stageLabel(selected.stage)}
          </span>
        </div>
        {poMismatch && quote && selected.po_amount_baisa !== null && (
          <p className="warning">
            <AlertTriangle size={14} /> The PO amount (OMR{" "}
            {money(selected.po_amount_baisa)}) matches neither the quotation (OMR{" "}
            {money(quote.total)}) nor the quotation with VAT (OMR {money(withVat)}
            ). Check it before passing QC.
          </p>
        )}
        <ol className="order-stepper">
          {ORDER_STAGES.slice(0, -1).map((s, i) => (
            <li
              key={s.key}
              className={
                i < current ? "done" : i === current ? "current" : undefined
              }
            >
              <span className="dot">{i < current ? <Check size={12} /> : i + 1}</span>
              <span className="name">{s.short}</span>
            </li>
          ))}
        </ol>
        {props.canAct(selected) && selected.stage !== "completed" && (
          <StepForm
            key={selected.id + selected.stage}
            order={selected}
            onDone={props.onRefresh}
          />
        )}
        {selected.stage === "completed" && (
          <p className="helper">
            This order is complete. Track the invoice payment from the Accounts
            tab.
          </p>
        )}
        <div className="section-head">
          <h2>Timeline</h2>
        </div>
        {events.length ? (
          <div className="timeline">
            {events.map((e) => (
              <article className="timeline-item" key={e.id}>
                <div className="timeline-meta">
                  <span className={"badge" + (e.kind === "stage" ? " badge-won" : "")}>
                    {e.kind === "stage"
                      ? `${stageLabel(e.stage)} · done`
                      : `Note · ${stageLabel(e.stage)}`}
                  </span>
                  <small>
                    {e.actor} · {when(e.created)}
                  </small>
                </div>
                {e.note && <p className="preserve-lines">{e.note}</p>}
                {e.file_name && (
                  <a
                    className="timeline-file"
                    href={`/api/order-files/${e.id}`}
                    target="_blank"
                    rel="noreferrer"
                  >
                    {e.file_mime?.startsWith("image/") ? (
                      <img src={`/api/order-files/${e.id}`} alt={e.file_name} />
                    ) : (
                      <Paperclip size={14} />
                    )}
                    <span>
                      {e.file_name} <small>{size(e.file_size)}</small>
                    </span>
                  </a>
                )}
              </article>
            ))}
          </div>
        ) : (
          <p className="helper">Nothing recorded yet.</p>
        )}
      </section>
    );
  }

  const shown = props.orders.filter((o) =>
    filter === "all"
      ? true
      : filter === "completed"
        ? o.stage === "completed"
        : o.stage !== "completed",
  );

  return (
    <section className="panel">
      <div className="section-head">
        <div>
          <h2>Orders</h2>
          <p className="helper">
            A quotation becomes an order as soon as it is marked Won. Track it
            from the customer's PO through production, transport and QC to
            delivery.
          </p>
        </div>
        <div className="actions">
          {(["active", "completed", "all"] as const).map((f) => (
            <button
              key={f}
              className={filter === f ? "primary" : "secondary"}
              onClick={() => setFilter(f)}
            >
              {f === "active" ? "In progress" : f === "completed" ? "Completed" : "All"}
            </button>
          ))}
        </div>
      </div>
      {shown.length ? (
        <Table className="responsive-table">
          <TableHeader>
            <TableRow>
              <TableHead>Order</TableHead>
              <TableHead>Customer</TableHead>
              <TableHead>Company</TableHead>
              <TableHead>Agent</TableHead>
              <TableHead>Step</TableHead>
              <TableHead>Updated</TableHead>
              <TableHead>
                <span className="sr-only">Open</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {shown.map((o) => (
              <TableRow key={o.id}>
                <TableCell className="card-title">
                  <button className="text-button" onClick={() => props.onSelect(o.id)}>
                    SO-{pad(o.number)}
                  </button>
                </TableCell>
                <TableCell data-label="Customer">{o.customer}</TableCell>
                <TableCell data-label="Company">{companyName(o.company_id)}</TableCell>
                <TableCell data-label="Agent">{props.agentName(o.agent)}</TableCell>
                <TableCell data-label="Step">
                  <span
                    className={"badge" + (o.stage === "completed" ? " badge-won" : "")}
                  >
                    {stageLabel(o.stage)}
                  </span>
                </TableCell>
                <TableCell data-label="Updated">
                  {new Date(o.updated).toLocaleDateString("en-OM")}
                </TableCell>
                <TableCell className="card-actions">
                  <button className="secondary" onClick={() => props.onSelect(o.id)}>
                    Open
                  </button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      ) : (
        <Empty>
          <EmptyHeader>
            <EmptyTitle>
              {filter === "completed" ? "No completed orders yet" : "No orders in progress"}
            </EmptyTitle>
            <EmptyDescription>
              Mark a quotation as Won (Set outcome) and its order appears here.
            </EmptyDescription>
          </EmptyHeader>
        </Empty>
      )}
    </section>
  );
}
