import { useState, type FormEvent, type ReactNode } from "react";
import { toast } from "sonner";
import {
  ArrowLeft,
  Check,
  Globe,
  Copy,
  Download,
  ExternalLink,
  UserPlus,
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
import { PROSPECT_STATUSES, type Prospect } from "@/lib/domain";
import { request } from "./http";

type AgentOption = { id: string; name: string; email: string | null };

type Props = {
  prospects: Prospect[];
  agents: AgentOption[];
  isAdmin: boolean;
  userAgentId: string | null;
  captureTokenSet: boolean;
  selectedId: string | null;
  onSelect: (id: string | null) => void;
  agentName: (id: string) => string;
  onRefresh: () => Promise<unknown>;
  onOpenCustomer: (customerId: string) => void | Promise<void>;
};

type Filter = "open" | "unassigned" | "converted" | "not_a_fit" | "all";

const statusLabel = (key: string) =>
  PROSPECT_STATUSES.find((s) => s.key === key)?.label ?? key;
const when = (iso: string) =>
  new Date(iso).toLocaleDateString("en-OM", { dateStyle: "medium" });
const safeUrl = (u: string) => (/^https?:\/\//i.test(u) ? u : "");

async function send(path: string, body?: Record<string, unknown>) {
  const r = await request(path, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body ?? {}),
  });
  const d = (await r.json().catch(() => ({}))) as Record<string, any>;
  if (!r.ok) {
    throw new Error(
      (d.errors && Object.values(d.errors as Record<string, string[]>).flat()[0]) ||
        d.error ||
        d.message ||
        "Something went wrong",
    );
  }
  return d;
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="field">
      <span>{label}</span>
      {children}
    </label>
  );
}

function Fields({ p }: { p?: Prospect }) {
  return (
    <div className="prospect-fields">
      <Field label="Name">
        <input name="name" maxLength={200} defaultValue={p?.name ?? ""} placeholder="Contact person" />
      </Field>
      <Field label="Job title">
        <input name="title" maxLength={200} defaultValue={p?.title ?? ""} />
      </Field>
      <Field label="Company">
        <input name="company" maxLength={200} defaultValue={p?.company ?? ""} />
      </Field>
      <Field label="Email">
        <input name="email" type="email" maxLength={254} defaultValue={p?.email ?? ""} />
      </Field>
      <Field label="Phone">
        <input name="phone" maxLength={50} defaultValue={p?.phone ?? ""} />
      </Field>
      <Field label="Website">
        <input name="website" maxLength={500} defaultValue={p?.website ?? ""} placeholder="company.com" />
      </Field>
      <Field label="LinkedIn">
        <input name="linkedinUrl" maxLength={500} defaultValue={p?.linkedin_url ?? ""} placeholder="linkedin.com/in/…" />
      </Field>
      <Field label="Location">
        <input name="location" maxLength={200} defaultValue={p?.location ?? ""} />
      </Field>
      <Field label="Notes">
        <textarea name="notes" rows={3} maxLength={5000} defaultValue={p?.notes ?? ""} />
      </Field>
    </div>
  );
}

function formValues(form: HTMLFormElement) {
  const f = new FormData(form);
  const out: Record<string, string> = {};
  f.forEach((v, k) => {
    out[k] = String(v).trim();
  });
  return out;
}

