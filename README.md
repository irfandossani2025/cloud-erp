# Cloud ERP

Corporate gifting workspace for Oman. **Developed by Irfan Dossani.**

The current deliverable is a local web application editable in Visual Studio Code. Its source is stored in this GitHub repository. Deployment to a company subdomain is a separate next phase.

This root application (React, Vinext/Vite, Cloudflare Workers) is guarded to `localhost` only and has no staff login. The [`laravel/`](laravel/README.md) directory holds a second edition of the same product on Laravel, with real staff authentication and administrator/sales-agent roles — see [laravel/README.md](laravel/README.md) for its setup.

## Local setup

Requires Node.js 22.13 or later. Python 3 is used only by the optional API integration check.

```sh
npm run install:ci
cp .dev.vars.example .dev.vars
npm run build
node --import ./scripts/sites-env.mjs ./node_modules/wrangler/bin/wrangler.js d1 execute DB --local --config dist/server/wrangler.json --persist-to .wrangler/state --file drizzle/0000_loving_pride.sql
npm run dev -- --hostname 127.0.0.1
```

The migration command above is for a **new local database only**. Do not replay it on an existing database. The original development checkout already has its database and supplier catalogue populated. In that checkout, start with `npm run dev -- --hostname 127.0.0.1`.

Open the local URL printed by the server (normally `http://127.0.0.1:5173`). VS Code tasks are included for running, building, and checking the project. The application uses React, TypeScript, Vinext/Vite, local D1 (SQLite), and local R2 image storage. Local data lives in `.wrangler/state`; back it up when moving the development workspace. Stop the development server before copying database files.

## What works

- Synchronise Luxury Trading's product catalogue, descriptions, images, AED costs and supplier availability.
- Warehouse quantities and OMR selling prices remain independent of supplier synchronisation.
- Search every imported or manually added product when preparing quotations. Results are paginated, not truncated.
- Create a missing product directly from a quotation and immediately add it to the draft.
- Create local sales-agent profiles; organise quotations and generated mockups by agent.
- Save, reopen, edit, review, accept or decline quotations. Optimistic revision checks prevent silently overwriting a newer edit.
- Calculate OMR values to three decimal places using integer baisa. Save an exchange-rate and line-item snapshot on each quotation.
- Print a customer quotation or save it as PDF from the browser's print dialog. Internal supplier costs and warehouse quantities are omitted.
- Prepare AI product/quantity suggestions from an agent's request, then explicitly add them to an editable draft. Existing selling prices come from the catalogue; missing prices are left for the agent to set.
- Select an inventory product, upload a logo and optionally an alternate product photograph, and request an AI branded mockup. Successful images are persisted and downloadable per agent.

See [currency details](docs/currency.md) for the initial indicative exchange rate and its sources.

## Connections

Set server-side values in `.dev.vars` and restart the local server:

- `LUXURY_API_USERNAME` and `LUXURY_API_PASSWORD`: supplier credentials. Obtain these from the supplier documentation; never commit the documentation's credentials.
- `GEMINI_API_KEY`: required for live quotation suggestions and image generation, using the Google Gemini API. Create one at [Google AI Studio](https://aistudio.google.com/apikey).
- `GEMINI_TEXT_MODEL`: defaults to `gemini-2.5-flash`.
- `GEMINI_IMAGE_MODEL`: defaults to `gemini-2.5-flash-image`.

Both AI models are configurable. The quotation integration uses Gemini's [`generateContent`](https://ai.google.dev/gemini-api/docs/structured-output) with a response schema for structured output. The studio uses the same endpoint with the product and logo supplied as inline reference images to Gemini's image generation model. API access and billing must be enabled on the chosen account. No live AI requests are made until a key is configured and a user requests generation.

`.env.example` mirrors the configuration for reference; the local Worker reads `.dev.vars`. Neither the real environment file nor local databases, customer data, logos, or generated images are tracked in Git. Never use a `NEXT_PUBLIC_` or `VITE_` prefix for secrets.

Supplier documentation describes a read-only catalogue endpoint. It does not document supplier order placement or inventory reservations. A sync validates the complete response before a transactional local upsert; errors leave the existing catalogue intact. Missing supplier quantities remain unknown, not zero. Availability is a dated snapshot, not a stock guarantee.

## Checks

```sh
npm run typecheck
npm run lint
npm test
python3 scripts/check-local.py
```

The integration script requires the development server, local migrations, and at least one product. It creates temporary records with unique IDs and deletes only those records afterwards. It checks product validation, quotation arithmetic and persistence, exchange-rate retention, stale revisions, reviewed-draft protection, request origin/host checks, and missing AI configuration.

## Current boundaries

This is the first local sales/inventory implementation, not a complete accounting ERP. Purchasing, stock movement ledgers, fulfilment, invoicing, tax calculation, and payment reconciliation are not implemented. Status changes do not reserve or deduct stock. The printed amount is a subtotal; tax, delivery and branding charges are not automatically added.

Local agent profiles are **not authenticated staff accounts**. API routes reject non-loopback hostnames and cross-origin mutations. Before public deployment, implement real staff authentication, membership/roles, authorisation on every record and media request, and production database/storage/secrets/backups. Do not bypass the local guard to publish this version.

AI suggestions and mockups require human review. Logo details may be altered by image generation, so a mockup is not final production artwork. No quotation is emailed automatically. Mockup generation is a bounded synchronous request in this local version; durable background jobs and account quotas should be added for hosted use.

A progressive WebMCP catalogue search tool is registered only in browsers that support it. The optional WebMCP tool and visual browser interactions have not been browser-tested in this build.

The supplied Mais logo is used in the app header, browser icon, and printable quotations. Its original PNG is preserved in `public/mais-logo.png`.
