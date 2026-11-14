// Server-side Shopee AWB post-processing for the scheduled Telegram send.
//
//   node awb.mjs <job.json>
//
// job.json: { "output": "/abs/out.pdf", "parts": [ { "pdf": "/abs/in.pdf", "orders": [ { "sns": ["2611149ABC"], "items": [ { "sku": "SKU-1", "name": "Kaos", "variant": "Hitam,XL" } ] } ] } ] }
//
// Every part's pages are copied into one merged PDF. On each page the product names are hidden and Shopee's item
// table is redrawn as "# | SKU | Qty" (qty > 1 circled). The page's order is found by the order / reservation
// number printed on it. KEEP IN SYNC with frontend/src/lib/awb.ts + awbTable.ts (the manual print path).
import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { PDFDocument, StandardFonts, rgb } from "pdf-lib";
import * as pdfjs from "pdfjs-dist/legacy/build/pdf.mjs";

pdfjs.GlobalWorkerOptions.workerSrc = import.meta.resolve("pdfjs-dist/legacy/build/pdf.worker.mjs");
const standardFontDataUrl = fileURLToPath(import.meta.resolve("pdfjs-dist/standard_fonts/FoxitSans.pfb")).replace(/FoxitSans\.pfb$/, "");

const HEADER = /^(Nama\s*Produk|Product\s*Name)$/i;

function sanitize(font, s) {
  return [...s].map((c) => { try { font.widthOfTextAtSize(c, 1); return c; } catch { return "?"; } }).join("");
}
function safeWidth(font, c, size) {
  try { return font.widthOfTextAtSize(c, size); } catch { return size * 0.5; }
}
function wrap(text, font, size, max) {
  const out = [];
  let cur = "";
  for (const ch of [...sanitize(font, text)]) {
    if (font.widthOfTextAtSize(cur + ch, size) > max && cur) {
      const cut = Math.max(cur.lastIndexOf(" "), cur.lastIndexOf("-"));
      if (cut > 0) { out.push(cur.slice(0, cut + 1).trimEnd()); cur = cur.slice(cut + 1).trimStart() + ch; }
      else { out.push(cur); cur = ch; }
    } else cur += ch;
  }
  if (cur) out.push(cur);
  return out;
}