function CapturePanel({
  tokenSet,
  onRefresh,
}: {
  tokenSet: boolean;
  onRefresh: () => Promise<unknown>;
}) {
  const [token, setToken] = useState("");
  const [busy, setBusy] = useState(false);

  async function create() {
    if (
      tokenSet &&
      !window.confirm(
        "Creating a new token stops the old one working. Anyone using it must paste the new one. Continue?",
      )
    )
      return;
    setBusy(true);
    try {
      setToken((await send("/api/capture-token")).token);
      await onRefresh();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "Something went wrong");
    } finally {
      setBusy(false);
    }
  }
  async function revoke() {
    setBusy(true);
    try {
      await send("/api/capture-token/revoke");
      setToken("");
      await onRefresh();
      toast.success("Capture token revoked");
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "Something went wrong");
    } finally {
      setBusy(false);
    }
  }

  return (
    <details className="capture-card">
      <summary>
        <Globe size={16} /> Capture prospects from Chrome
      </summary>
      <p className="helper">
        Browse LinkedIn, a company website or a directory as normal. When you
        find a good lead, click the extension and review what it picked up, then
        send it here. It reads only the page you are on, only when you click.
      </p>
      <ol className="capture-steps">
        <li>
          <a href="/downloads/cloud-erp-capture.zip" download>
            <Download size={14} /> Download the extension
          </a>{" "}
          and unzip it.
        </li>
        <li>
          In Chrome open <code>chrome://extensions</code>, switch on{" "}
          <strong>Developer mode</strong>, click <strong>Load unpacked</strong>{" "}
          and choose the unzipped folder.
        </li>
        <li>
          Create your personal token below, open the extension, and paste the
          token. The address is <code>{window.location.origin}</code>.
        </li>
      </ol>
      <div className="capture-token">
        {token ? (
          <>
            <input readOnly value={token} aria-label="Capture token" onFocus={(e) => e.currentTarget.select()} />
            <button
              type="button"
              className="secondary"
              onClick={() => {
                void navigator.clipboard
                  ?.writeText(token)
                  .then(() => toast.success("Token copied"))
                  .catch(() => toast.error("Select the token and copy it manually"));
              }}
            >
              <Copy size={16} /> Copy
            </button>
          </>
        ) : (
          <span className="helper">
            {tokenSet
              ? "You have an active token. For security it can't be shown again."
              : "You have no token yet."}
          </span>
        )}
      </div>
      {token && (
        <p className="helper">
          Copy it now: this is the only time it is shown. Treat it like a
          password.
        </p>
      )}
      <div className="actions">
        <button type="button" className="secondary" disabled={busy} onClick={() => void create()}>
          {tokenSet || token ? "Replace token" : "Create token"}
        </button>
        {(tokenSet || token) && (
          <button type="button" className="text-button" disabled={busy} onClick={() => void revoke()}>
            Revoke
          </button>
        )}
      </div>
    </details>
  );
}

