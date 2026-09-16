import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type FormEvent,
  type ReactNode,
} from "react";
import {
  FileText,
  Package,
  Sparkles,
  Plus,
  Search,
  RefreshCw,
  Settings,
  Trash2,
  ArrowLeft,
  Check,
  ImageIcon,
  Download,
  Printer,
  Users,
  LogOut,
  Contact,
  Receipt,
  Truck,
  Phone,
  Mail,
  CalendarClock,
  CheckCircle2,
  Circle,
  MessageCircle,
  Paperclip,
  SendHorizontal,
  File as FileIcon,
  X,
  Unlock,
  DollarSign,
  Wallet,
  AlertTriangle,
  Flag,
  BarChart3,
  LayoutDashboard,
  Target,
  ListChecks,
  TrendingUp,
  PieChart as PieChartIcon,
} from "lucide-react";
import { request } from "./http";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
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
import { Progress } from "@/components/ui/progress";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
} from "@/components/ui/chart";
import { PieChart, Pie, Cell } from "recharts";
import { Toaster } from "@/components/ui/sonner";
import { toast } from "sonner";
import {
  money,
  convertedCost,
  DEFAULT_AED_OMR_RATE,
  CRM_STAGES,
  type Product,
  type Quote,
  type Line,
  type Customer,
  type CustomerActivity,
  type DeliveryNote,
  type Invoice,
  type Company,
  type SalesGoal,
} from "@/lib/domain";
type Agent = {
  id: string;
  name: string;
  email: string | null;
  role: string | null;
};
type State = {
  products: Product[];
  quotes: Quote[];
  agents: Agent[];
  companies: Company[];
  customers: Customer[];
  customerActivities: CustomerActivity[];
  deliveryNotes: DeliveryNote[];
  invoices: Invoice[];
  salesGoals: SalesGoal[];
  settings: {
    company: string;
    rate: number;
    vat_number: string | null;
    updated: string | null;
  };
  vatRate: number;
  supplierConfigured: boolean;
  aiConfigured: boolean;
  isAdmin: boolean;
  userRole: string | null;
  userName: string;
  userAgentId: string;
};
type Draft = {
  id?: string;
  revision?: number;
  agent: string;
  companyId: string;
  customerId: string | null;
  customer: string;
  email: string;
  notes: string;
  rate: number;
  lines: Line[];
  pricingStatus: string;
  priceUnlocked: boolean;
};
type Mockup = {
  id: string;
  productId: string;
  productName: string;
  path: string;
  created?: string;
  quote_id?: string | null;
};
type DirectoryAgent = { id: string; name: string };
type ConversationSummary = {
  id: string;
  isGroup: boolean;
  title: string;
  participants: { agentId: string; name: string }[];
  lastMessage: { body: string | null; senderAgentId: string; created: string } | null;
  unreadCount: number;
  updated: string;
};
type MessageAttachment = { id: string; filename: string; mime: string; size: number };
type ThreadMessage = {
  id: string;
  senderAgentId: string;
  senderName: string;
  body: string | null;
  created: string;
  attachments: MessageAttachment[];
};
const initial: State = {
  products: [],
  quotes: [],
  agents: [],
  companies: [],
  customers: [],
  customerActivities: [],
  deliveryNotes: [],
  invoices: [],
  salesGoals: [],
  settings: {
    company: "Cloud ERP",
    rate: DEFAULT_AED_OMR_RATE,
    vat_number: null,
    updated: null,
  },
  vatRate: 0.05,
  supplierConfigured: false,
  aiConfigured: false,
  isAdmin: false,
  userRole: null,
  userName: "",
  userAgentId: "",
};
async function api(action: string, payload: object = {}) {
  const r = await request("/api/erp", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action, ...payload }),
  });
  const data = (await r.json()) as {
    id: string;
    count: number;
    invoiceId?: string | null;
    error?: string;
  };
  if (!r.ok) throw new Error(data.error || "Request failed");
  return data;
}
function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="field">
      <span>{label}</span>
      {children}
    </label>
  );
}
function Choice({
  value,
  onChange,
  placeholder,
  items,
  disabled = false,
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder: string;
  items: { id: string; name: string }[];
  disabled?: boolean;
}) {
  return (
    <Select value={value} onValueChange={onChange} disabled={disabled}>
      <SelectTrigger className="choice" aria-label={placeholder}>
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        {items.map((i) => (
          <SelectItem key={i.id} value={i.id}>
            {i.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
const csrfToken = () =>
  document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
    ?.content || "";
function formatBytes(bytes: number) {
  if (bytes < 1024) return bytes + " B";
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + " KB";
  return (bytes / (1024 * 1024)).toFixed(1) + " MB";
}
function Blank({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Empty className="empty">
      <EmptyHeader>
        <EmptyTitle>{title}</EmptyTitle>
        <EmptyDescription>{children}</EmptyDescription>
      </EmptyHeader>
    </Empty>
  );
}
export default function Home() {
  const [data, setData] = useState<State>(initial),
    [loaded, setLoaded] = useState(false),
    [loadError, setLoadError] = useState(""),
    [tab, setTab] = useState("dashboard"),
    [agent, setAgent] = useState(""),
    [busy, setBusy] = useState("");
  const [draft, setDraft] = useState<Draft | null>(null),
    [view, setView] = useState<Quote | null>(null),
    [search, setSearch] = useState(""),
    [filter, setFilter] = useState("all"),
    [page, setPage] = useState(0),
    [productDialog, setProductDialog] = useState(false),
    [editProduct, setEditProduct] = useState<Product | null>(null),
    [addToDraft, setAddToDraft] = useState(false);
  const [aiPrompt, setAiPrompt] = useState(""),
    [aiResult, setAiResult] = useState<{
      message: string;
      lines: { productId: string; quantity: number; branding: string }[];
    } | null>(null);
  const [studioProduct, setStudioProduct] = useState(""),
    [mockups, setMockups] = useState<Mockup[]>([]);
  const [customerDialog, setCustomerDialog] = useState(false),
    [editCustomer, setEditCustomer] = useState<Customer | null>(null),
    [viewCustomer, setViewCustomer] = useState<Customer | null>(null),
    [customerSearch, setCustomerSearch] = useState(""),
    [stageFilter, setStageFilter] = useState("all"),
    [activityType, setActivityType] = useState("call"),
    [activityNotes, setActivityNotes] = useState("");
  const [docTab, setDocTab] = useState<"delivery" | "invoice">("delivery"),
    [viewDeliveryNote, setViewDeliveryNote] = useState<DeliveryNote | null>(
      null,
    ),
    [viewInvoice, setViewInvoice] = useState<Invoice | null>(null);
  const [pricingQuote, setPricingQuote] = useState<Quote | null>(null),
    [pricingLines, setPricingLines] = useState<Record<string, number>>({});
  const [paymentInvoice, setPaymentInvoice] = useState<Invoice | null>(null),
    [paymentDate, setPaymentDate] = useState("");
  const [outcomeQuote, setOutcomeQuote] = useState<Quote | null>(null),
    [outcomeChoice, setOutcomeChoice] = useState(""),
    [outcomeReason, setOutcomeReason] = useState("");
  const [goalDraft, setGoalDraft] = useState("");
  const [passwordAgent, setPasswordAgent] = useState<Agent | null>(null),
    [passwordDraft, setPasswordDraft] = useState("");
  const [customerSuggestOpen, setCustomerSuggestOpen] = useState(false);
  const [conversations, setConversations] = useState<ConversationSummary[]>(
      [],
    ),
    [directory, setDirectory] = useState<DirectoryAgent[]>([]),
    [activeConversation, setActiveConversation] = useState<string | null>(
      null,
    ),
    [threadMessages, setThreadMessages] = useState<ThreadMessage[]>([]),
    [messageDraft, setMessageDraft] = useState(""),
    [composeDialog, setComposeDialog] = useState(false),
    [composeRecipients, setComposeRecipients] = useState<string[]>([]),
    [messageFiles, setMessageFiles] = useState<File[]>([]);
  const refresh = useCallback(async () => {
    try {
      const r = await request("/api/erp");
      const d = (await r.json()) as State & { error?: string };
      if (!r.ok) throw new Error(d.error || "Could not load the workspace");
      setData(d);
      setAgent((a) => a || d.agents[0]?.id || "");
      setLoadError("");
      setLoaded(true);
      return d as State;
    } catch (e) {
      setLoadError(
        e instanceof Error ? e.message : "Could not load the workspace",
      );
      throw e;
    }
  }, []);
  useEffect(() => {
    const controller = new AbortController();
    request("/api/erp", { signal: controller.signal })
      .then(async (r) => {
        const d = (await r.json()) as State & { error?: string };
        if (!r.ok) throw new Error(d.error || "Could not load the workspace");
        return d;
      })
      .then((d) => {
        setData(d);
        setAgent((a) => a || d.agents[0]?.id || "");
        setLoaded(true);
        setLoadError("");
      })
      .catch((e) => {
        if (e.name !== "AbortError") setLoadError(e.message);
      });
    return () => controller.abort();
  }, []);
  useEffect(() => {
    if (!agent) return;
    const controller = new AbortController();
    request("/api/mockups?agent=" + encodeURIComponent(agent), {
      signal: controller.signal,
    })
      .then(async (r) => {
        if (!r.ok) throw new Error("Could not load saved mockups");
        return r.json() as Promise<Mockup[]>;
      })
      .then(setMockups)
      .catch((e) => {
        if (e.name !== "AbortError") toast.error(e.message);
      });
    return () => controller.abort();
  }, [agent]);
  const loadConversations = useCallback(async () => {
    try {
      const r = await request("/api/messages");
      const d = (await r.json()) as {
        conversations: ConversationSummary[];
        directory: DirectoryAgent[];
        error?: string;
      };
      if (!r.ok) throw new Error(d.error || "Could not load messages");
      setConversations(d.conversations);
      setDirectory(d.directory);
    } catch {
      // silent: polling failure shouldn't interrupt the rest of the app
    }
  }, []);
  const loadThread = useCallback(async (conversationId: string) => {
    const r = await request(
      "/api/messages/thread?conversation=" +
        encodeURIComponent(conversationId),
    );
    const d = (await r.json()) as { messages: ThreadMessage[]; error?: string };
    if (!r.ok) throw new Error(d.error || "Could not load this conversation");
    setThreadMessages(d.messages);
  }, []);
  useEffect(() => {
    if (!loaded) return;
    void loadConversations();
    const id = setInterval(() => void loadConversations(), 8000);
    return () => clearInterval(id);
  }, [loaded, loadConversations]);
  useEffect(() => {
    if (tab !== "messages" || !activeConversation) return;
    void loadThread(activeConversation).then(() => loadConversations());
    const id = setInterval(() => {
      void loadThread(activeConversation).then(() => loadConversations());
    }, 5000);
    return () => clearInterval(id);
  }, [tab, activeConversation, loadThread, loadConversations]);
  useEffect(() => {
    const context = (
      document as Document & {
        modelContext?: {
          registerTool: (tool: unknown, options: unknown) => Promise<void>;
        };
      }
    ).modelContext;
    if (!context) return;
    const controller = new AbortController();
    Promise.resolve(
      context.registerTool(
        {
          name: "search_gift_products",
          description:
            "Search the loaded product catalogue and read separate warehouse and supplier stock quantities.",
          inputSchema: {
            type: "object",
            properties: { query: { type: "string" } },
            required: ["query"],
            additionalProperties: false,
          },
          annotations: { readOnlyHint: true, untrustedContentHint: true },
          execute: (input: unknown) => {
            if (
              !input ||
              typeof input !== "object" ||
              !("query" in input) ||
              typeof input.query !== "string"
            )
              throw new Error("A text query is required");
            const query = input.query.toLowerCase();
            return data.products
              .filter((p) =>
                (p.name + " " + p.sku + " " + p.category)
                  .toLowerCase()
                  .includes(query),
              )
              .slice(0, 50)
              .map((p) => ({
                id: p.id,
                name: p.name,
                sku: p.sku,
                warehouseStock: p.warehouse_stock,
                supplierStock: p.supplier_stock,
              }));
          },
        },
        { signal: controller.signal },
      ),
    ).catch(() => {});
    return () => controller.abort();
  }, [data.products]);
  async function perform(label: string, work: () => Promise<void>) {
    if (busy) return;
    setBusy(label);
    try {
      await work();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "Something went wrong");
    } finally {
      setBusy("");
    }
  }
  const filtered = useMemo(
    () =>
      data.products.filter(
        (p) =>
          (p.name + " " + p.sku + " " + p.category)
            .toLowerCase()
            .includes(search.toLowerCase()) &&
          (tab === "quotations" ||
            filter === "all" ||
            (filter === "warehouse" ? p.warehouse_stock > 0 : !!p.supplier_id)),
      ),
    [data.products, search, filter, tab],
  );
  const quoteList = data.quotes.filter((q) => q.agent === agent);
  const customerMatches = draft
    ? data.customers
        .filter(
          (c) =>
            c.agent === draft.agent &&
            (!draft.customer.trim() ||
              c.company
                .toLowerCase()
                .includes(draft.customer.trim().toLowerCase())),
        )
        .slice(0, 6)
    : [];
  const selectedProduct = data.products.find((p) => p.id === studioProduct);
  const customerList = data.customers.filter(
    (c) =>
      c.agent === agent &&
      (stageFilter === "all" || c.stage === stageFilter) &&
      (c.company + " " + c.contact_name)
        .toLowerCase()
        .includes(customerSearch.toLowerCase()),
  );
  const deliveryList = data.deliveryNotes.filter((n) => n.agent === agent);
  const invoiceList = data.invoices.filter((i) => i.agent === agent);
  const hasDeliveryNote = (quoteId: string) =>
    data.deliveryNotes.some((n) => n.quote_id === quoteId);
  const hasInvoice = (quoteId: string) =>
    data.invoices.some((i) => i.quote_id === quoteId);
  const totalUnread = conversations.reduce((n, c) => n + c.unreadCount, 0);
  const activeConversationSummary = conversations.find(
    (c) => c.id === activeConversation,
  );
  const pricingQueue = data.quotes.filter(
    (q) => q.status === "Draft" && q.pricing_status === "Pending",
  );
  const agentName = (id: string) =>
    data.agents.find((a) => a.id === id)?.name || "Unknown agent";
  const companyFor = (id: string): Company =>
    data.companies.find((c) => c.id === id) || {
      id: "",
      key: "",
      name: data.settings.company,
      trading_name: null,
      vat_number: null,
      logo_path: "/mais-logo.png",
    };
  const todayIso = new Date().toISOString().slice(0, 10);
  const isOverdue = (i: Invoice) =>
    i.status !== "Paid" &&
    i.status !== "Cancelled" &&
    !!i.due_date &&
    i.due_date.slice(0, 10) < todayIso;
  const pendingInvoices = data.invoices.filter(
    (i) => i.status !== "Paid" && i.status !== "Cancelled",
  );
  const paidInvoices = data.invoices.filter((i) => i.status === "Paid");
  const overdueInvoices = pendingInvoices.filter(isOverdue);
  const wonQuotes = data.quotes.filter((q) => q.outcome === "Won");
  const lostQuotes = data.quotes.filter((q) => q.outcome === "Lost");
  const onHoldQuotes = data.quotes.filter((q) => q.outcome === "OnHold");
  const openQuotes = data.quotes.filter((q) => !q.outcome);
  const reasonCounts = (quotes: Quote[]) => {
    const counts: Record<string, number> = {};
    quotes.forEach((q) => {
      const reason = q.outcome_reason || "No reason given";
      counts[reason] = (counts[reason] || 0) + 1;
    });
    return Object.entries(counts).sort((a, b) => b[1] - a[1]);
  };
  const agentBreakdown = data.agents.map((a) => {
    const qs = data.quotes.filter((q) => q.agent === a.id);
    return {
      agent: a,
      total: qs.length,
      won: qs.filter((q) => q.outcome === "Won").length,
      lost: qs.filter((q) => q.outcome === "Lost").length,
      onHold: qs.filter((q) => q.outcome === "OnHold").length,
    };
  });
  const currentPeriod = todayIso.slice(0, 7);
  const myWon = quoteList.filter((q) => q.outcome === "Won");
  const myLost = quoteList.filter((q) => q.outcome === "Lost");
  const myOnHold = quoteList.filter((q) => q.outcome === "OnHold");
  const myOpen = quoteList.filter((q) => !q.outcome);
  const myWonThisMonth = myWon.filter(
    (q) => (q.outcome_at || "").slice(0, 7) === currentPeriod,
  );
  const myWonThisMonthValue = myWonThisMonth.reduce((n, q) => n + q.total, 0);
  const myPipelineValue = myOpen.reduce((n, q) => n + q.total, 0);
  const myWinRate =
    myWon.length + myLost.length
      ? Math.round((myWon.length / (myWon.length + myLost.length)) * 100)
      : null;
  const currentGoal = data.salesGoals.find(
    (g) => g.agent_id === agent && g.period === currentPeriod,
  );
  const outcomeChartData = [
    { name: "Won", value: myWon.length, fill: "#1c6b34" },
    { name: "Lost", value: myLost.length, fill: "#a32d3e" },
    { name: "On hold", value: myOnHold.length, fill: "#815b10" },
    { name: "Open", value: myOpen.length, fill: "#1756bd" },
  ].filter((d) => d.value > 0);
  const monthlyWonData = Array.from({ length: 6 }, (_, i) => {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() - (5 - i));
    const period = d.toISOString().slice(0, 7);
    const value = quoteList
      .filter(
        (q) => q.outcome === "Won" && (q.outcome_at || "").slice(0, 7) === period,
      )
      .reduce((n, q) => n + q.total, 0);
    return { month: d.toLocaleDateString("en-OM", { month: "short" }), value: value / 1000 };
  });
  const myOverdueInvoices = invoiceList.filter(isOverdue);
  const todoItems = [
    {
      count: quoteList.filter(
        (q) => q.status === "Draft" && q.pricing_status === "Priced",
      ).length,
      label: "priced draft(s) ready to review and accept",
      tab: "quotations",
    },
    {
      count: quoteList.filter(
        (q) => q.status === "Draft" && q.pricing_status === "Pending",
      ).length,
      label: "quotation(s) awaiting pricing",
      tab: "quotations",
    },
    {
      count: quoteList.filter(
        (q) => q.status === "Accepted" && !hasDeliveryNote(q.id),
      ).length,
      label: "accepted quotation(s) need a delivery note",
      tab: "quotations",
    },
    {
      count: quoteList.filter(
        (q) =>
          q.status === "Accepted" &&
          hasDeliveryNote(q.id) &&
          !hasInvoice(q.id),
      ).length,
      label: "delivered order(s) not yet invoiced",
      tab: "documents",
    },
    {
      count: myOnHold.length,
      label: "quotation(s) on hold need a follow-up",
      tab: "reports",
    },
    {
      count: myOverdueInvoices.length,
      label: "invoice(s) overdue",
      tab: "documents",
    },
  ].filter((i) => i.count > 0);
  function startDraft() {
    if (!agent) {
      setTab("settings");
      toast.info("Add a sales agent to create quotations.");
      return;
    }
    const lastQuote = data.quotes.find((q) => q.agent === agent);
    setDraft({
      agent,
      companyId: lastQuote?.company_id || data.companies[0]?.id || "",
      customerId: null,
      customer: "",
      email: "",
      notes: "",
      rate: data.settings.rate,
      lines: [],
      pricingStatus: "Pending",
      priceUnlocked: false,
    });
    setView(null);
    setAiResult(null);
    setSearch("");
    setFilter("all");
    setPage(0);
    setTab("quotations");
  }
  function addProduct(p: Product, quantity = 1, branding = "") {
    setDraft((d) => {
      if (!d) return d;
      const i = d.lines.findIndex(
        (l) => l.productId === p.id && l.branding === branding,
      );
      if (i >= 0)
        return {
          ...d,
          lines: d.lines.map((l, j) =>
            j === i ? { ...l, quantity: l.quantity + quantity } : l,
          ),
        };
      return {
        ...d,
        lines: [
          ...d.lines,
          {
            productId: p.id,
            name: p.name,
            sku: p.sku,
            quantity,
            branding,
            unitBaisa: p.sale_baisa ?? 0,
            costBaisa: convertedCost(p, d.rate),
          },
        ],
      };
    });
  }
  function updateLine(i: number, changes: Partial<Line>) {
    setDraft((d) =>
      d
        ? {
            ...d,
            lines: d.lines.map((l, j) => (j === i ? { ...l, ...changes } : l)),
          }
        : d,
    );
  }
  async function saveProduct(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    await perform("product", async () => {
      if (editProduct) {
        await api("stock", {
          id: editProduct.id,
          warehouseStock: Number(f.get("warehouse")),
          saleBaisa:
            f.get("sale") === ""
              ? null
              : Math.round(Number(f.get("sale")) * 1000),
        });
        await refresh();
        toast.success("Inventory updated");
      } else {
        const r = await api("product", {
          product: {
            name: String(f.get("name")),
            sku: String(f.get("sku")),
            description: String(f.get("description")),
            category: String(f.get("category")),
            warehouseStock: Number(f.get("warehouse")),
            costBaisa: Math.round(Number(f.get("cost")) * 1000),
            saleBaisa:
              f.get("sale") === ""
                ? null
                : Math.round(Number(f.get("sale")) * 1000),
          },
        });
        const photo = f.get("photo");
        if (photo instanceof File && photo.size > 0) {
          const payload = new FormData();
          payload.set("photo", photo);
          const pr = await request(`/api/products/${r.id}/photo`, {
            method: "POST",
            body: payload,
          });
          if (!pr.ok) {
            const pd = (await pr.json()) as { message?: string };
            throw new Error(pd.message || "Could not upload the photo");
          }
        }
        const d = await refresh();
        if (addToDraft) {
          const p = d.products.find((p) => p.id === r.id);
          if (p) addProduct(p);
        }
        toast.success(
          addToDraft
            ? "Product created and added to quotation"
            : "Product created",
        );
      }
      setProductDialog(false);
      setEditProduct(null);
    });
  }
  async function saveDraft() {
    if (!draft) return;
    await perform("save", async () => {
      const r = await api("quote", { quote: draft });
      const d = await refresh();
      setView(d.quotes.find((q) => q.id === r.id) || null);
      setDraft(null);
      toast.success("Quotation saved");
    });
  }
  async function generateDraft() {
    if (!draft) return;
    await perform("ai", async () => {
      const r = await request("/api/ai", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          agent: draft.agent,
          prompt: aiPrompt,
          rate: draft.rate,
        }),
      });
      const d = (await r.json()) as {
        message: string;
        lines: { productId: string; quantity: number; branding: string }[];
        error?: string;
      };
      if (!r.ok) throw new Error(d.error);
      setAiResult(d);
    });
  }
  async function generateMockup(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const payload = new FormData(e.currentTarget);
    payload.set("agent", agent);
    payload.set("productId", studioProduct);
    await perform("mockup", async () => {
      const r = await request("/api/mockups", { method: "POST", body: payload });
      const d = (await r.json()) as Mockup & { error?: string };
      if (!r.ok) throw new Error(d.error);
      setMockups((m) => [d, ...m]);
      toast.success("Mockup saved");
    });
  }
  async function saveCustomer(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    await perform("customer", async () => {
      const followUpAt = String(f.get("followUpAt") || "");
      const r = await api("customer", {
        customer: {
          id: editCustomer?.id,
          agent,
          company: String(f.get("company")),
          contactName: String(f.get("contactName")),
          email: String(f.get("email") || "") || undefined,
          phone: String(f.get("phone") || "") || undefined,
          address: String(f.get("address") || "") || undefined,
          vatNumber: String(f.get("vatNumber") || "") || undefined,
          stage: String(f.get("stage")),
          notes: String(f.get("notes") || ""),
          followUpAt: followUpAt || undefined,
        },
      });
      const d = await refresh();
      setCustomerDialog(false);
      setEditCustomer(null);
      const saved = d.customers.find((c) => c.id === r.id);
      if (saved) setViewCustomer(saved);
      toast.success(editCustomer ? "Customer updated" : "Customer added");
    });
  }
  async function changeStage(customer: Customer, stage: string) {
    await perform("stage", async () => {
      await api("customer", {
        customer: {
          id: customer.id,
          agent: customer.agent,
          company: customer.company,
          contactName: customer.contact_name,
          email: customer.email || undefined,
          phone: customer.phone || undefined,
          address: customer.address || undefined,
          stage,
          notes: customer.notes,
          followUpAt: customer.follow_up_at || undefined,
        },
      });
      const d = await refresh();
      setViewCustomer(d.customers.find((c) => c.id === customer.id) || null);
    });
  }
  async function addActivity(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!viewCustomer || !activityNotes.trim()) return;
    await perform("activity", async () => {
      await api("customer_activity", {
        activity: {
          customerId: viewCustomer.id,
          type: activityType,
          notes: activityNotes,
        },
      });
      const d = await refresh();
      setViewCustomer(
        d.customers.find((c) => c.id === viewCustomer.id) || null,
      );
      setActivityNotes("");
      toast.success("Activity logged");
    });
  }
  function openPricing(q: Quote) {
    setPricingQuote(q);
    setPricingLines(
      Object.fromEntries(q.lines.map((l) => [l.productId, l.unitBaisa])),
    );
  }
  async function submitPricing(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!pricingQuote) return;
    await perform("quote-price", async () => {
      await api("quote_price", {
        quote: {
          id: pricingQuote.id,
          lines: pricingQuote.lines.map((l) => ({
            productId: l.productId,
            unitBaisa: pricingLines[l.productId] ?? 0,
          })),
        },
      });
      await refresh();
      setPricingQuote(null);
      toast.success("Quotation priced");
    });
  }
  function openPayment(i: Invoice) {
    setPaymentInvoice(i);
    setPaymentDate(i.paid_at?.slice(0, 10) || todayIso);
  }
  async function submitPayment(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!paymentInvoice) return;
    await perform("invoice-payment", async () => {
      await api("invoice_payment", {
        id: paymentInvoice.id,
        paid: true,
        paidAt: paymentDate,
      });
      await refresh();
      setPaymentInvoice(null);
      toast.success("Payment recorded");
    });
  }
  async function undoPayment(i: Invoice) {
    await perform("invoice-payment", async () => {
      await api("invoice_payment", { id: i.id, paid: false });
      await refresh();
      toast.success("Payment undone");
    });
  }
  function openOutcome(q: Quote) {
    setOutcomeQuote(q);
    setOutcomeChoice(q.outcome || "");
    setOutcomeReason(q.outcome_reason || "");
  }
  async function submitOutcome(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!outcomeQuote) return;
    await perform("quote-outcome", async () => {
      await api("quote_outcome", {
        id: outcomeQuote.id,
        outcome: outcomeChoice || null,
        reason: outcomeReason || undefined,
      });
      const d = await refresh();
      setView((v) => (v ? d.quotes.find((q) => q.id === v.id) || v : v));
      setOutcomeQuote(null);
      toast.success("Outcome updated");
    });
  }
  async function submitGoal(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!agent || !goalDraft) return;
    await perform("sales-goal", async () => {
      await api("sales_goal", {
        agentId: agent,
        period: currentPeriod,
        targetBaisa: Math.round(Number(goalDraft) * 1000),
      });
      await refresh();
      setGoalDraft("");
      toast.success("Sales goal saved");
    });
  }
  async function submitPassword(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!passwordAgent || !passwordDraft) return;
    await perform("agent-password", async () => {
      await api("agent_password", {
        agentId: passwordAgent.id,
        password: passwordDraft,
      });
      setPasswordAgent(null);
      setPasswordDraft("");
      toast.success("Password updated for " + passwordAgent.name);
    });
  }
  async function startConversation() {
    if (!composeRecipients.length) return;
    await perform("new-conversation", async () => {
      const r = await request("/api/messages", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ participantIds: composeRecipients }),
      });
      const d = (await r.json()) as { id: string; error?: string };
      if (!r.ok) throw new Error(d.error || "Could not start the conversation");
      setComposeDialog(false);
      setComposeRecipients([]);
      await loadConversations();
      setActiveConversation(d.id);
    });
  }
  async function sendMessage(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!activeConversation) return;
    if (!messageDraft.trim() && messageFiles.length === 0) return;
    await perform("send-message", async () => {
      const payload = new FormData();
      payload.set("conversationId", activeConversation);
      if (messageDraft.trim()) payload.set("body", messageDraft.trim());
      messageFiles.forEach((f) => payload.append("attachments[]", f));
      const r = await request("/api/messages/send", {
        method: "POST",
        body: payload,
      });
      const d = (await r.json()) as { id: string; error?: string };
      if (!r.ok) throw new Error(d.error || "Could not send the message");
      setMessageDraft("");
      setMessageFiles([]);
      await loadThread(activeConversation);
      await loadConversations();
    });
  }
  async function createDeliveryNote(quoteId: string) {
    await perform("delivery-note", async () => {
      const r = await api("delivery_note", { deliveryNote: { quoteId } });
      const d = await refresh();
      setViewDeliveryNote(d.deliveryNotes.find((n) => n.id === r.id) || null);
      setDocTab("delivery");
      setTab("documents");
      toast.success("Delivery note created");
    });
  }
  async function createInvoice(quoteId: string) {
    await perform("invoice", async () => {
      const r = await api("invoice", { invoice: { quoteId } });
      const d = await refresh();
      setViewInvoice(d.invoices.find((i) => i.id === r.id) || null);
      setDocTab("invoice");
      setTab("documents");
      toast.success("Invoice created");
    });
  }
  const total = draft
    ? draft.lines.reduce((n, l) => n + l.quantity * l.unitBaisa, 0)
    : 0;
  return (
    <main className="workspace">
      <Toaster richColors />
      <header className="topbar">
        <div className="brand">
          <img
            className="company-logo"
            src="/mais-logo.png"
            alt="Mais company logo"
            width={72}
            height={72}
          />
          <strong>Cloud ERP</strong>
          <span>Corporate gifting</span>
        </div>
        <div className="actions">
          <span className="badge">Oman workspace</span>
          {data.isAdmin ? (
            <Choice
              value={agent}
              onChange={(a) => {
                if (draft) {
                  toast.info(
                    "Save or close your quotation before switching agents.",
                  );
                  return;
                }
                setAgent(a);
                setMockups([]);
                setView(null);
              }}
              placeholder="Sales agent"
              disabled={!!busy}
              items={data.agents}
            />
          ) : (
            agent && (
              <span className="badge">
                {data.agents.find((a) => a.id === agent)?.name}
              </span>
            )
          )}
          <div className="user-menu">
            <span className="user-name">{data.userName}</span>
            <form method="post" action="/logout">
              <input type="hidden" name="_token" value={csrfToken()} />
              <button className="icon-button" type="submit" aria-label="Sign out">
                <LogOut size={17} />
              </button>
            </form>
          </div>
        </div>
      </header>
      <section className="heading">
        <div>
          <p className="eyebrow">SALES WORKSPACE</p>
          <h1>
            {draft
              ? "Build a thoughtful quotation."
              : tab === "dashboard"
                ? "Your sales, at a glance."
                : tab === "studio"
                  ? "Make the gift their own."
                  : tab === "inventory"
                    ? "Every product. One place."
                    : tab === "settings"
                      ? "Your workspace, your way."
                      : "Every great gift starts here."}
          </h1>
          <p>
            {tab === "studio"
              ? "Turn a product photograph and a customer logo into a branded preview."
              : tab === "dashboard"
                ? "Pipeline, goals and what needs your attention today."
                : "Products, availability and quotations. Together in one workspace."}
          </p>
        </div>
        <button
          className="primary"
          onClick={startDraft}
          disabled={!!draft || !loaded}
        >
          <Plus size={18} /> New quotation
        </button>
      </section>
      {loadError && (
        <div role="alert" className="notice error">
          {loadError}
          <button
            className="secondary"
            onClick={() => void refresh().catch(() => {})}
          >
            Retry
          </button>
        </div>
      )}
      {!loaded && !loadError && <p role="status">Loading your workspace…</p>}
      <Tabs
        value={tab}
        onValueChange={(v) => {
          setTab(v);
          setPage(0);
        }}
      >
        <TabsList className="navigation">
          <TabsTrigger value="dashboard">
            <LayoutDashboard />
            Dashboard
          </TabsTrigger>
          <TabsTrigger value="quotations">
            <FileText />
            Quotations
          </TabsTrigger>
          <TabsTrigger value="inventory">
            <Package />
            Inventory
          </TabsTrigger>
          <TabsTrigger value="customers">
            <Contact />
            Customers
          </TabsTrigger>
          <TabsTrigger value="documents">
            <Receipt />
            Documents
          </TabsTrigger>
          <TabsTrigger value="reports">
            <BarChart3 />
            Reports
          </TabsTrigger>
          {(data.isAdmin || data.userRole === "pricing") && (
            <TabsTrigger value="pricing">
              <DollarSign />
              Pricing
              {pricingQueue.length > 0 && (
                <span className="unread-dot">{pricingQueue.length}</span>
              )}
            </TabsTrigger>
          )}
          {(data.isAdmin || data.userRole === "accounts") && (
            <TabsTrigger value="accounts">
              <Wallet />
              Accounts
              {overdueInvoices.length > 0 && (
                <span className="unread-dot">{overdueInvoices.length}</span>
              )}
            </TabsTrigger>
          )}
          <TabsTrigger value="studio">
            <Sparkles />
            Mockup studio
          </TabsTrigger>
          <TabsTrigger value="messages">
            <MessageCircle />
            Messages
            {totalUnread > 0 && (
              <span className="unread-dot">{totalUnread}</span>
            )}
          </TabsTrigger>
          <TabsTrigger value="settings">
            <Settings />
            Settings
          </TabsTrigger>
        </TabsList>
        <TabsContent value="dashboard">
          <div className="stat-grid">
            <div className="stat-card">
              <span className="stat-label">Quotations</span>
              <strong className="stat-value">{quoteList.length}</strong>
            </div>
            <div className="stat-card">
              <span className="stat-label">Pipeline · OMR</span>
              <strong className="stat-value">{money(myPipelineValue)}</strong>
            </div>
            <div className="stat-card stat-card-won">
              <span className="stat-label">Won this month · OMR</span>
              <strong className="stat-value">
                {money(myWonThisMonthValue)}
              </strong>
            </div>
            <div className="stat-card stat-card-warn">
              <span className="stat-label">Lost</span>
              <strong className="stat-value">{myLost.length}</strong>
            </div>
            <div className="stat-card">
              <span className="stat-label">Win rate</span>
              <strong className="stat-value">
                {myWinRate === null ? "—" : `${myWinRate}%`}
              </strong>
            </div>
          </div>
          <div className="settings-grid">
            <section className="panel">
              <div className="section-head">
                <h2 className="icon-heading">
                  <PieChartIcon size={18} /> Quotation pipeline
                </h2>
              </div>
              {outcomeChartData.length ? (
                <>
                  <ChartContainer
                    config={{}}
                    className="mx-auto aspect-square max-h-[220px]"
                  >
                    <PieChart>
                      <ChartTooltip content={<ChartTooltipContent hideLabel />} />
                      <Pie
                        data={outcomeChartData}
                        dataKey="value"
                        nameKey="name"
                        innerRadius={55}
                        outerRadius={85}
                        strokeWidth={2}
                      >
                        {outcomeChartData.map((entry) => (
                          <Cell key={entry.name} fill={entry.fill} />
                        ))}
                      </Pie>
                    </PieChart>
                  </ChartContainer>
                  <div className="chart-legend">
                    {outcomeChartData.map((entry) => (
                      <span key={entry.name} className="chart-legend-item">
                        <span
                          className="chart-legend-dot"
                          style={{ background: entry.fill }}
                        />
                        {entry.name} · {entry.value}
                      </span>
                    ))}
                  </div>
                </>
              ) : (
                <Blank title="No quotations yet">
                  Once you prepare quotations, their outcomes will show here.
                </Blank>
              )}
            </section>
            <section className="panel">
              <div className="section-head">
                <h2 className="icon-heading">
                  <TrendingUp size={18} /> Won value · last 6 months
                </h2>
              </div>
              {monthlyWonData.some((m) => m.value > 0) ? (
                <div className="mini-bar-chart">
                  {monthlyWonData.map((m) => {
                    const max = Math.max(
                      ...monthlyWonData.map((x) => x.value),
                      1,
                    );
                    return (
                      <div
                        key={m.month}
                        className="mini-bar-col"
                        title={`${m.month}: OMR ${m.value.toFixed(3)}`}
                      >
                        <div className="mini-bar-track">
                          <div
                            className="mini-bar-fill"
                            style={{ height: `${(m.value / max) * 100}%` }}
                          />
                        </div>
                        <small>{m.month}</small>
                      </div>
                    );
                  })}
                </div>
              ) : (
                <Blank title="No won quotations yet">
                  Monthly won value will show here once you close deals.
                </Blank>
              )}
            </section>
          </div>
          <div className="settings-grid">
            <section className="panel">
              <div className="section-head">
                <h2 className="icon-heading">
                  <Target size={18} /> Sales goal ·{" "}
                  {new Date(currentPeriod + "-01").toLocaleDateString(
                    "en-OM",
                    { month: "long", year: "numeric" },
                  )}
                </h2>
              </div>
              {currentGoal ? (
                <>
                  <p className="helper">
                    OMR {money(myWonThisMonthValue)} won of your OMR{" "}
                    {money(currentGoal.target_baisa)} goal
                  </p>
                  <Progress
                    value={Math.min(
                      100,
                      (myWonThisMonthValue / currentGoal.target_baisa) * 100,
                    )}
                  />
                </>
              ) : (
                <Blank title="No goal set for this month">
                  Set a target and track your won quotations against it.
                </Blank>
              )}
              <form className="goal-form" onSubmit={submitGoal}>
                <Field label="Set monthly goal · OMR">
                  <input
                    type="number"
                    min="0"
                    step="0.001"
                    value={goalDraft}
                    onChange={(e) => setGoalDraft(e.target.value)}
                    placeholder={
                      currentGoal
                        ? String(currentGoal.target_baisa / 1000)
                        : "e.g. 5000"
                    }
                  />
                </Field>
                <button
                  className="secondary"
                  disabled={!!busy || !agent || !goalDraft}
                >
                  <Check size={16} /> Save goal
                </button>
              </form>
            </section>
            <section className="panel">
              <div className="section-head">
                <h2 className="icon-heading">
                  <ListChecks size={18} /> To-do
                </h2>
                <span className="badge">{todoItems.length}</span>
              </div>
              {todoItems.length ? (
                <div className="todo-list">
                  {todoItems.map((item) => (
                    <button
                      key={item.label}
                      className="todo-item"
                      onClick={() => setTab(item.tab)}
                    >
                      <span className="todo-count">{item.count}</span>
                      <span>{item.label}</span>
                    </button>
                  ))}
                </div>
              ) : (
                <Blank title="You're all caught up">
                  Nothing needs your attention right now.
                </Blank>
              )}
            </section>
          </div>
        </TabsContent>
        <TabsContent value="quotations">
          {draft ? (
            <div className="builder">
              <div className="main-column">
                <div className="panel">
                  <div className="section-head">
                    <h2>{draft.id ? "Edit draft" : "New quotation"}</h2>
                    <button
                      className="text-button"
                      onClick={() => {
                        if (draft.lines.length || draft.customer) {
                          if (
                            !window.confirm(
                              "Close this quotation without saving your changes?",
                            )
                          )
                            return;
                        }
                        setDraft(null);
                      }}
                    >
                      <ArrowLeft size={16} /> Back
                    </button>
                  </div>
                  <div className="form-grid">
                    <Field label="Issuing company">
                      <Choice
                        value={draft.companyId}
                        onChange={(companyId) =>
                          setDraft({ ...draft, companyId })
                        }
                        placeholder="Select a company"
                        items={data.companies.map((c) => ({
                          id: c.id,
                          name: c.trading_name
                            ? `${c.name} (${c.trading_name})`
                            : c.name,
                        }))}
                      />
                    </Field>
                    <Field label="Customer company name">
                      <div className="autocomplete">
                        <input
                          value={draft.customer}
                          onChange={(e) =>
                            setDraft({
                              ...draft,
                              customer: e.target.value,
                              customerId: null,
                            })
                          }
                          onFocus={() => setCustomerSuggestOpen(true)}
                          onBlur={() => setCustomerSuggestOpen(false)}
                          placeholder="Start typing to find a saved customer"
                          maxLength={200}
                          autoComplete="off"
                        />
                        {customerSuggestOpen && customerMatches.length > 0 && (
                          <div className="autocomplete-list">
                            {customerMatches.map((c) => (
                              <button
                                key={c.id}
                                type="button"
                                className="autocomplete-item"
                                onMouseDown={(e) => {
                                  e.preventDefault();
                                  setDraft({
                                    ...draft,
                                    customer: c.company,
                                    email: c.email || draft.email,
                                    customerId: c.id,
                                  });
                                  setCustomerSuggestOpen(false);
                                }}
                              >
                                <strong>{c.company}</strong>
                                <small>
                                  {c.contact_name}
                                  {c.email ? " · " + c.email : ""}
                                </small>
                              </button>
                            ))}
                          </div>
                        )}
                      </div>
                      {draft.customerId && (
                        <small className="linked-customer">
                          <Contact size={13} /> Linked to saved customer
                        </small>
                      )}
                    </Field>
                    <Field label="Customer email (optional)">
                      <input
                        type="email"
                        value={draft.email}
                        onChange={(e) =>
                          setDraft({ ...draft, email: e.target.value })
                        }
                        placeholder="name@company.com"
                      />
                    </Field>
                  </div>
                </div>
                <section className="panel">
                  <div className="section-head">
                    <h2>
                      Quotation items{" "}
                      <span className="count">{draft.lines.length}</span>
                    </h2>
                    <button
                      className="secondary"
                      onClick={() => {
                        setEditProduct(null);
                        setAddToDraft(true);
                        setProductDialog(true);
                      }}
                    >
                      <Plus size={16} /> Add new product
                    </button>
                  </div>
                  {!draft.lines.length ? (
                    <Blank title="Start with the right gifts">
                      Choose products from the catalogue below, or add a product
                      your customer has requested.
                    </Blank>
                  ) : (
                    <div className="line-list">
                      {draft.lines.map((l, i) => {
                        const p = data.products.find(
                          (p) => p.id === l.productId,
                        );
                        return (
                          <article className="quote-line" key={i}>
                            <div className="section-head">
                              <div className="product-cell">
                                {p && <ProductPhoto product={p} />}
                                <div>
                                  <strong>{l.name}</strong>
                                  <small>
                                    {l.sku} · Warehouse{" "}
                                    {p?.warehouse_stock ?? "—"} · Supplier{" "}
                                    {p?.supplier_stock ?? "Unknown"}
                                  </small>
                                </div>
                              </div>
                              <button
                                className="icon-button"
                                aria-label={"Remove " + l.name}
                                onClick={() =>
                                  setDraft({
                                    ...draft,
                                    lines: draft.lines.filter(
                                      (_, j) => i !== j,
                                    ),
                                  })
                                }
                              >
                                <Trash2 size={17} />
                              </button>
                            </div>
                            <div className="line-inputs">
                              <Field label="Quantity">
                                <input
                                  aria-label={"Quantity for " + l.name}
                                  type="number"
                                  min="1"
                                  max="1000000"
                                  step="1"
                                  value={l.quantity || ""}
                                  onChange={(e) =>
                                    updateLine(i, {
                                      quantity: Number(e.target.value),
                                    })
                                  }
                                />
                              </Field>
                              {draft.priceUnlocked ? (
                                <Field label="Unit selling price · OMR">
                                  <input
                                    type="number"
                                    min="0"
                                    max="1000000"
                                    step="0.001"
                                    value={l.unitBaisa / 1000}
                                    onChange={(e) =>
                                      updateLine(i, {
                                        unitBaisa: Math.round(
                                          Number(e.target.value) * 1000,
                                        ),
                                      })
                                    }
                                  />
                                </Field>
                              ) : draft.pricingStatus === "Priced" ? (
                                <Field label="Unit selling price · OMR">
                                  <p className="locked-price">
                                    {money(l.unitBaisa)}
                                  </p>
                                </Field>
                              ) : (
                                <Field label="Unit selling price · OMR">
                                  <p className="locked-price">
                                    Awaiting pricing
                                  </p>
                                </Field>
                              )}
                              <div className="line-total">
                                <small>Line total · OMR</small>
                                <strong>
                                  {draft.pricingStatus === "Pending" &&
                                  !draft.priceUnlocked
                                    ? "—"
                                    : money(l.quantity * l.unitBaisa)}
                                </strong>
                              </div>
                            </div>
                            <Field label="Branding / printing requirements">
                              <input
                                value={l.branding}
                                onChange={(e) =>
                                  updateLine(i, { branding: e.target.value })
                                }
                                placeholder="e.g. One-colour logo, front centre"
                                maxLength={1000}
                              />
                            </Field>
                            {l.unitBaisa === 0 &&
                              draft.pricingStatus === "Priced" &&
                              draft.priceUnlocked && (
                                <p className="warning">
                                  Selling price is zero. Enter the price
                                  before sending to the customer.
                                </p>
                              )}
                            {p && l.quantity > p.warehouse_stock && (
                              <p className="warning">
                                {l.quantity - p.warehouse_stock} units exceed
                                warehouse stock. Confirm sourcing and lead time.
                              </p>
                            )}
                          </article>
                        );
                      })}
                    </div>
                  )}
                  <Field label="Notes and commercial terms">
                    <textarea
                      value={draft.notes}
                      onChange={(e) =>
                        setDraft({ ...draft, notes: e.target.value })
                      }
                      placeholder="Delivery estimate, validity, payment terms, and any applicable charges"
                      rows={3}
                      maxLength={5000}
                    />
                  </Field>
                  <div className="quote-bottom">
                    <div>
                      <small>Subtotal · OMR</small>
                      <strong>
                        {draft.pricingStatus === "Pending" &&
                        !draft.priceUnlocked
                          ? "Awaiting pricing"
                          : money(total)}
                      </strong>
                      <small>
                        {draft.pricingStatus === "Pending" &&
                        !draft.priceUnlocked
                          ? "Save this draft and the Pricing team will send back prices."
                          : "No tax, delivery or printing charges added automatically."}
                      </small>
                    </div>
                    <button
                      className="primary"
                      disabled={
                        !!busy ||
                        !draft.lines.length ||
                        !draft.customer.trim() ||
                        !draft.companyId ||
                        !draft.rate
                      }
                      onClick={() => void saveDraft()}
                    >
                      <Check size={17} />
                      {busy === "save" ? "Saving…" : "Save draft"}
                    </button>
                  </div>
                </section>
                <section className="panel">
                  <div className="section-head">
                    <h2>Product catalogue</h2>
                    <span className="badge">
                      {data.products.length} products
                    </span>
                  </div>
                  <div className="searchbox">
                    <Search size={18} />
                    <input
                      aria-label="Search quotation products"
                      value={search}
                      onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(0);
                      }}
                      placeholder="Search all products, SKUs or categories"
                    />
                  </div>
                  <div className="product-picker">
                    {filtered.slice(page * 20, (page + 1) * 20).map((p) => (
                      <article className="picker-row" key={p.id}>
                        <ProductPhoto product={p} />
                        <div className="product-info">
                          <strong>{p.name}</strong>
                          <small>{p.sku}</small>
                          <div className="stock-labels">
                            <span>Warehouse {p.warehouse_stock}</span>
                            <span>
                              Supplier {p.supplier_stock ?? "Unknown"}
                            </span>
                          </div>
                        </div>
                        <div className="picker-price">
                          <strong>
                            {p.sale_baisa === null
                              ? "Set price"
                              : money(p.sale_baisa) + " OMR"}
                          </strong>
                          {data.isAdmin && (
                            <small>
                              Cost{" "}
                              {p.supplier_aed === null && p.supplier_id
                                ? "unavailable"
                                : draft.rate || !p.supplier_id
                                  ? money(convertedCost(p, draft.rate)) +
                                    " OMR"
                                  : "needs rate"}
                            </small>
                          )}
                        </div>
                        <button
                          className="secondary"
                          aria-label={"Add " + p.name}
                          onClick={() => addProduct(p)}
                        >
                          <Plus size={16} />
                          <span>Add</span>
                        </button>
                      </article>
                    ))}
                  </div>
                  {!filtered.length && (
                    <Blank title="No matching products">
                      Add a new product or sync your supplier catalogue from
                      Inventory.
                    </Blank>
                  )}
                  <Pager
                    page={page}
                    count={filtered.length}
                    size={20}
                    onPage={setPage}
                  />
                </section>
              </div>
              <aside className="assistant-panel panel">
                <div className="assistant-icon">
                  <Sparkles size={22} />
                </div>
                <h2>Your quotation assistant</h2>
                <p>
                  Describe the gifts and quantities your customer needs. Review
                  suggestions before adding them.
                </p>
                <span
                  className={"badge " + (data.aiConfigured ? "" : "pending")}
                >
                  {data.aiConfigured ? "AI connected" : "AI setup required"}
                </span>
                <Field label="Customer request">
                  <textarea
                    rows={7}
                    value={aiPrompt}
                    onChange={(e) => setAiPrompt(e.target.value)}
                    maxLength={5000}
                    placeholder="Find 100 black water bottles with a white logo for a company event."
                  />
                </Field>
                <button
                  className="primary wide"
                  disabled={
                    !!busy ||
                    !data.aiConfigured ||
                    !aiPrompt.trim() ||
                    !draft.rate
                  }
                  onClick={() => void generateDraft()}
                >
                  <Sparkles size={16} />
                  {busy === "ai"
                    ? "Preparing suggestions…"
                    : "Prepare quotation"}
                </button>
                {!data.aiConfigured && (
                  <p className="helper">
                    Connect your AI account in the local environment. You can
                    continue creating quotations manually.
                  </p>
                )}
                {aiResult && (
                  <div className="ai-answer">
                    <p>{aiResult.message}</p>
                    {aiResult.lines.map((l, i) => (
                      <p key={i}>
                        <strong>
                          {l.quantity} ×{" "}
                          {
                            data.products.find((p) => p.id === l.productId)
                              ?.name
                          }
                        </strong>
                      </p>
                    ))}
                    {!!aiResult.lines.length && (
                      <button
                        className="secondary wide"
                        onClick={() => {
                          aiResult.lines.forEach((l) => {
                            const p = data.products.find(
                              (p) => p.id === l.productId,
                            );
                            if (p) addProduct(p, l.quantity, l.branding);
                          });
                          setAiResult(null);
                          toast.success(
                            "Suggestions added. Review the selling prices.",
                          );
                        }}
                      >
                        Add suggestions to draft
                      </button>
                    )}
                  </div>
                )}
              </aside>
            </div>
          ) : view ? (
            <section className="panel">
              <div className="section-head no-print">
                <button className="text-button" onClick={() => setView(null)}>
                  <ArrowLeft size={16} /> All quotations
                </button>
                <div className="actions">
                  {view.status === "Draft" && (
                    <button
                      className="secondary"
                      onClick={() => {
                        setDraft({
                          id: view.id,
                          revision: view.revision,
                          agent: view.agent,
                          companyId: view.company_id,
                          customerId: view.customer_id,
                          customer: view.customer,
                          email: view.email,
                          notes: view.notes,
                          rate: view.rate,
                          lines: view.lines,
                          pricingStatus: view.pricing_status,
                          priceUnlocked: view.price_unlocked_by_admin,
                        });
                        setView(null);
                      }}
                    >
                      Edit draft
                    </button>
                  )}
                  <Choice
                    value={view.status}
                    onChange={(status) =>
                      void perform("status", async () => {
                        await api("status", {
                          id: view.id,
                          status,
                          revision: view.revision,
                        });
                        const d = await refresh();
                        setView(d.quotes.find((q) => q.id === view.id) || null);
                      })
                    }
                    placeholder="Quotation status"
                    items={["Draft", "Reviewed", "Accepted", "Declined"]
                      .filter(
                        (s) =>
                          view.pricing_status === "Priced" ||
                          !["Reviewed", "Accepted"].includes(s),
                      )
                      .map((s) => ({ id: s, name: s }))}
                    disabled={!!busy}
                  />
                  {data.isAdmin && view.pricing_status === "Priced" && (
                    <button
                      className="secondary"
                      disabled={!!busy || view.price_unlocked_by_admin}
                      onClick={() =>
                        void perform("unlock-price", async () => {
                          await api("quote_unlock_price", { id: view.id });
                          const d = await refresh();
                          setView(d.quotes.find((q) => q.id === view.id) || null);
                          toast.success("Pricing unlocked for this agent");
                        })
                      }
                    >
                      <Unlock size={16} />
                      {view.price_unlocked_by_admin
                        ? "Pricing unlocked"
                        : "Unlock pricing"}
                    </button>
                  )}
                  {view.status === "Accepted" && !hasDeliveryNote(view.id) && (
                    <button
                      className="secondary"
                      disabled={!!busy}
                      onClick={() => void createDeliveryNote(view.id)}
                    >
                      <Truck size={16} /> Delivery note
                    </button>
                  )}
                  {view.status === "Accepted" && hasDeliveryNote(view.id) && (
                    <button
                      className="secondary"
                      disabled={!!busy}
                      onClick={() => void createInvoice(view.id)}
                    >
                      <Receipt size={16} /> Invoice
                    </button>
                  )}
                  <button
                    className="secondary"
                    disabled={!!busy}
                    onClick={() => openOutcome(view)}
                  >
                    <Flag size={16} /> Set outcome
                  </button>
                  <button className="secondary" onClick={() => window.print()}>
                    <Printer size={16} /> Print / PDF
                  </button>
                </div>
              </div>
              <div className="pipeline no-print">
                <span
                  className={
                    "pipeline-step" +
                    (view.pricing_status === "Priced" ? " done" : "")
                  }
                >
                  {view.pricing_status === "Priced" ? (
                    <CheckCircle2 size={14} />
                  ) : (
                    <Circle size={14} />
                  )}
                  {view.pricing_status === "Priced"
                    ? "Priced"
                    : "Awaiting pricing"}
                </span>
                <span
                  className={
                    "badge" +
                    (view.outcome === "Won"
                      ? " badge-won"
                      : view.outcome === "Lost"
                        ? " badge-lost"
                        : view.outcome === "OnHold"
                          ? " badge-onhold"
                          : "")
                  }
                >
                  {view.outcome === "OnHold"
                    ? "On hold"
                    : view.outcome || "Open"}
                  {view.outcome_reason ? " · " + view.outcome_reason : ""}
                </span>
              </div>
              {view.status === "Accepted" && (
                <div className="pipeline no-print">
                  <span
                    className={
                      "pipeline-step" +
                      (hasDeliveryNote(view.id) ? " done" : "")
                    }
                  >
                    {hasDeliveryNote(view.id) ? (
                      <CheckCircle2 size={14} />
                    ) : (
                      <Circle size={14} />
                    )}
                    Delivery note
                  </span>
                  <span
                    className={
                      "pipeline-step" + (hasInvoice(view.id) ? " done" : "")
                    }
                  >
                    {hasInvoice(view.id) ? (
                      <CheckCircle2 size={14} />
                    ) : (
                      <Circle size={14} />
                    )}
                    Invoice
                  </span>
                </div>
              )}
              <div className="print-document">
                <div className="document-heading">
                  <div className="document-brand">
                    <img
                      className="quotation-logo"
                      src={companyFor(view.company_id).logo_path}
                      alt={companyFor(view.company_id).name + " logo"}
                      width={72}
                      height={72}
                    />
                    <strong>
                      {companyFor(view.company_id).trading_name ||
                        companyFor(view.company_id).name}
                    </strong>
                    {companyFor(view.company_id).trading_name && (
                      <small>{companyFor(view.company_id).name}</small>
                    )}
                  </div>
                  <div className="document-meta">
                    <p className="eyebrow">Quotation</p>
                    <h1>Q-{String(view.number).padStart(4, "0")}</h1>
                    <div className="document-meta-row">
                      <span>Date</span>
                      <strong>
                        {new Date(view.created).toLocaleDateString("en-OM")}
                      </strong>
                    </div>
                    <div className="document-meta-row">
                      <span>Status</span>
                      <span className="badge">{view.status}</span>
                    </div>
                  </div>
                </div>
                <div className="document-parties">
                  <div>
                    <p className="document-label">Prepared for</p>
                    <h2>{view.customer}</h2>
                    {view.email && <p>{view.email}</p>}
                  </div>
                </div>
                <Table className="responsive-table">
                  <TableHeader>
                    <TableRow>
                      <TableHead>Item</TableHead>
                      <TableHead className="num">Quantity</TableHead>
                      <TableHead className="num">Unit · OMR</TableHead>
                      <TableHead className="num">Total · OMR</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {view.lines.map((l, i) => {
                      const p = data.products.find(
                        (pr) => pr.id === l.productId,
                      );
                      return (
                        <TableRow key={i}>
                          <TableCell className="card-title">
                            <div className="product-cell">
                              {p && <ProductPhoto product={p} />}
                              <div>
                                <strong>{l.name}</strong>
                                <small>{l.sku}</small>
                                {l.branding && <small>{l.branding}</small>}
                              </div>
                            </div>
                          </TableCell>
                          <TableCell data-label="Quantity" className="num">
                            {l.quantity}
                          </TableCell>
                          <TableCell data-label="Unit · OMR" className="num">
                            {money(l.unitBaisa)}
                          </TableCell>
                          <TableCell data-label="Total · OMR" className="num">
                            {money(l.quantity * l.unitBaisa)}
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
                <div className="invoice-summary">
                  <div className="invoice-summary-total">
                    <span>Total (excl. VAT)</span>
                    <strong>OMR {money(view.total)}</strong>
                  </div>
                </div>
                {view.notes && (
                  <>
                    <p className="document-label">Notes</p>
                    <p className="preserve-lines">{view.notes}</p>
                  </>
                )}
                <div className="document-footer">
                  <p className="document-thanks">
                    Thank you for considering{" "}
                    {companyFor(view.company_id).trading_name ||
                      companyFor(view.company_id).name}
                    .
                  </p>
                  <p className="helper">
                    Prepared by{" "}
                    {data.agents.find((a) => a.id === view.agent)?.name}
                  </p>
                </div>
              </div>
            </section>
          ) : (
            <>
              <div className="stats">
                <div>
                  <small>Your quotations</small>
                  <strong>{quoteList.length}</strong>
                </div>
                <div>
                  <small>Drafts to review</small>
                  <strong>
                    {quoteList.filter((q) => q.status === "Draft").length}
                  </strong>
                </div>
                <div>
                  <small>Quoted value · OMR</small>
                  <strong>
                    {money(quoteList.reduce((n, q) => n + q.total, 0))}
                  </strong>
                </div>
              </div>
              <section className="panel">
                <div className="section-head">
                  <h2>Your quotations</h2>
                  <span className="badge">
                    {data.agents.find((a) => a.id === agent)?.name ||
                      "Add a sales agent in Settings"}
                  </span>
                </div>
                {quoteList.length ? (
                  <Table className="responsive-table">
                    <TableHeader>
                      <TableRow>
                        <TableHead>Quotation</TableHead>
                        <TableHead>Customer</TableHead>
                        <TableHead>Company</TableHead>
                        <TableHead>Date</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Subtotal · OMR</TableHead>
                        <TableHead>
                          <span className="sr-only">Open</span>
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {quoteList.map((q) => (
                        <TableRow key={q.id}>
                          <TableCell className="card-title">
                            <button
                              className="text-button"
                              onClick={() => setView(q)}
                            >
                              Q-{String(q.number).padStart(4, "0")}
                            </button>
                          </TableCell>
                          <TableCell data-label="Customer">
                            {q.customer}
                          </TableCell>
                          <TableCell data-label="Company">
                            {companyFor(q.company_id).trading_name ||
                              companyFor(q.company_id).name}
                          </TableCell>
                          <TableCell data-label="Date">
                            {new Date(q.created).toLocaleDateString("en-OM")}
                          </TableCell>
                          <TableCell data-label="Status">
                            <span className="badge">{q.status}</span>
                            {q.pricing_status !== "Priced" && (
                              <span className="badge pending">
                                Awaiting pricing
                              </span>
                            )}
                            {q.outcome && (
                              <span
                                className={
                                  "badge" +
                                  (q.outcome === "Won"
                                    ? " badge-won"
                                    : q.outcome === "Lost"
                                      ? " badge-lost"
                                      : " badge-onhold")
                                }
                              >
                                {q.outcome === "OnHold"
                                  ? "On hold"
                                  : q.outcome}
                              </span>
                            )}
                          </TableCell>
                          <TableCell data-label="Subtotal · OMR">
                            <strong>
                              {q.pricing_status === "Priced"
                                ? money(q.total)
                                : "—"}
                            </strong>
                          </TableCell>
                          <TableCell className="card-actions">
                            <button
                              className="secondary"
                              onClick={() => setView(q)}
                            >
                              Open
                            </button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                ) : (
                  <Blank title="Your first quotation starts here">
                    Choose gifts from your catalogue, customise the branding,
                    and quote in Omani rials.
                    <br />
                    <button className="primary mt-5" onClick={startDraft}>
                      <Plus size={16} /> Create quotation
                    </button>
                  </Blank>
                )}
              </section>
            </>
          )}
        </TabsContent>
        <TabsContent value="inventory">
          <section className="panel">
            <div className="section-head">
              <div>
                <h2>Product inventory</h2>
                <p className="helper">
                  Warehouse quantities belong to you. Supplier quantities are
                  availability snapshots.
                </p>
              </div>
              <div className="actions">
                {data.isAdmin && (
                  <button
                    className="secondary"
                    disabled={!!busy || !data.supplierConfigured}
                    onClick={() =>
                      void perform("sync", async () => {
                        const r = await api("sync");
                        await refresh();
                        toast.success(`${r.count} supplier products synced`);
                      })
                    }
                  >
                    <RefreshCw
                      size={16}
                      className={busy === "sync" ? "spin" : ""}
                    />
                    {busy === "sync" ? "Syncing…" : "Sync supplier"}
                  </button>
                )}
                <button
                  className="primary"
                  onClick={() => {
                    setEditProduct(null);
                    setAddToDraft(false);
                    setProductDialog(true);
                  }}
                >
                  <Plus size={16} /> Add product
                </button>
              </div>
            </div>
            <div className="toolbar">
              <div className="searchbox">
                <Search size={18} />
                <input
                  aria-label="Search inventory"
                  value={search}
                  onChange={(e) => {
                    setSearch(e.target.value);
                    setPage(0);
                  }}
                  placeholder="Search products, SKUs or categories"
                />
              </div>
              <Choice
                value={filter}
                onChange={(v) => {
                  setFilter(v);
                  setPage(0);
                }}
                placeholder="Inventory source"
                items={[
                  { id: "all", name: "All products" },
                  { id: "warehouse", name: "In our warehouse" },
                  { id: "supplier", name: "Supplier catalogue" },
                ]}
              />
            </div>
            {filtered.length ? (
              <>
                <Table className="responsive-table">
                  <TableHeader>
                    <TableRow>
                      <TableHead>Product</TableHead>
                      <TableHead>Warehouse</TableHead>
                      <TableHead>Supplier</TableHead>
                      {data.isAdmin && <TableHead>Cost · OMR</TableHead>}
                      <TableHead>Selling · OMR</TableHead>
                      <TableHead>Updated</TableHead>
                      <TableHead>Manage</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {filtered.slice(page * 30, (page + 1) * 30).map((p) => (
                      <TableRow key={p.id}>
                        <TableCell className="card-title">
                          <div className="product-cell">
                            <ProductPhoto product={p} />
                            <div>
                              <strong>{p.name}</strong>
                              <small>
                                {p.sku} · {p.category || "Uncategorised"}
                              </small>
                            </div>
                          </div>
                        </TableCell>
                        <TableCell data-label="Warehouse">
                          {p.warehouse_stock}
                        </TableCell>
                        <TableCell data-label="Supplier">
                          {p.supplier_stock ?? "Unknown"}
                        </TableCell>
                        {data.isAdmin && (
                          <TableCell data-label="Cost · OMR">
                            {p.supplier_id && !data.settings.rate
                              ? "Set rate"
                              : p.supplier_id && p.supplier_aed === null
                                ? "Unknown"
                                : money(convertedCost(p, data.settings.rate))}
                          </TableCell>
                        )}
                        <TableCell data-label="Selling · OMR">
                          {p.sale_baisa === null
                            ? "Not set"
                            : money(p.sale_baisa)}
                        </TableCell>
                        <TableCell data-label="Updated">
                          {p.supplier_sync
                            ? new Date(p.supplier_sync).toLocaleString(
                                "en-OM",
                                { dateStyle: "short", timeStyle: "short" },
                              )
                            : "Manual"}
                        </TableCell>
                        <TableCell className="card-actions">
                          <div className="actions">
                            {data.isAdmin && (
                              <button
                                className="secondary"
                                onClick={() => {
                                  setEditProduct(p);
                                  setProductDialog(true);
                                }}
                              >
                                Update
                              </button>
                            )}
                            <button
                              className="text-button"
                              onClick={() => {
                                setStudioProduct(p.id);
                                setTab("studio");
                              }}
                            >
                              Mockup
                            </button>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                <Pager
                  page={page}
                  count={filtered.length}
                  size={30}
                  onPage={setPage}
                />
              </>
            ) : (
              <Blank title="Build your product catalogue">
                Sync your supplier or add the products you hold in stock.
              </Blank>
            )}
          </section>
        </TabsContent>
        <TabsContent value="customers">
          {viewCustomer ? (
            <section className="panel">
              <div className="section-head no-print">
                <button
                  className="text-button"
                  onClick={() => setViewCustomer(null)}
                >
                  <ArrowLeft size={16} /> All customers
                </button>
                <button
                  className="secondary"
                  onClick={() => {
                    setEditCustomer(viewCustomer);
                    setCustomerDialog(true);
                  }}
                >
                  Edit details
                </button>
              </div>
              <div className="customer-detail">
                <div>
                  <p className="eyebrow">{viewCustomer.stage}</p>
                  <h2>{viewCustomer.company}</h2>
                  <p>{viewCustomer.contact_name}</p>
                  <div className="stock-labels">
                    {viewCustomer.phone && (
                      <span>
                        <Phone size={13} /> {viewCustomer.phone}
                      </span>
                    )}
                    {viewCustomer.email && (
                      <span>
                        <Mail size={13} /> {viewCustomer.email}
                      </span>
                    )}
                    {viewCustomer.follow_up_at && (
                      <span>
                        <CalendarClock size={13} /> Follow up{" "}
                        {new Date(viewCustomer.follow_up_at).toLocaleDateString(
                          "en-OM",
                        )}
                      </span>
                    )}
                  </div>
                  {viewCustomer.address && (
                    <p className="helper preserve-lines">
                      {viewCustomer.address}
                    </p>
                  )}
                  {viewCustomer.vat_number && (
                    <p className="helper">
                      VAT reg. {viewCustomer.vat_number}
                    </p>
                  )}
                  {viewCustomer.notes && (
                    <p className="helper preserve-lines">
                      {viewCustomer.notes}
                    </p>
                  )}
                </div>
                <Choice
                  value={viewCustomer.stage}
                  onChange={(stage) => void changeStage(viewCustomer, stage)}
                  placeholder="Pipeline stage"
                  items={CRM_STAGES.map((s) => ({ id: s, name: s }))}
                  disabled={!!busy}
                />
              </div>
              <div className="section-head">
                <h2>Activity</h2>
              </div>
              <form className="activity-form" onSubmit={addActivity}>
                <Choice
                  value={activityType}
                  onChange={setActivityType}
                  placeholder="Type"
                  items={[
                    { id: "call", name: "Call" },
                    { id: "email", name: "Email" },
                    { id: "meeting", name: "Meeting" },
                    { id: "note", name: "Note" },
                  ]}
                />
                <Field label="What happened?">
                  <input
                    value={activityNotes}
                    onChange={(e) => setActivityNotes(e.target.value)}
                    placeholder="Called about the delivery timeline"
                    maxLength={2000}
                  />
                </Field>
                <button
                  className="secondary"
                  disabled={!!busy || !activityNotes.trim()}
                >
                  <Plus size={16} /> Log activity
                </button>
              </form>
              <div className="activity-feed">
                {data.customerActivities.filter(
                  (a) => a.customer_id === viewCustomer.id,
                ).length ? (
                  data.customerActivities
                    .filter((a) => a.customer_id === viewCustomer.id)
                    .map((a) => (
                      <article className="activity-item" key={a.id}>
                        <span className="badge">{a.type}</span>
                        <p>{a.notes}</p>
                        <small>
                          {new Date(a.created).toLocaleString("en-OM")}
                        </small>
                      </article>
                    ))
                ) : (
                  <Blank title="No activity yet">
                    Log a call, email, meeting or note to keep track of this
                    relationship.
                  </Blank>
                )}
              </div>
            </section>
          ) : (
            <section className="panel">
              <div className="section-head">
                <div>
                  <h2>Customers</h2>
                  <p className="helper">
                    Track leads through your pipeline and keep a history of
                    every conversation.
                  </p>
                </div>
                <button
                  className="primary"
                  onClick={() => {
                    setEditCustomer(null);
                    setCustomerDialog(true);
                  }}
                >
                  <Plus size={16} /> New customer
                </button>
              </div>
              <div className="toolbar">
                <div className="searchbox">
                  <Search size={18} />
                  <input
                    aria-label="Search customers"
                    value={customerSearch}
                    onChange={(e) => setCustomerSearch(e.target.value)}
                    placeholder="Search company or contact"
                  />
                </div>
                <Choice
                  value={stageFilter}
                  onChange={setStageFilter}
                  placeholder="Pipeline stage"
                  items={[
                    { id: "all", name: "All stages" },
                    ...CRM_STAGES.map((s) => ({ id: s, name: s })),
                  ]}
                />
              </div>
              {customerList.length ? (
                <Table className="responsive-table">
                  <TableHeader>
                    <TableRow>
                      <TableHead>Company</TableHead>
                      <TableHead>Contact</TableHead>
                      <TableHead>Stage</TableHead>
                      <TableHead>Follow-up</TableHead>
                      <TableHead>
                        <span className="sr-only">Open</span>
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {customerList.map((c) => (
                      <TableRow key={c.id}>
                        <TableCell className="card-title">
                          <strong>{c.company}</strong>
                        </TableCell>
                        <TableCell data-label="Contact">
                          {c.contact_name}
                        </TableCell>
                        <TableCell data-label="Stage">
                          <span className="badge">{c.stage}</span>
                        </TableCell>
                        <TableCell data-label="Follow-up">
                          {c.follow_up_at
                            ? new Date(c.follow_up_at).toLocaleDateString(
                                "en-OM",
                              )
                            : "—"}
                        </TableCell>
                        <TableCell className="card-actions">
                          <button
                            className="secondary"
                            onClick={() => setViewCustomer(c)}
                          >
                            Open
                          </button>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ) : (
                <Blank title="Build your customer list">
                  Add a customer to start tracking their journey through your
                  pipeline.
                </Blank>
              )}
            </section>
          )}
        </TabsContent>
        <TabsContent value="documents">
          {viewDeliveryNote ? (
            <section className="panel">
              <div className="section-head no-print">
                <button
                  className="text-button"
                  onClick={() => setViewDeliveryNote(null)}
                >
                  <ArrowLeft size={16} /> All documents
                </button>
                <div className="actions">
                  <Choice
                    value={viewDeliveryNote.status}
                    onChange={(status) =>
                      void perform("dn-status", async () => {
                        const r = await api("delivery_note_status", {
                          id: viewDeliveryNote.id,
                          status,
                        });
                        const d = await refresh();
                        if (status === "Delivered" && r.invoiceId) {
                          const inv = d.invoices.find(
                            (i) => i.id === r.invoiceId,
                          );
                          if (inv) {
                            setViewInvoice(inv);
                            setDocTab("invoice");
                            setViewDeliveryNote(null);
                            toast.success(
                              "Delivered — invoice INV-" +
                                String(inv.number).padStart(4, "0") +
                                " created",
                            );
                            return;
                          }
                        }
                        setViewDeliveryNote(
                          d.deliveryNotes.find(
                            (n) => n.id === viewDeliveryNote.id,
                          ) || null,
                        );
                      })
                    }
                    placeholder="Status"
                    items={["Draft", "Delivered"].map((s) => ({
                      id: s,
                      name: s,
                    }))}
                    disabled={!!busy}
                  />
                  <button className="secondary" onClick={() => window.print()}>
                    <Printer size={16} /> Print / PDF
                  </button>
                </div>
              </div>
              <form
                key={viewDeliveryNote.id}
                className="po-form no-print"
                onSubmit={(e) => {
                  e.preventDefault();
                  const f = new FormData(e.currentTarget);
                  void perform("dn-po", async () => {
                    await api("delivery_note_update", {
                      id: viewDeliveryNote.id,
                      poNumber: String(f.get("poNumber") || "") || undefined,
                    });
                    const d = await refresh();
                    setViewDeliveryNote(
                      d.deliveryNotes.find(
                        (n) => n.id === viewDeliveryNote.id,
                      ) || null,
                    );
                    toast.success("Purchase order number saved");
                  });
                }}
              >
                <Field label="Purchase order number (optional)">
                  <input
                    name="poNumber"
                    maxLength={100}
                    defaultValue={viewDeliveryNote.po_number ?? ""}
                    placeholder="Customer's PO number"
                  />
                </Field>
                <button className="secondary" disabled={!!busy}>
                  <Check size={16} /> Save
                </button>
              </form>
              <div className="print-document">
                <div className="document-heading">
                  <div>
                    <img
                      className="quotation-logo"
                      src={companyFor(viewDeliveryNote.company_id).logo_path}
                      alt={companyFor(viewDeliveryNote.company_id).name + " logo"}
                      width={100}
                      height={100}
                    />
                    <p className="eyebrow">
                      {companyFor(viewDeliveryNote.company_id).trading_name ||
                        companyFor(viewDeliveryNote.company_id).name}
                    </p>
                    <h2>
                      Delivery Note DN-
                      {String(viewDeliveryNote.number).padStart(4, "0")}
                    </h2>
                    <span className="badge">{viewDeliveryNote.status}</span>
                    {viewDeliveryNote.po_number && (
                      <small>PO: {viewDeliveryNote.po_number}</small>
                    )}
                  </div>
                  <div>
                    <small>Deliver to</small>
                    <h2>{viewDeliveryNote.customer}</h2>
                    {viewDeliveryNote.address && (
                      <p className="preserve-lines">
                        {viewDeliveryNote.address}
                      </p>
                    )}
                  </div>
                </div>
                <Table className="responsive-table">
                  <TableHeader>
                    <TableRow>
                      <TableHead>Item</TableHead>
                      <TableHead>Quantity</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {viewDeliveryNote.lines.map((l, i) => (
                      <TableRow key={i}>
                        <TableCell className="card-title">
                          <strong>{l.name}</strong>
                          <small>{l.sku}</small>
                        </TableCell>
                        <TableCell data-label="Quantity">
                          {l.quantity}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                {viewDeliveryNote.notes && (
                  <p className="preserve-lines">{viewDeliveryNote.notes}</p>
                )}
                <p className="helper">
                  Prepared by{" "}
                  {
                    data.agents.find((a) => a.id === viewDeliveryNote.agent)
                      ?.name
                  }{" "}
                  ·{" "}
                  {new Date(viewDeliveryNote.created).toLocaleDateString(
                    "en-OM",
                  )}
                </p>
              </div>
            </section>
          ) : viewInvoice ? (
            <section className="panel">
              <div className="section-head no-print">
                <button
                  className="text-button"
                  onClick={() => setViewInvoice(null)}
                >
                  <ArrowLeft size={16} /> All documents
                </button>
                <div className="actions">
                  <Choice
                    value={viewInvoice.status}
                    onChange={(status) =>
                      void perform("inv-status", async () => {
                        await api("invoice_status", {
                          id: viewInvoice.id,
                          status,
                        });
                        const d = await refresh();
                        setViewInvoice(
                          d.invoices.find((i) => i.id === viewInvoice.id) ||
                            null,
                        );
                      })
                    }
                    placeholder="Status"
                    items={["Draft", "Sent", "Paid", "Cancelled"].map(
                      (s) => ({ id: s, name: s }),
                    )}
                    disabled={!!busy}
                  />
                  <button className="secondary" onClick={() => window.print()}>
                    <Printer size={16} /> Print / PDF
                  </button>
                </div>
              </div>
              <form
                key={viewInvoice.id}
                className="po-form no-print"
                onSubmit={(e) => {
                  e.preventDefault();
                  const f = new FormData(e.currentTarget);
                  void perform("inv-po", async () => {
                    await api("invoice_update", {
                      id: viewInvoice.id,
                      poNumber: String(f.get("poNumber") || "") || undefined,
                    });
                    const d = await refresh();
                    setViewInvoice(
                      d.invoices.find((i) => i.id === viewInvoice.id) || null,
                    );
                    toast.success("Purchase order number saved");
                  });
                }}
              >
                <Field label="Purchase order number (optional)">
                  <input
                    name="poNumber"
                    maxLength={100}
                    defaultValue={viewInvoice.po_number ?? ""}
                    placeholder="Customer's PO number"
                  />
                </Field>
                <button className="secondary" disabled={!!busy}>
                  <Check size={16} /> Save
                </button>
              </form>
              <div className="print-document">
                <div className="document-heading">
                  <div className="document-brand">
                    <img
                      className="quotation-logo"
                      src={companyFor(viewInvoice.company_id).logo_path}
                      alt={companyFor(viewInvoice.company_id).name + " logo"}
                      width={72}
                      height={72}
                    />
                    <strong>
                      {companyFor(viewInvoice.company_id).trading_name ||
                        companyFor(viewInvoice.company_id).name}
                    </strong>
                    {companyFor(viewInvoice.company_id).trading_name && (
                      <small>{companyFor(viewInvoice.company_id).name}</small>
                    )}
                    {companyFor(viewInvoice.company_id).vat_number && (
                      <small>
                        VAT reg. {companyFor(viewInvoice.company_id).vat_number}
                      </small>
                    )}
                  </div>
                  <div className="document-meta">
                    <p className="eyebrow">Invoice</p>
                    <h1>INV-{String(viewInvoice.number).padStart(4, "0")}</h1>
                    <div className="document-meta-row">
                      <span>Date</span>
                      <strong>
                        {new Date(viewInvoice.created).toLocaleDateString(
                          "en-OM",
                        )}
                      </strong>
                    </div>
                    <div className="document-meta-row">
                      <span>Status</span>
                      <span className="badge">{viewInvoice.status}</span>
                    </div>
                    {viewInvoice.due_date && (
                      <div className="document-meta-row">
                        <span>Due</span>
                        <strong>
                          {new Date(viewInvoice.due_date).toLocaleDateString(
                            "en-OM",
                          )}
                        </strong>
                      </div>
                    )}
                    {viewInvoice.po_number && (
                      <div className="document-meta-row">
                        <span>PO number</span>
                        <strong>{viewInvoice.po_number}</strong>
                      </div>
                    )}
                  </div>
                </div>
                <div className="document-parties">
                  <div>
                    <p className="document-label">Billed to</p>
                    <h2>{viewInvoice.customer}</h2>
                    {viewInvoice.email && <p>{viewInvoice.email}</p>}
                  </div>
                </div>
                <Table className="responsive-table">
                  <TableHeader>
                    <TableRow>
                      <TableHead>Item</TableHead>
                      <TableHead className="num">Quantity</TableHead>
                      <TableHead className="num">Unit · OMR</TableHead>
                      <TableHead className="num">Total · OMR</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {viewInvoice.lines.map((l, i) => (
                      <TableRow key={i}>
                        <TableCell className="card-title">
                          <strong>{l.name}</strong>
                          <small>{l.sku}</small>
                        </TableCell>
                        <TableCell data-label="Quantity" className="num">
                          {l.quantity}
                        </TableCell>
                        <TableCell data-label="Unit · OMR" className="num">
                          {money(l.unitBaisa)}
                        </TableCell>
                        <TableCell data-label="Total · OMR" className="num">
                          {money(l.quantity * l.unitBaisa)}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                <div className="invoice-summary">
                  <div>
                    <span>Subtotal</span>
                    <strong>OMR {money(viewInvoice.subtotal)}</strong>
                  </div>
                  <div>
                    <span>VAT ({(data.vatRate * 100).toFixed(0)}%)</span>
                    <strong>OMR {money(viewInvoice.vat_baisa)}</strong>
                  </div>
                  <div className="invoice-summary-total">
                    <span>Total due</span>
                    <strong>OMR {money(viewInvoice.total)}</strong>
                  </div>
                </div>
                {viewInvoice.notes && (
                  <>
                    <p className="document-label">Notes</p>
                    <p className="preserve-lines">{viewInvoice.notes}</p>
                  </>
                )}
                <div className="document-footer">
                  <p className="document-thanks">
                    Thank you for your business.
                  </p>
                  <p className="helper">
                    Prepared by{" "}
                    {
                      data.agents.find((a) => a.id === viewInvoice.agent)
                        ?.name
                    }
                  </p>
                </div>
              </div>
            </section>
          ) : (
            <section className="panel">
              <div className="section-head">
                <h2>Documents</h2>
                <div className="actions">
                  <button
                    className={docTab === "delivery" ? "primary" : "secondary"}
                    onClick={() => setDocTab("delivery")}
                  >
                    <Truck size={16} /> Delivery notes
                  </button>
                  <button
                    className={docTab === "invoice" ? "primary" : "secondary"}
                    onClick={() => setDocTab("invoice")}
                  >
                    <Receipt size={16} /> Invoices
                  </button>
                </div>
              </div>
              <p className="helper">
                Generate these from an accepted quotation, on its detail page.
              </p>
              {docTab === "delivery" ? (
                deliveryList.length ? (
                  <Table className="responsive-table">
                    <TableHeader>
                      <TableRow>
                        <TableHead>Number</TableHead>
                        <TableHead>Customer</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Date</TableHead>
                        <TableHead>
                          <span className="sr-only">Open</span>
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {deliveryList.map((n) => (
                        <TableRow key={n.id}>
                          <TableCell className="card-title">
                            DN-{String(n.number).padStart(4, "0")}
                          </TableCell>
                          <TableCell data-label="Customer">
                            {n.customer}
                          </TableCell>
                          <TableCell data-label="Status">
                            <span className="badge">{n.status}</span>
                          </TableCell>
                          <TableCell data-label="Date">
                            {new Date(n.created).toLocaleDateString("en-OM")}
                          </TableCell>
                          <TableCell className="card-actions">
                            <button
                              className="secondary"
                              onClick={() => setViewDeliveryNote(n)}
                            >
                              Open
                            </button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                ) : (
                  <Blank title="No delivery notes yet">
                    Open an accepted quotation and choose “Delivery note” to
                    create one.
                  </Blank>
                )
              ) : invoiceList.length ? (
                <Table className="responsive-table">
                  <TableHeader>
                    <TableRow>
                      <TableHead>Number</TableHead>
                      <TableHead>Customer</TableHead>
                      <TableHead>Total · OMR</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Date</TableHead>
                      <TableHead>
                        <span className="sr-only">Open</span>
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {invoiceList.map((i) => (
                      <TableRow key={i.id}>
                        <TableCell className="card-title">
                          INV-{String(i.number).padStart(4, "0")}
                        </TableCell>
                        <TableCell data-label="Customer">
                          {i.customer}
                        </TableCell>
                        <TableCell data-label="Total · OMR">
                          {money(i.total)}
                        </TableCell>
                        <TableCell data-label="Status">
                          <span className="badge">{i.status}</span>
                        </TableCell>
                        <TableCell data-label="Date">
                          {new Date(i.created).toLocaleDateString("en-OM")}
                        </TableCell>
                        <TableCell className="card-actions">
                          <button
                            className="secondary"
                            onClick={() => setViewInvoice(i)}
                          >
                            Open
                          </button>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ) : (
                <Blank title="No invoices yet">
                  Open an accepted quotation and choose “Invoice” to create
                  one.
                </Blank>
              )}
            </section>
          )}
        </TabsContent>
        <TabsContent value="reports">
          <div className="stat-grid">
            <div className="stat-card">
              <span className="stat-label">Prepared</span>
              <strong className="stat-value">{data.quotes.length}</strong>
            </div>
            <div className="stat-card">
              <span className="stat-label">Open</span>
              <strong className="stat-value">{openQuotes.length}</strong>
            </div>
            <div className="stat-card stat-card-won">
              <span className="stat-label">Won</span>
              <strong className="stat-value">{wonQuotes.length}</strong>
            </div>
            <div className="stat-card stat-card-warn">
              <span className="stat-label">Lost</span>
              <strong className="stat-value">{lostQuotes.length}</strong>
            </div>
            <div className="stat-card stat-card-hold">
              <span className="stat-label">On hold</span>
              <strong className="stat-value">{onHoldQuotes.length}</strong>
            </div>
          </div>
          <div className="settings-grid">
            <section className="panel">
              <div className="section-head">
                <h2>Reasons for loss</h2>
                <span className="badge">{lostQuotes.length} lost</span>
              </div>
              {lostQuotes.length ? (
                <Table className="responsive-table">
                  <TableHeader>
                    <TableRow>
                      <TableHead>Reason</TableHead>
                      <TableHead>Count</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {reasonCounts(lostQuotes).map(([reason, count]) => (
                      <TableRow key={reason}>
                        <TableCell data-label="Reason">{reason}</TableCell>
                        <TableCell data-label="Count">{count}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ) : (
                <Blank title="No lost quotations">
                  Quotations marked Lost will show their reasons here.
                </Blank>
              )}
            </section>
            <section className="panel">
              <div className="section-head">
                <h2>Reasons on hold</h2>
                <span className="badge">{onHoldQuotes.length} on hold</span>
              </div>
              {onHoldQuotes.length ? (
                <Table className="responsive-table">
                  <TableHeader>
                    <TableRow>
                      <TableHead>Reason</TableHead>
                      <TableHead>Count</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {reasonCounts(onHoldQuotes).map(([reason, count]) => (
                      <TableRow key={reason}>
                        <TableCell data-label="Reason">{reason}</TableCell>
                        <TableCell data-label="Count">{count}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ) : (
                <Blank title="Nothing on hold">
                  Quotations marked On hold will show their reasons here.
                </Blank>
              )}
            </section>
          </div>
          {data.isAdmin && (
            <section className="panel">
              <div className="section-head">
                <h2>By sales agent</h2>
              </div>
              <Table className="responsive-table">
                <TableHeader>
                  <TableRow>
                    <TableHead>Agent</TableHead>
                    <TableHead>Prepared</TableHead>
                    <TableHead>Won</TableHead>
                    <TableHead>Lost</TableHead>
                    <TableHead>On hold</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {agentBreakdown.map((row) => (
                    <TableRow key={row.agent.id}>
                      <TableCell data-label="Agent" className="card-title">
                        {row.agent.name}
                      </TableCell>
                      <TableCell data-label="Prepared">{row.total}</TableCell>
                      <TableCell data-label="Won">{row.won}</TableCell>
                      <TableCell data-label="Lost">{row.lost}</TableCell>
                      <TableCell data-label="On hold">{row.onHold}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </section>
          )}
        </TabsContent>
        <TabsContent value="pricing">
          <section className="panel">
            <div className="section-head">
              <h2>Pricing queue</h2>
              <span className="badge">{pricingQueue.length} awaiting</span>
            </div>
            {pricingQueue.length ? (
              <Table className="responsive-table">
                <TableHeader>
                  <TableRow>
                    <TableHead>Quotation</TableHead>
                    <TableHead>Agent</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Items</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {pricingQueue.map((q) => (
                    <TableRow key={q.id}>
                      <TableCell data-label="Quotation" className="card-title">
                        Q-{String(q.number).padStart(4, "0")}
                      </TableCell>
                      <TableCell data-label="Agent">
                        {agentName(q.agent)}
                      </TableCell>
                      <TableCell data-label="Customer">
                        {q.customer}
                      </TableCell>
                      <TableCell data-label="Items">
                        {q.lines.length}
                      </TableCell>
                      <TableCell data-label="Date">
                        {new Date(q.created).toLocaleDateString("en-OM")}
                      </TableCell>
                      <TableCell data-label="" className="card-actions">
                        <button
                          className="secondary"
                          onClick={() => openPricing(q)}
                        >
                          <DollarSign size={16} /> Price this quote
                        </button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            ) : (
              <Blank title="Nothing waiting on you">
                New quotations appear here as soon as an agent saves one.
              </Blank>
            )}
          </section>
        </TabsContent>
        <TabsContent value="accounts">
          <div className="stat-grid">
            <div className="stat-card">
              <span className="stat-label">Total invoices</span>
              <strong className="stat-value">{data.invoices.length}</strong>
            </div>
            <div className="stat-card">
              <span className="stat-label">Pending</span>
              <strong className="stat-value">{pendingInvoices.length}</strong>
            </div>
            <div className="stat-card">
              <span className="stat-label">Paid</span>
              <strong className="stat-value">{paidInvoices.length}</strong>
            </div>
            <div className="stat-card stat-card-warn">
              <span className="stat-label">Overdue</span>
              <strong className="stat-value">{overdueInvoices.length}</strong>
            </div>
          </div>
          <section className="panel">
            <div className="section-head">
              <h2>Invoices</h2>
              <span className="badge">{data.invoices.length} total</span>
            </div>
            {data.invoices.length ? (
              <Table className="responsive-table">
                <TableHeader>
                  <TableRow>
                    <TableHead>Invoice</TableHead>
                    <TableHead>Agent</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Total</TableHead>
                    <TableHead>Due date</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.invoices.map((i) => (
                    <TableRow key={i.id}>
                      <TableCell data-label="Invoice" className="card-title">
                        INV-{String(i.number).padStart(4, "0")}
                      </TableCell>
                      <TableCell data-label="Agent">
                        {agentName(i.agent)}
                      </TableCell>
                      <TableCell data-label="Customer">
                        {i.customer}
                      </TableCell>
                      <TableCell data-label="Total">
                        OMR {money(i.total)}
                      </TableCell>
                      <TableCell data-label="Due date">
                        {i.due_date ? (
                          <span
                            className={
                              isOverdue(i) ? "overdue-date" : undefined
                            }
                          >
                            {isOverdue(i) && <AlertTriangle size={14} />}{" "}
                            {new Date(i.due_date).toLocaleDateString("en-OM")}
                          </span>
                        ) : (
                          "—"
                        )}
                      </TableCell>
                      <TableCell data-label="Status">
                        <span className="badge">
                          {i.status === "Paid"
                            ? `Paid ${i.paid_at ? new Date(i.paid_at).toLocaleDateString("en-OM") : ""}`
                            : i.status}
                        </span>
                      </TableCell>
                      <TableCell data-label="" className="card-actions">
                        {i.status === "Paid" ? (
                          <button
                            className="secondary"
                            onClick={() => void undoPayment(i)}
                          >
                            Undo
                          </button>
                        ) : (
                          <button
                            className="secondary"
                            onClick={() => openPayment(i)}
                          >
                            <Wallet size={16} /> Mark as paid
                          </button>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            ) : (
              <Blank title="No invoices yet">
                Invoices appear here once an agent creates one from an
                accepted quotation.
              </Blank>
            )}
          </section>
        </TabsContent>
        <TabsContent value="studio">
          <div className="studio-layout">
            <form className="panel" onSubmit={generateMockup}>
              <div className="assistant-icon">
                <Sparkles size={22} />
              </div>
              <h2>Create a branded mockup</h2>
              <p>Use the actual product photograph and your customer’s logo.</p>
              <Field label="Product">
                <Choice
                  value={studioProduct}
                  onChange={setStudioProduct}
                  placeholder="Select a product"
                  items={data.products.map((p) => ({
                    id: p.id,
                    name: p.name + " · " + p.sku,
                  }))}
                />
              </Field>
              {selectedProduct && (
                <div className="studio-product">
                  <ProductPhoto product={selectedProduct} />
                  <div>
                    <strong>{selectedProduct.name}</strong>
                    <small>{selectedProduct.sku}</small>
                  </div>
                </div>
              )}
              <Field label="Customer logo · PNG, JPG or WebP">
                <input
                  name="logo"
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  required
                />
              </Field>
              <Field label="Product photograph (optional override)">
                <input
                  name="photo"
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                />
              </Field>
              <p className="helper">
                Maximum 8 MB per image. A transparent PNG works well for logos.
              </p>
              <Field label="Logo placement and printing finish">
                <textarea
                  name="instruction"
                  rows={4}
                  maxLength={1500}
                  placeholder="White logo, centred on the front of the bottle, screen-printed finish."
                />
              </Field>
              <button
                className="primary wide"
                disabled={
                  !!busy || !agent || !studioProduct || !data.aiConfigured
                }
              >
                <Sparkles size={16} />
                {busy === "mockup"
                  ? "Creating your mockup…"
                  : "Generate mockup"}
              </button>
              {!data.aiConfigured && (
                <p className="notice">
                  Connect an AI account to enable image generation.
                </p>
              )}
              <p className="helper">
                AI previews are visual concepts. Review logo lettering,
                placement and print suitability before customer approval.
              </p>
            </form>
            <section className="panel">
              <div className="section-head">
                <h2>Your mockups</h2>
                <span className="badge">{mockups.length} saved</span>
              </div>
              {mockups.length ? (
                <div className="mockup-grid">
                  {mockups.map((m) => (
                    <article key={m.id}>
                      <img
                        src={"/api/assets?path=" + encodeURIComponent(m.path)}
                        alt={"Branded mockup of " + m.productName}
                      />
                      <div className="section-head">
                        <strong>{m.productName}</strong>
                        <a
                          className="secondary"
                          href={
                            "/api/assets?path=" + encodeURIComponent(m.path)
                          }
                          download={"mockup-" + m.id + ".png"}
                        >
                          <Download size={16} /> Save
                        </a>
                      </div>
                    </article>
                  ))}
                </div>
              ) : (
                <Blank title="Your product, with their identity">
                  Select a product and upload a logo. Generated mockups will be
                  saved here for the selected sales agent.
                </Blank>
              )}
            </section>
          </div>
        </TabsContent>
        <TabsContent value="messages">
          <div className="messages-layout">
            <aside className="conversation-list panel">
              <div className="section-head">
                <h2>Messages</h2>
                <button
                  className="secondary"
                  onClick={() => setComposeDialog(true)}
                >
                  <Plus size={16} /> New
                </button>
              </div>
              {conversations.length ? (
                <div className="conversation-items">
                  {conversations.map((c) => (
                    <button
                      key={c.id}
                      type="button"
                      className={
                        "conversation-item" +
                        (c.id === activeConversation ? " active" : "")
                      }
                      onClick={() => setActiveConversation(c.id)}
                    >
                      <div className="conversation-item-head">
                        <strong>{c.title || "Conversation"}</strong>
                        {c.unreadCount > 0 && (
                          <span className="unread-dot">{c.unreadCount}</span>
                        )}
                      </div>
                      <p>
                        {c.lastMessage
                          ? (c.lastMessage.senderAgentId === data.userAgentId
                              ? "You: "
                              : "") +
                            (c.lastMessage.body || "Sent an attachment")
                          : "No messages yet"}
                      </p>
                    </button>
                  ))}
                </div>
              ) : (
                <Blank title="No conversations yet">
                  Start a conversation with a teammate to share updates and
                  files.
                </Blank>
              )}
            </aside>
            <section className="thread panel">
              {activeConversation && activeConversationSummary ? (
                <>
                  <div className="section-head">
                    <h2>{activeConversationSummary.title || "Conversation"}</h2>
                  </div>
                  <div className="thread-messages">
                    {threadMessages.length ? (
                      threadMessages.map((m) => (
                        <div
                          key={m.id}
                          className={
                            "message-bubble" +
                            (m.senderAgentId === data.userAgentId
                              ? " mine"
                              : "")
                          }
                        >
                          <div className="message-meta">
                            <strong>
                              {m.senderAgentId === data.userAgentId
                                ? "You"
                                : m.senderName}
                            </strong>
                            <span>
                              {new Date(m.created).toLocaleString("en-OM")}
                            </span>
                          </div>
                          {m.body && <p>{m.body}</p>}
                          {m.attachments.length > 0 && (
                            <div className="message-attachments">
                              {m.attachments.map((a) =>
                                a.mime.startsWith("image/") ? (
                                  <a
                                    key={a.id}
                                    href={
                                      "/api/messages/asset?id=" +
                                      encodeURIComponent(a.id)
                                    }
                                    target="_blank"
                                    rel="noreferrer"
                                  >
                                    <img
                                      src={
                                        "/api/messages/asset?id=" +
                                        encodeURIComponent(a.id)
                                      }
                                      alt={a.filename}
                                    />
                                  </a>
                                ) : (
                                  <a
                                    key={a.id}
                                    className="message-file"
                                    href={
                                      "/api/messages/asset?id=" +
                                      encodeURIComponent(a.id)
                                    }
                                    target="_blank"
                                    rel="noreferrer"
                                  >
                                    <FileIcon size={16} />
                                    {a.filename}
                                    <small>{formatBytes(a.size)}</small>
                                  </a>
                                ),
                              )}
                            </div>
                          )}
                        </div>
                      ))
                    ) : (
                      <Blank title="Say hello">
                        Send the first message in this conversation.
                      </Blank>
                    )}
                  </div>
                  <form className="thread-compose" onSubmit={sendMessage}>
                    {messageFiles.length > 0 && (
                      <div className="compose-files">
                        {messageFiles.map((f, idx) => (
                          <span key={idx} className="compose-file-chip">
                            {f.name}
                            <button
                              type="button"
                              onClick={() =>
                                setMessageFiles((files) =>
                                  files.filter((_, i) => i !== idx),
                                )
                              }
                            >
                              <X size={12} />
                            </button>
                          </span>
                        ))}
                      </div>
                    )}
                    <div className="thread-compose-row">
                      <label className="attach-button">
                        <Paperclip size={18} />
                        <input
                          type="file"
                          multiple
                          hidden
                          onChange={(e) => {
                            const picked = Array.from(e.target.files || []);
                            setMessageFiles((files) =>
                              [...files, ...picked].slice(0, 3),
                            );
                            e.target.value = "";
                          }}
                        />
                      </label>
                      <input
                        value={messageDraft}
                        onChange={(e) => setMessageDraft(e.target.value)}
                        placeholder="Write a message"
                        maxLength={4000}
                      />
                      <button
                        className="primary"
                        disabled={!!busy}
                        type="submit"
                      >
                        <SendHorizontal size={18} />
                      </button>
                    </div>
                  </form>
                </>
              ) : (
                <Blank title="Select a conversation">
                  Choose a conversation on the left, or start a new one.
                </Blank>
              )}
            </section>
          </div>
        </TabsContent>
        <TabsContent value="settings">
          <div className="settings-grid">
            {data.isAdmin ? (
              <form
                className="panel"
                key={data.settings.updated}
                onSubmit={(e) => {
                  e.preventDefault();
                  const f = new FormData(e.currentTarget);
                  void perform("settings", async () => {
                    await api("settings", {
                      company: String(f.get("company")),
                      rate: Number(f.get("rate")),
                      vatNumber: String(f.get("vatNumber") || "") || undefined,
                    });
                    await refresh();
                    toast.success("Settings saved");
                  });
                }}
              >
                <h2>Company and currency</h2>
                <Field label="Company name">
                  <input
                    name="company"
                    defaultValue={data.settings.company}
                    required
                    maxLength={200}
                  />
                </Field>
                <Field label="Conversion rate · OMR per 1 AED">
                  <input
                    name="rate"
                    type="number"
                    min="0.000001"
                    max="100"
                    step="0.000001"
                    defaultValue={data.settings.rate || ""}
                    placeholder="Enter your approved exchange rate"
                    required
                  />
                </Field>
                <Field label="VAT registration number (optional)">
                  <input
                    name="vatNumber"
                    maxLength={50}
                    defaultValue={data.settings.vat_number ?? ""}
                    placeholder="Shown on printed invoices"
                  />
                </Field>
                <p className="helper">
                  Supplier prices are in AED. The initial rate of 0.104699 is
                  indicative, based on currency pegs; it excludes bank fees.
                  Change it to your business costing rate. Existing quotations
                  keep their saved rate and prices. Selling prices are set
                  separately. Invoices add {(data.vatRate * 100).toFixed(0)}%
                  Oman VAT automatically.
                </p>
                <button className="primary" disabled={!!busy}>
                  Save settings
                </button>
              </form>
            ) : (
              <section className="panel">
                <h2>Company and currency</h2>
                <p className="helper">
                  {data.settings.company} · {data.settings.rate || "—"} OMR
                  per AED
                </p>
                <p className="helper">
                  Only an administrator can change these values.
                </p>
              </section>
            )}
            <section className="panel">
              <h2>Companies</h2>
              <p className="helper">
                An agent picks one of these when creating a quotation. It
                decides the logo and legal name shown on that quotation, its
                delivery note and its invoice.
              </p>
              {data.companies.map((c) =>
                data.isAdmin ? (
                  <form
                    key={c.id}
                    className="company-row"
                    onSubmit={(e) => {
                      e.preventDefault();
                      const f = new FormData(e.currentTarget);
                      void perform("company-" + c.id, async () => {
                        await api("company_update", {
                          id: c.id,
                          vatNumber:
                            String(f.get("vatNumber") || "") || undefined,
                        });
                        await refresh();
                        toast.success("Company updated");
                      });
                    }}
                  >
                    <img
                      className="company-row-logo"
                      src={c.logo_path}
                      alt=""
                      width={40}
                      height={40}
                    />
                    <div className="company-row-name">
                      <strong>{c.name}</strong>
                      {c.trading_name && (
                        <small>Trading as {c.trading_name}</small>
                      )}
                    </div>
                    <input
                      name="vatNumber"
                      defaultValue={c.vat_number ?? ""}
                      placeholder="VAT registration number"
                      maxLength={50}
                    />
                    <button className="secondary" disabled={!!busy}>
                      Save
                    </button>
                  </form>
                ) : (
                  <div key={c.id} className="company-row">
                    <img
                      className="company-row-logo"
                      src={c.logo_path}
                      alt=""
                      width={40}
                      height={40}
                    />
                    <div className="company-row-name">
                      <strong>{c.name}</strong>
                      {c.trading_name && (
                        <small>Trading as {c.trading_name}</small>
                      )}
                    </div>
                    {c.vat_number && (
                      <small>VAT reg. {c.vat_number}</small>
                    )}
                  </div>
                ),
              )}
            </section>
            {data.isAdmin && (
              <section className="panel">
                <h2>Sales agents</h2>
                <p className="helper">
                  Add a staff sign-in so an agent can access their own
                  quotations directly, or leave email blank to keep a local
                  profile only.
                </p>
                {data.agents.map((a) => (
                  <div className="agent-row" key={a.id}>
                    <Users size={18} />
                    <strong>{a.name}</strong>
                    <span className="badge">
                      {a.role === "pricing"
                        ? "Pricing"
                        : a.role === "accounts"
                          ? "Accounts"
                          : a.email
                            ? "Sales agent"
                            : "No sign-in"}
                    </span>
                    {a.email && (
                      <button
                        type="button"
                        className="text-button"
                        onClick={() => {
                          setPasswordAgent(a);
                          setPasswordDraft("");
                        }}
                      >
                        Change password
                      </button>
                    )}
                  </div>
                ))}
                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    const form = e.currentTarget;
                    const f = new FormData(form);
                    void perform("agent", async () => {
                      const r = await api("agent", {
                        name: String(f.get("name")),
                        email: String(f.get("email") || "") || undefined,
                        password:
                          String(f.get("password") || "") || undefined,
                        role: String(f.get("role") || "") || undefined,
                      });
                      await refresh();
                      if (!draft) {
                        setAgent(r.id);
                        setMockups([]);
                      }
                      form.reset();
                      toast.success("Sales agent added");
                    });
                  }}
                >
                  <Field label="New agent name">
                    <input
                      name="name"
                      required
                      maxLength={200}
                      placeholder="Full name"
                    />
                  </Field>
                  <div className="form-grid">
                    <Field label="Sign-in email (optional)">
                      <input
                        name="email"
                        type="email"
                        placeholder="name@company.com"
                      />
                    </Field>
                    <Field label="Sign-in password (optional)">
                      <input
                        name="password"
                        type="password"
                        minLength={8}
                        maxLength={72}
                        placeholder="At least 8 characters"
                        autoComplete="new-password"
                      />
                    </Field>
                    <Field label="Role">
                      <select name="role" className="choice">
                        <option value="">Sales agent</option>
                        <option value="pricing">Pricing</option>
                        <option value="accounts">Accounts</option>
                      </select>
                    </Field>
                  </div>
                  <button className="secondary" disabled={!!busy}>
                    <Plus size={16} /> Add sales agent
                  </button>
                </form>
              </section>
            )}
            <section className="panel">
              <h2>Connections</h2>
              <div className="connection">
                <div>
                  <strong>Luxury Trading</strong>
                  <p>Products, images, AED costs and supplier stock</p>
                </div>
                <span className="badge">
                  {data.supplierConfigured ? "Configured" : "Needs credentials"}
                </span>
              </div>
              <div className="connection">
                <div>
                  <strong>AI assistant & mockups</strong>
                  <p>Quotation suggestions and branded product previews</p>
                </div>
                <span className="badge">
                  {data.aiConfigured ? "Configured" : "Needs API key"}
                </span>
              </div>
              <p className="helper">
                Connection secrets are configured on the server and never
                included in the public source code.
              </p>
            </section>
            <section className="panel">
              <h2>About Cloud ERP</h2>
              <p>Corporate gifting workspace for Oman.</p>
              <strong>Developed by Irfan Dossani</strong>
              <p className="helper">
                Local development version. Quotation and inventory workflows are
                available here; deployment and staff access are a later step.
              </p>
            </section>
          </div>
        </TabsContent>
      </Tabs>
      <footer className="developer-credit">Developed by Irfan Dossani</footer>
      <Dialog open={productDialog} onOpenChange={setProductDialog}>
        <DialogContent className="product-dialog">
          <DialogTitle>
            {editProduct
              ? "Update stock and selling price"
              : addToDraft
                ? "Add a product to this quotation"
                : "Add product"}
          </DialogTitle>
          <DialogDescription>
            {editProduct
              ? editProduct.name
              : "Create a catalogue record now. You can use it again on future quotations."}
          </DialogDescription>
          <form onSubmit={saveProduct} key={editProduct?.id || "new"}>
            {!editProduct && (
              <>
                <div className="form-grid">
                  <Field label="Product name">
                    <input name="name" required maxLength={200} />
                  </Field>
                  <Field label="SKU">
                    <input name="sku" required maxLength={200} />
                  </Field>
                </div>
                <Field label="Description">
                  <textarea name="description" rows={2} maxLength={5000} />
                </Field>
                <Field label="Category">
                  <input name="category" maxLength={1000} />
                </Field>
                <Field label="Product photo (optional)">
                  <input
                    name="photo"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                  />
                </Field>
                <p className="helper">
                  PNG, JPG or WebP, up to 8 MB.
                </p>
              </>
            )}
            <div className={data.isAdmin ? "form-grid" : undefined}>
              {data.isAdmin && (
                <Field label="Our warehouse quantity">
                  <input
                    name="warehouse"
                    type="number"
                    min="0"
                    max="1000000"
                    step="1"
                    defaultValue={editProduct?.warehouse_stock ?? 0}
                    required
                  />
                </Field>
              )}
              <Field label="Unit selling price · OMR">
                <input
                  name="sale"
                  type="number"
                  min="0"
                  max="1000000"
                  step="0.001"
                  defaultValue={
                    editProduct?.sale_baisa === null
                      ? ""
                      : editProduct
                        ? editProduct.sale_baisa! / 1000
                        : ""
                  }
                  placeholder="Set later"
                />
              </Field>
            </div>
            {!data.isAdmin && !editProduct && (
              <p className="helper">
                New products are added with zero warehouse quantity. An
                administrator can set stock afterwards.
              </p>
            )}
            {!editProduct && data.isAdmin && (
              <Field label="Unit cost · OMR">
                <input
                  name="cost"
                  type="number"
                  min="0"
                  max="1000000"
                  step="0.001"
                  defaultValue={0}
                  required
                />
              </Field>
            )}
            <button className="primary wide" disabled={!!busy}>
              {busy === "product"
                ? "Saving…"
                : editProduct
                  ? "Save changes"
                  : addToDraft
                    ? "Create and add to quotation"
                    : "Create product"}
            </button>
          </form>
        </DialogContent>
      </Dialog>
      <Dialog open={customerDialog} onOpenChange={setCustomerDialog}>
        <DialogContent>
          <DialogTitle>
            {editCustomer ? "Edit customer" : "New customer"}
          </DialogTitle>
          <DialogDescription>
            {editCustomer
              ? editCustomer.company
              : "Add a company and contact to start tracking them through your pipeline."}
          </DialogDescription>
          <form onSubmit={saveCustomer} key={editCustomer?.id || "new"}>
            <div className="form-grid">
              <Field label="Company">
                <input
                  name="company"
                  required
                  maxLength={200}
                  defaultValue={editCustomer?.company}
                />
              </Field>
              <Field label="Contact name">
                <input
                  name="contactName"
                  required
                  maxLength={200}
                  defaultValue={editCustomer?.contact_name}
                />
              </Field>
            </div>
            <div className="form-grid">
              <Field label="Email (optional)">
                <input
                  name="email"
                  type="email"
                  defaultValue={editCustomer?.email ?? ""}
                />
              </Field>
              <Field label="Phone (optional)">
                <input
                  name="phone"
                  maxLength={50}
                  defaultValue={editCustomer?.phone ?? ""}
                />
              </Field>
            </div>
            <div className="form-grid">
              <Field label="Address (optional)">
                <textarea
                  name="address"
                  rows={2}
                  maxLength={2000}
                  defaultValue={editCustomer?.address ?? ""}
                />
              </Field>
              <Field label="VAT registration number (optional)">
                <input
                  name="vatNumber"
                  maxLength={50}
                  defaultValue={editCustomer?.vat_number ?? ""}
                />
              </Field>
            </div>
            <div className="form-grid">
              <Field label="Pipeline stage">
                <select
                  name="stage"
                  className="choice"
                  defaultValue={editCustomer?.stage ?? "New Lead"}
                >
                  {CRM_STAGES.map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Follow up on (optional)">
                <input
                  name="followUpAt"
                  type="date"
                  defaultValue={editCustomer?.follow_up_at ?? ""}
                />
              </Field>
            </div>
            <Field label="Notes">
              <textarea
                name="notes"
                rows={3}
                maxLength={5000}
                defaultValue={editCustomer?.notes ?? ""}
              />
            </Field>
            <button className="primary wide" disabled={!!busy}>
              {editCustomer ? "Save changes" : "Add customer"}
            </button>
          </form>
        </DialogContent>
      </Dialog>
      <Dialog
        open={composeDialog}
        onOpenChange={(open) => {
          setComposeDialog(open);
          if (!open) setComposeRecipients([]);
        }}
      >
        <DialogContent>
          <DialogTitle>New message</DialogTitle>
          <DialogDescription>
            Pick who to message. Select more than one person to start a group
            conversation.
          </DialogDescription>
          {directory.length ? (
            <div className="recipient-list">
              {directory.map((p) => (
                <label key={p.id} className="recipient-row">
                  <input
                    type="checkbox"
                    checked={composeRecipients.includes(p.id)}
                    onChange={(e) =>
                      setComposeRecipients((ids) =>
                        e.target.checked
                          ? [...ids, p.id]
                          : ids.filter((id) => id !== p.id),
                      )
                    }
                  />
                  {p.name}
                </label>
              ))}
            </div>
          ) : (
            <Empty>
              <EmptyHeader>
                <EmptyTitle>No other staff accounts yet</EmptyTitle>
                <EmptyDescription>
                  Add a sign-in email for another agent to message them.
                </EmptyDescription>
              </EmptyHeader>
            </Empty>
          )}
          {directory.length > 0 && (
            <button
              className="primary wide"
              disabled={!!busy || composeRecipients.length === 0}
              onClick={() => void startConversation()}
            >
              <MessageCircle size={16} /> Start conversation
            </button>
          )}
        </DialogContent>
      </Dialog>
      <Dialog
        open={!!pricingQuote}
        onOpenChange={(open) => !open && setPricingQuote(null)}
      >
        <DialogContent>
          <DialogTitle>
            {pricingQuote &&
              `Price Q-${String(pricingQuote.number).padStart(4, "0")}`}
          </DialogTitle>
          <DialogDescription>
            {pricingQuote &&
              `For ${pricingQuote.customer} · prepared by ${agentName(pricingQuote.agent)}`}
          </DialogDescription>
          {pricingQuote && (
            <form className="pricing-form" onSubmit={submitPricing}>
              {pricingQuote.lines.map((l) => (
                <div className="pricing-line" key={l.productId}>
                  <div>
                    <strong>{l.name}</strong>
                    <small>
                      {l.sku} · Qty {l.quantity}
                      {l.branding ? " · " + l.branding : ""}
                    </small>
                  </div>
                  <Field label="Unit price · OMR">
                    <input
                      type="number"
                      min="0"
                      max="1000000"
                      step="0.001"
                      value={(pricingLines[l.productId] ?? 0) / 1000}
                      onChange={(e) =>
                        setPricingLines((lines) => ({
                          ...lines,
                          [l.productId]: Math.round(
                            Number(e.target.value) * 1000,
                          ),
                        }))
                      }
                    />
                  </Field>
                  <div className="line-total">
                    <small>Line total · OMR</small>
                    <strong>
                      {money(l.quantity * (pricingLines[l.productId] ?? 0))}
                    </strong>
                  </div>
                </div>
              ))}
              <div className="quote-bottom">
                <div>
                  <small>Subtotal · OMR</small>
                  <strong>
                    {money(
                      pricingQuote.lines.reduce(
                        (n, l) =>
                          n + l.quantity * (pricingLines[l.productId] ?? 0),
                        0,
                      ),
                    )}
                  </strong>
                </div>
                <button className="primary" disabled={!!busy}>
                  <Check size={16} /> Submit pricing
                </button>
              </div>
            </form>
          )}
        </DialogContent>
      </Dialog>
      <Dialog
        open={!!paymentInvoice}
        onOpenChange={(open) => !open && setPaymentInvoice(null)}
      >
        <DialogContent>
          <DialogTitle>
            {paymentInvoice &&
              `Record payment · INV-${String(paymentInvoice.number).padStart(4, "0")}`}
          </DialogTitle>
          <DialogDescription>
            {paymentInvoice &&
              `${paymentInvoice.customer} · OMR ${money(paymentInvoice.total)}`}
          </DialogDescription>
          {paymentInvoice && (
            <form className="pricing-form" onSubmit={submitPayment}>
              <Field label="Payment received on">
                <input
                  type="date"
                  required
                  value={paymentDate}
                  onChange={(e) => setPaymentDate(e.target.value)}
                />
              </Field>
              <div className="quote-bottom">
                <button className="primary" disabled={!!busy}>
                  <Check size={16} /> Confirm payment
                </button>
              </div>
            </form>
          )}
        </DialogContent>
      </Dialog>
      <Dialog
        open={!!outcomeQuote}
        onOpenChange={(open) => !open && setOutcomeQuote(null)}
      >
        <DialogContent>
          <DialogTitle>
            {outcomeQuote &&
              `Set outcome · Q-${String(outcomeQuote.number).padStart(4, "0")}`}
          </DialogTitle>
          <DialogDescription>
            {outcomeQuote && outcomeQuote.customer}
          </DialogDescription>
          {outcomeQuote && (
            <form className="pricing-form" onSubmit={submitOutcome}>
              <Field label="Outcome">
                <Choice
                  value={outcomeChoice || "Open"}
                  onChange={(v) => setOutcomeChoice(v === "Open" ? "" : v)}
                  placeholder="Outcome"
                  items={[
                    { id: "Open", name: "Open / in progress" },
                    { id: "Won", name: "Won" },
                    { id: "Lost", name: "Lost" },
                    { id: "OnHold", name: "On hold" },
                  ]}
                />
              </Field>
              {(outcomeChoice === "Lost" || outcomeChoice === "OnHold") && (
                <Field
                  label={
                    outcomeChoice === "Lost"
                      ? "Reason for losing this quotation"
                      : "Reason it's on hold"
                  }
                >
                  <textarea
                    rows={3}
                    maxLength={1000}
                    required
                    value={outcomeReason}
                    onChange={(e) => setOutcomeReason(e.target.value)}
                  />
                </Field>
              )}
              <div className="quote-bottom">
                <button className="primary" disabled={!!busy}>
                  <Check size={16} /> Save outcome
                </button>
              </div>
            </form>
          )}
        </DialogContent>
      </Dialog>
      <Dialog
        open={!!passwordAgent}
        onOpenChange={(open) => !open && setPasswordAgent(null)}
      >
        <DialogContent>
          <DialogTitle>
            {passwordAgent && `Change password · ${passwordAgent.name}`}
          </DialogTitle>
          <DialogDescription>
            {passwordAgent && passwordAgent.email}
          </DialogDescription>
          <form className="pricing-form" onSubmit={submitPassword}>
            <Field label="New password">
              <input
                type="password"
                required
                minLength={8}
                maxLength={72}
                autoComplete="new-password"
                value={passwordDraft}
                onChange={(e) => setPasswordDraft(e.target.value)}
                placeholder="At least 8 characters"
              />
            </Field>
            <div className="quote-bottom">
              <button className="primary" disabled={!!busy}>
                <Check size={16} /> Update password
              </button>
            </div>
          </form>
        </DialogContent>
      </Dialog>
    </main>
  );
}
function ProductPhoto({ product }: { product: Product }) {
  const [failed, setFailed] = useState(false);
  return (
    <div className="product-photo">
      {product.image && !failed ? (
        <img
          src={product.image}
          alt=""
          loading="lazy"
          referrerPolicy="no-referrer"
          onError={() => setFailed(true)}
        />
      ) : (
        <ImageIcon size={22} />
      )}
    </div>
  );
}
function Pager({
  page,
  count,
  size,
  onPage,
}: {
  page: number;
  count: number;
  size: number;
  onPage: (n: number) => void;
}) {
  return (
    <div className="pager">
      <small>
        {count ? Math.min(page * size + 1, count) : 0}–
        {Math.min((page + 1) * size, count)} of {count} products
      </small>
      <div className="actions">
        <button
          className="secondary"
          disabled={page === 0}
          onClick={() => onPage(page - 1)}
        >
          Previous
        </button>
        <button
          className="secondary"
          disabled={(page + 1) * size >= count}
          onClick={() => onPage(page + 1)}
        >
          Next
        </button>
      </div>
    </div>
  );
}