/** Redraw Shopee's "# | Nama Produk | SKU | Variasi | Qty" as "# | SKU | Qty". Returns false when not recognised. */
function redrawItemTable(page, items, fonts, own) {
  const name = items.find((i) => HEADER.test(i.str.trim()));
  if (!name) return false;
  const hy = name.transform[5];
  const onHeader = (re) => items.find((i) => re.test(i.str.trim()) && Math.abs(i.transform[5] - hy) < 3);
  const hash = onHeader(/^#$/), sku = onHeader(/^SKU$/i), variant = onHeader(/^(Variasi|Variation)$/i), qty = onHeader(/^(Qty|Jumlah)$/i);
  if (!hash || !sku || !variant || !qty) return false;

  const x = { name: name.transform[4] - 1, sku: sku.transform[4] - 1, variant: variant.transform[4] - 1, qty: qty.transform[4] - 1 };
  const size = name.height || name.transform[3] || 7;

  const below = items.filter((i) => i.str.trim() && i.transform[5] < hy - 1).sort((a, b) => b.transform[5] - a.transform[5]);
  const stop = below.find((i) => i.transform[4] < x.name && !/^\d+$/.test(i.str.trim()));
  const body = below.filter((i) => !stop || i.transform[5] > stop.transform[5] + 1);
  const numbers = body.filter((i) => i.transform[4] < x.name && /^\d+$/.test(i.str.trim()));
  if (!numbers.length) return false;

  const cells = numbers.map((n) => ({ y: n.transform[5], no: n.str.trim(), name: [], sku: [], vname: [], qty: [] }));
  for (const it of body) {
    if (numbers.includes(it)) continue;
    const y = it.transform[5];
    const row = [...cells].reverse().find((c) => c.y >= y - 1) ?? cells[0];
    const chars = [...it.str];
    const widths = chars.map((c) => safeWidth(fonts.regular, c, size));
    const total = widths.reduce((a, b) => a + b, 0) || 1;
    const scale = it.width ? it.width / total : 1;
    let cx = it.transform[4];
    chars.forEach((c, k) => {
      const mid = cx + (widths[k] * scale) / 2;
      cx += widths[k] * scale;
      // Product and variant names are only printed when the item has no SKU.
      const col = mid >= x.qty ? "qty" : mid >= x.variant ? "vname" : mid >= x.sku ? "sku" : mid >= x.name ? "name" : null;
      if (col) row[col].push([y, c]);
    });
  }
  const join = (chars) => {
    const lines = [];
    let last = null;
    for (const [y, c] of chars) {
      if (last === null || Math.abs(y - last) > 1) lines.push("");
      lines[lines.length - 1] += c;
      last = y;
    }
    return lines.map((l) => l.trim()).filter(Boolean).reduce((acc, l) => (acc && !acc.endsWith("-") ? `${acc} ${l}` : acc + l), "");
  };
  const rows = cells.map((c) => ({ no: c.no, sku: join(c.sku) || "-", qty: join(c.qty) || "1", name: join(c.name), vname: join(c.vname) }));

  const { width: pw } = page.getSize();
  const top = hy + size + 1.5;
  const lowest = Math.min(...body.map((i) => i.transform[5]));
  const bottom = stop ? stop.transform[5] + (stop.height || size) * 0.8 : lowest - size * 0.4;
  page.drawRectangle({ x: 1.5, y: bottom, width: pw - 3, height: top - bottom, color: rgb(1, 1, 1) });

  // Every item has a SKU → "# | SKU | Qty". Any item without one → "# | Nama Produk | Variasi | Qty" (an item that
  // does have a SKU shows it in the first column). Pesanin's own data first, else what Shopee printed. Same as awbTable.ts.
  const mine = Array.isArray(own) && own.length === rows.length && rows.length > 0;
  const has = (s) => !!s && String(s).trim() !== "" && String(s).trim() !== "-";
  const info = rows.map((r, i) => {
    const d = mine ? own[i] : null;
    return { sku: d ? (has(d.sku) ? d.sku : "") : has(r.sku) ? r.sku : "", name: ((d && d.name) || r.name || "").trim(), variant: ((d ? d.variant : r.vname) || "").trim() };
  });
  const allSku = info.every((x) => x.sku);
  const heads = allSku ? ["SKU"] : [info.some((x) => x.sku) ? "SKU / Nama Produk" : "Nama Produk", "Variasi"];
  const body2 = rows.map((r, i) => ({ no: r.no, qty: r.qty, cells: allSku ? [info[i].sku] : [info[i].sku || info[i].name || "-", info[i].sku ? "-" : info[i].variant || "-"] }));

  const left = hash.transform[4];
  const right = pw - left;
  const qtyX = right - 18;
  const colX = allSku ? [left + 14] : [left + 14, left + 14 + (qtyX - left - 14) * 0.66];
  const widthFor = (k) => (k + 1 < colX.length ? colX[k + 1] : qtyX) - colX[k] - 6;
  const ink = rgb(0, 0, 0), rule = rgb(0.6, 0.6, 0.6);
  const line = (yy) => page.drawLine({ start: { x: left, y: yy }, end: { x: right, y: yy }, thickness: 0.5, color: rule });
  const lh = size * 1.18;

  let y = hy;
  page.drawText("#", { x: left, y, size, font: fonts.bold, color: ink });
  heads.forEach((t, k) => page.drawText(t, { x: colX[k], y, size, font: fonts.bold, color: ink }));
  page.drawText("Qty", { x: qtyX, y, size, font: fonts.bold, color: ink });
  y -= size * 0.32;
  line(y);
  body2.forEach((r, i) => {
    const cols = r.cells.map((c, k) => wrap(c, fonts.bold, size, widthFor(k)));
    const n = Math.max(1, ...cols.map((l) => l.length));
    const first = y - size * 1.0;
    if (first - (n - 1) * lh < bottom) return;
    page.drawText(r.no, { x: left, y: first, size, font: fonts.regular, color: ink });
    const q = sanitize(fonts.regular, r.qty);
    page.drawText(q, { x: qtyX, y: first, size, font: fonts.bold, color: ink });
    if ((parseInt(r.qty, 10) || 1) > 1) {
      const tw = safeWidth(fonts.bold, q, size);
      page.drawEllipse({ x: qtyX + tw / 2, y: first + size * 0.33, xScale: tw / 2 + 4, yScale: size * 0.72, borderColor: ink, borderWidth: 1.1 });
    }
    cols.forEach((col, k) => col.forEach((ln, j) => page.drawText(ln, { x: colX[k], y: first - j * lh, size, font: fonts.bold, color: ink })));
    y = first - (n - 1) * lh - size * 0.32;
    if (i < body2.length - 1) line(y);
  });
  return true;
}

/** Fallback when the table isn't recognised: cover the product-name column. */
function coverNames(page, items) {
  const header = items.find((it) => /^Nama\s*Produk/i.test(it.str.trim()) || /^Product\s*Name/i.test(it.str.trim()));
  if (!header) return;
  const hy = header.transform[5];
  const sku = items.find((it) => it.str.trim() === "SKU" && Math.abs(it.transform[5] - hy) < 4);
  const x0 = header.transform[4] - 1;
  const x1 = sku ? sku.transform[4] - 2 : x0 + 180;
  for (const it of items) {
    const [, , , d, x, y] = it.transform;
    if (!it.str.trim() || x < x0 || x >= x1 || y > hy + 1) continue;
    const h = it.height || Math.abs(d) || 8;
    page.drawRectangle({ x: x - 0.5, y: y - h * 0.3, width: Math.min(it.width, x1 - x) + 1, height: h * 1.4, color: rgb(1, 1, 1) });
  }
}

async function processPart(merged, part) {
  const bytes = new Uint8Array(await readFile(part.pdf));
  const doc = await pdfjs.getDocument({ data: bytes.slice(), isEvalSupported: false, disableFontFace: true, standardFontDataUrl }).promise;
  const out = await PDFDocument.load(bytes);
  const fonts = { regular: await out.embedFont(StandardFonts.Helvetica), bold: await out.embedFont(StandardFonts.HelveticaBold) };
  const orders = part.orders ?? [];
  const keyed = orders.flatMap((o) => (o.sns ?? []).filter(Boolean).map((sn) => [String(sn).toUpperCase(), o]));

  for (let i = 0; i < doc.numPages; i++) {
    const items = (await (await doc.getPage(i + 1)).getTextContent()).items.filter((it) => "str" in it);
    const text = items.map((it) => it.str).join("").replace(/\s+/g, "").toUpperCase();
    const match = keyed.find(([sn]) => text.includes(sn))?.[1] ?? (orders.length === doc.numPages ? orders[i] : undefined);
    if (!redrawItemTable(out.getPage(i), items, fonts, match?.items)) coverNames(out.getPage(i), items);
  }
  const pages = await merged.copyPages(out, out.getPageIndices());
  pages.forEach((p) => merged.addPage(p));
}

const job = JSON.parse(await readFile(process.argv[2], "utf8"));
const merged = await PDFDocument.create();
for (const part of job.parts) await processPart(merged, part);
await writeFile(job.output, await merged.save());
console.log(JSON.stringify({ ok: true, pages: merged.getPageCount() }));