export default function ProspectsPanel(props: Props) {
  const [filter, setFilter] = useState<Filter>("open");
  const [search, setSearch] = useState("");
  const [adding, setAdding] = useState(false);
  const [busy, setBusy] = useState(false);
  const selected = props.prospects.find((p) => p.id === props.selectedId) ?? null;
  const unassigned = props.prospects.filter((p) => !p.agent).length;

  async function run(work: () => Promise<void>) {
    setBusy(true);
    try {
      await work();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "Something went wrong");
    } finally {
      setBusy(false);
    }
  }

  function add(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    const v = formValues(form);
    void run(async () => {
      const r = await send("/api/prospects", {
        ...v,
        agentId: v.agentId || undefined,
        assignToMe: v.assignTo !== "auto",
      });
      await props.onRefresh();
      if (r.duplicate === "customer") {
        toast.info("That email already belongs to a customer.");
      } else if (r.duplicate) {
        toast.info("That prospect was already captured.");
      } else {
        toast.success(
          r.mine
            ? "Prospect added to your list"
            : r.assignedTo
              ? `Prospect assigned to ${r.assignedTo}`
              : "Prospect added, waiting to be assigned",
        );
        form.reset();
        setAdding(false);
      }
    });
  }

  if (selected) {
    const canEdit = props.isAdmin || selected.agent === props.userAgentId;
    const mine = selected.agent === props.userAgentId;
    const done = selected.status === "converted";
    return (
      <section className="panel">
        <div className="section-head">
          <button className="secondary" onClick={() => props.onSelect(null)}>
            <ArrowLeft size={16} /> All prospects
          </button>
          <div className="actions">
            {done && selected.customer_id && (
              <button className="secondary" onClick={() => props.onOpenCustomer(selected.customer_id!)}>
                View customer
              </button>
            )}
            {canEdit && !done && selected.agent && (
              <button
                className="primary"
                disabled={busy}
                onClick={() =>
                  void run(async () => {
                    const r = await send(`/api/prospects/${selected.id}/convert`);
                    toast.success("Added to your customers");
                    await props.onOpenCustomer(r.customerId);
                  })
                }
              >
                <UserPlus size={16} /> Add to customers
              </button>
            )}
          </div>
        </div>
        <div className="order-head">
          <div>
            <p className="eyebrow">Prospect</p>
            <h2>{selected.name || selected.company}</h2>
            <p className="helper">
              {selected.agent ? `Assigned to ${props.agentName(selected.agent)}` : "Not assigned yet"}
              {" · "}
              {selected.source === "extension" ? "Captured" : "Added"} by{" "}
              {selected.captured_by || "someone"} on {when(selected.created)}
            </p>
          </div>
          <span className={"badge" + (done ? " badge-won" : selected.status === "not_a_fit" ? " badge-lost" : "")}>
            {statusLabel(selected.status)}
          </span>
        </div>
        <div className="prospect-links">
          {safeUrl(selected.linkedin_url) && (
            <a href={safeUrl(selected.linkedin_url)} target="_blank" rel="noopener noreferrer">
              <ExternalLink size={14} /> LinkedIn
            </a>
          )}
          {safeUrl(selected.website) && (
            <a href={safeUrl(selected.website)} target="_blank" rel="noopener noreferrer">
              <ExternalLink size={14} /> Website
            </a>
          )}
          {safeUrl(selected.source_url) && (
            <a href={safeUrl(selected.source_url)} target="_blank" rel="noopener noreferrer">
              <ExternalLink size={14} /> Where it was found
            </a>
          )}
          {selected.email && <a href={`mailto:${selected.email}`}>{selected.email}</a>}
          {selected.phone && <a href={`tel:${selected.phone.replace(/[^\d+]/g, "")}`}>{selected.phone}</a>}
        </div>
        {canEdit ? (
          <form
            key={selected.id + selected.updated}
            className="prospect-form"
            onSubmit={(e) => {
              e.preventDefault();
              const v = formValues(e.currentTarget);
              void run(async () => {
                await send(`/api/prospects/${selected.id}`, v);
                await props.onRefresh();
                toast.success("Prospect saved");
              });
            }}
          >
            <Fields p={selected} />
            {!done && (
              <Field label="Status">
                <select name="status" defaultValue={selected.status}>
                  <option value="new">New</option>
                  <option value="contacted">Contacted</option>
                  <option value="not_a_fit">Not a fit</option>
                </select>
              </Field>
            )}
            <div className="actions">
              <button className="primary" disabled={busy}>
                <Check size={16} /> Save
              </button>
            </div>
          </form>
        ) : (
          <p className="helper">This prospect belongs to another sales agent.</p>
        )}
        {props.isAdmin && (
          <div className="prospect-admin">
            <h3>Administrator</h3>
            <div className="actions">
              <select
                aria-label="Assign to"
                value={selected.agent ?? ""}
                disabled={busy}
                onChange={(e) =>
                  void run(async () => {
                    if (!e.target.value) return;
                    await send(`/api/prospects/${selected.id}/assign`, { agentId: e.target.value });
                    await props.onRefresh();
                    toast.success("Prospect reassigned");
                  })
                }
              >
                <option value="">Choose an agent…</option>
                {props.agents.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name}
                  </option>
                ))}
              </select>
              <button
                className="secondary"
                disabled={busy}
                onClick={() =>
                  void run(async () => {
                    await send(`/api/prospects/${selected.id}/assign`, { agentId: "auto" });
                    await props.onRefresh();
                    toast.success("Passed to the next agent in the rotation");
                  })
                }
              >
                Pass to next in rotation
              </button>
              <button
                className="secondary danger"
                disabled={busy}
                onClick={() => {
                  if (!window.confirm(`Delete ${selected.name || selected.company}? This cannot be undone.`)) return;
                  void run(async () => {
                    await send(`/api/prospects/${selected.id}/delete`);
                    props.onSelect(null);
                    await props.onRefresh();
                    toast.success("Prospect deleted");
                  });
                }}
              >
                Delete
              </button>
            </div>
          </div>
        )}
        {mine && selected.status === "new" && canEdit && (
          <p className="helper">Tip: set the status to Contacted once you have reached out.</p>
        )}
      </section>
    );
  }

  const needle = search.trim().toLowerCase();
  const shown = props.prospects.filter((p) => {
    const inFilter =
      filter === "all"
        ? true
        : filter === "unassigned"
          ? !p.agent
          : filter === "open"
            ? p.status === "new" || p.status === "contacted"
            : p.status === filter;
    return (
      inFilter &&
      (!needle ||
        [p.name, p.company, p.title, p.email, p.location].some((x) => x.toLowerCase().includes(needle)))
    );
  });
  const filters: [Filter, string][] = [
    ["open", "To work"],
    ...(props.isAdmin ? ([["unassigned", `Unassigned${unassigned ? ` (${unassigned})` : ""}`]] as [Filter, string][]) : []),
    ["converted", "Customers"],
    ["not_a_fit", "Not a fit"],
    ["all", "All"],
  ];

  return (
    <section className="panel">
      <div className="section-head">
        <div>
          <h2>Prospects</h2>
          <p className="helper">
            New leads land here, shared out fairly between the sales team. Reach
            out, then add the good ones to your customers.
          </p>
        </div>
        <div className="actions">
          <button className="primary" onClick={() => setAdding((v) => !v)}>
            <UserPlus size={16} /> {adding ? "Close" : "Add prospect"}
          </button>
        </div>
      </div>
      {adding && (
        <form className="prospect-form" onSubmit={add}>
          <Fields />
          {props.isAdmin ? (
            <Field label="Assign to">
              <select name="agentId" defaultValue="">
                <option value="">Next agent in the rotation</option>
                {props.agents.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name}
                  </option>
                ))}
              </select>
            </Field>
          ) : (
            <Field label="Assign to">
              <select name="assignTo" defaultValue="me">
                <option value="me">Me</option>
                <option value="auto">Next agent in the rotation</option>
              </select>
            </Field>
          )}
          <div className="actions">
            <button className="primary" disabled={busy}>
              <Check size={16} /> Save prospect
            </button>
          </div>
        </form>
      )}
      <div className="actions prospect-filters">
        {filters.map(([key, label]) => (
          <button key={key} className={filter === key ? "primary" : "secondary"} onClick={() => setFilter(key)}>
            {label}
          </button>
        ))}
        <input
          type="search"
          aria-label="Search prospects"
          placeholder="Search prospects"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>
      {shown.length ? (
        <Table className="responsive-table">
          <TableHeader>
            <TableRow>
              <TableHead>Prospect</TableHead>
              <TableHead>Company</TableHead>
              <TableHead>Contact</TableHead>
              {props.isAdmin && <TableHead>Assigned to</TableHead>}
              <TableHead>Status</TableHead>
              <TableHead>Added</TableHead>
              <TableHead>
                <span className="sr-only">Open</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {shown.map((p) => (
              <TableRow key={p.id}>
                <TableCell className="card-title">
                  <button className="text-button" onClick={() => props.onSelect(p.id)}>
                    {p.name || p.company}
                  </button>
                  {p.title && <small className="block">{p.title}</small>}
                </TableCell>
                <TableCell data-label="Company">{p.company || "—"}</TableCell>
                <TableCell data-label="Contact">{p.email || p.phone || "—"}</TableCell>
                {props.isAdmin && (
                  <TableCell data-label="Assigned to">{p.agent ? props.agentName(p.agent) : "Unassigned"}</TableCell>
                )}
                <TableCell data-label="Status">
                  <span className={"badge" + (p.status === "converted" ? " badge-won" : p.status === "not_a_fit" ? " badge-lost" : "")}>
                    {statusLabel(p.status)}
                  </span>
                </TableCell>
                <TableCell data-label="Added">{when(p.created)}</TableCell>
                <TableCell className="card-actions">
                  <button className="secondary" onClick={() => props.onSelect(p.id)}>
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
            <EmptyTitle>{props.prospects.length ? "Nothing matches" : "No prospects yet"}</EmptyTitle>
            <EmptyDescription>
              {props.prospects.length
                ? "Try another filter or search."
                : "Add one by hand, or install the Chrome extension below and capture leads as you browse."}
            </EmptyDescription>
          </EmptyHeader>
        </Empty>
      )}
      <CapturePanel tokenSet={props.captureTokenSet} onRefresh={props.onRefresh} />
    </section>
  );
}
