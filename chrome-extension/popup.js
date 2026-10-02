const DEFAULT_SERVER = "https://clouderp.mugdiinvestments.com";
const $ = (id) => document.getElementById(id);
const FIELDS = ["name", "title", "company", "email", "phone", "website", "notes"];
let conn = { server: DEFAULT_SERVER, token: "" };
let sourceUrl = "";
let linkedinUrl = "";

const store = {
  get: () => chrome.storage.local.get({ server: DEFAULT_SERVER, token: "" }),
  set: (v) => chrome.storage.local.set(v),
};

function show(which) {
  $("setup").hidden = which !== "setup";
  $("capture").hidden = which !== "capture";
}
function say(text, kind) {
  const s = $("status");
  s.textContent = text;
  s.className = kind || "";
}

async function api(path, init = {}) {
  const r = await fetch(conn.server + path, {
    ...init,
    headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: "Bearer " + conn.token },
  });
  const d = await r.json().catch(() => ({}));
  if (!r.ok) {
    const e = new Error(d.error || d.message || "Something went wrong (" + r.status + ")");
    e.status = r.status;
    throw e;
  }
  return d;
}

function normaliseServer(raw) {
  const u = new URL(raw.trim());
  if (!/^https?:$/.test(u.protocol)) throw new Error("Use an http(s) address");
  return u.origin;
}

async function connect() {
  const msg = $("setupMsg");
  try {
    const server = normaliseServer($("server").value || DEFAULT_SERVER);
    const token = $("token").value.trim();
    if (!token) throw new Error("Paste your capture token");
    if (server !== DEFAULT_SERVER) {
      const ok = await chrome.permissions.request({ origins: [server + "/*"] });
      if (!ok) throw new Error("Permission to contact " + server + " was declined");
    }
    conn = { server, token };
    const me = await api("/api/capture/ping");
    await store.set(conn);
    $("token").value = "";
    await start(me);
  } catch (e) {
    msg.textContent = e.status === 401 ? "That token isn't valid. Create a new one in the ERP." : e.message;
  }
}

async function readPage() {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab || !/^https?:/.test(tab.url || "")) throw new Error("not a web page");
    // extract.js defines extract(); the second call runs in the same page context.
    await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ["extract.js"] });
    const [run] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: () => ({ ...extract(document, location), selection: String(getSelection() || "").trim().slice(0, 1000) }),
    });
    return run.result;
  } catch (e) {
    return null;
  }
}

async function start(me) {
  $("who").textContent = me.name;
  $("keepRow").hidden = !me.hasAgent;
  show("capture");
  say("");
  const data = await readPage();
  if (!data) {
    $("pageNote").textContent = "This page can't be read. Fill in the details by hand.";
    sourceUrl = "";
    return;
  }
  sourceUrl = data.sourceUrl;
  linkedinUrl = data.linkedinUrl;
  FIELDS.forEach((f) => {
    $(f).value = f === "notes" ? data.selection || "" : data[f] || "";
  });
  $("pageNote").textContent = "Check the details, fix anything that is wrong, then send.";
}

async function send() {
  const body = {
    ...Object.fromEntries(FIELDS.map((f) => [f, $(f).value.trim()])),
    linkedinUrl,
    sourceUrl,
    location: "",
    keepForMe: $("keep").checked,
  };
  if (!body.name && !body.company) return say("Enter a name or a company first.", "warn");
  $("send").disabled = true;
  say("Sending…");
  try {
    const r = await api("/api/capture/prospect", { method: "POST", body: JSON.stringify(body) });
    if (r.duplicate === "customer") say("Already one of your customers (same email).", "warn");
    else if (r.duplicate) say("Already in the prospect inbox. Any missing details were added.", "warn");
    else say(r.mine ? "Sent. It's on your list." : r.assignedTo ? "Sent. Assigned to " + r.assignedTo + "." : "Sent. Waiting to be assigned.", "ok");
  } catch (e) {
    say(e.status === 401 ? "Your token no longer works. Use Change connection." : e.message, "err");
  } finally {
    $("send").disabled = false;
  }
}

$("connect").addEventListener("click", connect);
$("send").addEventListener("click", send);
$("settings").addEventListener("click", () => {
  $("server").value = conn.server;
  $("setupMsg").textContent = "Paste a token to reconnect.";
  show("setup");
});

(async () => {
  conn = await store.get();
  $("server").value = conn.server;
  if (!conn.token) return show("setup");
  try {
    await start(await api("/api/capture/ping"));
  } catch (e) {
    $("setupMsg").textContent = e.status === 401 ? "Your token no longer works. Paste a new one." : "Couldn't reach the ERP: " + e.message;
    show("setup");
  }
})();
