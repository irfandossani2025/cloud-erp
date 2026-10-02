// Reads what is on the page the user is looking at, only when they open the
// extension. Nothing is crawled and nothing is sent until they press Send.
function extract(doc, loc) {
  const clean = (s) => (s || "").replace(/\s+/g, " ").trim();
  const meta = (n) =>
    clean(doc.querySelector(`meta[property="${n}"],meta[name="${n}"]`)?.getAttribute("content"));
  const host = (loc.hostname || "").replace(/^www\./, "");
  const out = {
    name: "", title: "", company: "", email: "", phone: "", website: "",
    linkedinUrl: "", location: "", sourceUrl: loc.href, notes: "",
  };

  const isLinkedIn = /(^|\.)linkedin\.com$/.test(host);
  const social = /(^|\.)(linkedin|facebook|instagram|twitter|x|google|bing|youtube|tiktok)\.(com|co\.uk)$/.test(host);
  let pageTitle = clean(doc.title).replace(/^\(\d+\)\s*/, "");

  // JSON-LD (many company sites describe themselves here).
  const nodes = [];
  doc.querySelectorAll('script[type="application/ld+json"]').forEach((s) => {
    try {
      const j = JSON.parse(s.textContent || "null");
      const walk = (n) => {
        if (Array.isArray(n)) return n.forEach(walk);
        if (n && typeof n === "object") {
          nodes.push(n);
          if (n["@graph"]) walk(n["@graph"]);
        }
      };
      walk(j);
    } catch (e) { /* ignore malformed JSON-LD */ }
  });
  const typeOf = (n) => [].concat(n["@type"] || []).join(" ");
  const person = nodes.find((n) => /Person/.test(typeOf(n)));
  const org = nodes.find((n) => /Organization|LocalBusiness|Corporation|Store/.test(typeOf(n)));
  if (!isLinkedIn) {
    if (person) {
      out.name = clean(person.name);
      out.title = clean(person.jobTitle);
      out.email = clean(person.email).replace(/^mailto:/i, "");
      out.phone = clean(person.telephone);
      out.company = clean(person.worksFor && person.worksFor.name);
    }
    if (org) {
      out.company = out.company || clean(org.name);
      out.phone = out.phone || clean(org.telephone);
      out.email = out.email || clean(org.email).replace(/^mailto:/i, "");
      const a = org.address;
      if (a && typeof a === "object") {
        out.location = clean([a.addressLocality, a.addressCountry && (a.addressCountry.name || a.addressCountry)].filter(Boolean).join(", "));
      }
    }
  }

  if (isLinkedIn && /^\/in\//.test(loc.pathname)) {
    out.linkedinUrl = loc.origin + loc.pathname;
    const t = pageTitle.replace(/\s*[|·-]\s*LinkedIn\s*$/i, "");
    const parts = t.split(/\s+-\s+/);
    out.name = clean(parts[0]);
    out.title = clean(parts[1] || "");
    out.company = clean(parts[2] || "");
    const at = out.title.match(/^(.*?)\s+(?:at|@)\s+(.+)$/i);
    if (at && !out.company) {
      out.title = clean(at[1]);
      out.company = clean(at[2]);
    }
    if (!out.name) out.name = clean(doc.querySelector("h1")?.textContent);
  } else if (isLinkedIn && /^\/company\//.test(loc.pathname)) {
    out.linkedinUrl = loc.origin + loc.pathname;
    out.company = clean(pageTitle.replace(/\s*[|·-]\s*LinkedIn\s*$/i, "").replace(/\s*[:|·-]\s*(Overview|About)\s*$/i, ""));
  } else if (!social) {
    out.website = loc.origin;
    out.company =
      out.company ||
      meta("og:site_name") ||
      clean(pageTitle.split(/\s+[|–—-]\s+/).pop() || "") ||
      host;
    if (/^\s*$/.test(out.company) || out.company.length > 80) out.company = host;
  }

  if (!out.email) {
    const mail = doc.querySelector('a[href^="mailto:"]');
    if (mail) {
      out.email = decodeURIComponent((mail.getAttribute("href") || "").slice(7).split("?")[0]).trim();
    } else if (!social) {
      const m = (doc.body?.textContent || "").match(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g) || [];
      out.email = m.find((e) => !/\.(png|jpe?g|gif|svg|webp)$/i.test(e) && !/^(noreply|no-reply|donotreply)@/i.test(e)) || "";
    }
  }
  if (!out.phone) {
    const tel = doc.querySelector('a[href^="tel:"]');
    if (tel) out.phone = decodeURIComponent((tel.getAttribute("href") || "").slice(4)).trim();
  }
  out.email = out.email.toLowerCase();
  return out;
}
