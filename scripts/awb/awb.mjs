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
function redrawItemTable(page, items, fonts, own, settings) {
  const name = items.find((i) => HEADER.test(i.str.trim()));
  if (!name) return false;
  const hy = name.transform[5];
  const onHeader = (re) => items.find((i) => re.test(i.str.trim()) && Math.abs(i.transform[5] - hy) < 3);
  const hash = onHeader(/^#$/), sku = onHeader(/^SKU$/i), variant = onHeader(/^(Variasi|Variation)$/i), qty = onHeader(/^(Qty|Jumlah)$/i);
  if (!hash || !qty) return false;

  const x = {
    name: name.transform[4] - 1,
    sku: sku ? sku.transform[4] - 1 : null,
    variant: variant ? variant.transform[4] - 1 : null,
    qty: qty.transform[4] - 1,
  };
  const customSize = settings?.sku_font_size ? Number(settings.sku_font_size) : undefined;
  const size = (customSize && customSize >= 5 && customSize <= 16) ? customSize : (name.height || name.transform[3] || 7);
  const headerSize = Math.min(size, 8);

  const below = items.filter((i) => i.str.trim() && i.transform[5] < hy - 1).sort((a, b) => b.transform[5] - a.transform[5]);
  const stop = below.find((i) =>
    /^(Pesan|Komentar|Catatan|Note)/i.test(i.str.trim()) ||
    (i.transform[4] < x.name && !/^\d+$/.test(i.str.trim()) && i.transform[5] < hy - size * 2.5)
  );
  const body = below.filter((i) => !stop || i.transform[5] > stop.transform[5] + 1);
  const numericCandidates = body.filter((i) => i.transform[4] < x.name && /^\d+$/.test(i.str.trim()));
  const numbers = own?.length ? numericCandidates.slice(0, own.length) : numericCandidates;
  if (!numbers.length && (!own || !own.length)) return false;

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
      const col = mid >= x.qty
        ? "qty"
        : (x.variant !== null && mid >= x.variant)
        ? "vname"
        : (x.sku !== null && mid >= x.sku)
        ? "sku"
        : mid >= x.name
        ? "name"
        : null;
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
  const top = hy + headerSize + 3;
  const lowest = body.length ? Math.min(...body.map((i) => i.transform[5])) : hy - size * 3;
  const bottom = stop ? stop.transform[5] + (stop.height || size) * 0.8 : lowest - size * 0.4;
  page.drawRectangle({ x: 1.5, y: bottom, width: pw - 3, height: top - bottom, color: rgb(1, 1, 1) });

  // With Pesanin's own order items, show all items from the order.
  const mine = Array.isArray(own) && own.length > 0;
  const has = (s) => !!s && String(s).trim() !== "" && String(s).trim() !== "-";
  const info = mine
    ? own.map((d, i) => {
        const sku = has(d.variant) ? d.variant : has(d.sku) ? d.sku : "";
        return {
          no: String(i + 1),
          sku,
          name: ((d && d.name) || "").trim(),
          variant: ((d && d.variant) || "").trim(),
          qty: String((d && (d.qty || d.quantity)) || (rows[i]?.qty || "1")),
        };
      })
    : rows.map((r, i) => {
        const sku = has(r.sku) ? r.sku : "";
        return {
          no: r.no || String(i + 1),
          sku,
          name: (r.name || "").trim(),
          variant: (r.vname || "").trim(),
          qty: r.qty || "1",
        };
      });

  const allSku = info.every((x) => x.sku);
  const showVariant = (variant !== undefined) || info.some((x) => has(x.variant));
  const heads = allSku
    ? ["SKU", "Qty"]
    : showVariant
    ? [info.some((x) => x.sku) ? "SKU / Nama Produk" : "Nama Produk", "Variasi", "Qty"]
    : [info.some((x) => x.sku) ? "SKU / Nama Produk" : "Nama Produk", "Qty"];

  const body2 = info.map((it) => {
    const cells = allSku
      ? [it.sku]
      : showVariant
      ? [it.sku || it.name || "-", it.variant || "-"]
      : [it.sku || it.name || "-"];
    return { no: it.no, qty: it.qty, cells };
  });

  const left = hash.transform[4];
  const right = pw - left;
  const qtyX = right - 18;
  const hasMultipleCols = heads.length > 2;
  const colX = hasMultipleCols
    ? [left + 14, left + 14 + (qtyX - left - 14) * 0.66]
    : [left + 14];
  const headX = [...colX, qtyX];
  const widthFor = (k) => (k + 1 < colX.length ? colX[k + 1] : qtyX) - colX[k] - 6;
  const ink = rgb(0, 0, 0);
  const rule = rgb(0.1, 0.1, 0.1);
  const stroke = 0.6;
  const showBorders = settings?.show_table_border !== false;

  const tableLeft = left - 3;
  const tableRight = right + 2;
  const line = (yy) => page.drawLine({ start: { x: tableLeft, y: yy }, end: { x: tableRight, y: yy }, thickness: stroke, color: rule });
  const vLine = (vx, y1, y2) => page.drawLine({ start: { x: vx, y: y1 }, end: { x: vx, y: y2 }, thickness: stroke, color: rule });

  const lh = size * 1.18;
  const topY = hy + headerSize + 2.5;
  const headerBottomY = hy - 2.5;

  let y = hy;
  page.drawText("#", { x: left, y, size: headerSize, font: fonts.bold, color: ink });
  heads.forEach((t, k) => page.drawText(t, { x: headX[k], y, size: headerSize, font: fonts.bold, color: ink }));

  if (showBorders) {
    line(topY);
    line(headerBottomY);
  } else {
    page.drawLine({ start: { x: left, y: headerBottomY }, end: { x: right, y: headerBottomY }, thickness: 0.5, color: rule });
  }

  y = headerBottomY;

  body2.forEach((r, i) => {
    const cols = r.cells.map((c, k) => wrap(c, fonts.bold, size, widthFor(k)));
    const n = Math.max(1, ...cols.map((l) => l.length));
    const first = y - size - 2.5;
    if (first - (n - 1) * lh < bottom) return;
    page.drawText(r.no, { x: left, y: first, size: headerSize, font: fonts.regular, color: ink });
    const q = sanitize(fonts.regular, r.qty);
    page.drawText(q, { x: qtyX, y: first, size: Math.max(size, 7), font: fonts.bold, color: ink });
    if ((parseInt(r.qty, 10) || 1) > 1) {
      const tw = safeWidth(fonts.bold, q, size);
      page.drawEllipse({ x: qtyX + tw / 2, y: first + size * 0.33, xScale: tw / 2 + 4, yScale: size * 0.72, borderColor: ink, borderWidth: 1.1 });
    }
    cols.forEach((col, k) => col.forEach((ln, j) => page.drawText(ln, { x: colX[k], y: first - j * lh, size, font: fonts.bold, color: ink })));
    y = first - (n - 1) * lh - 3;
    if (showBorders) {
      line(y);
    } else if (i < body2.length - 1) {
      page.drawLine({ start: { x: left, y }, end: { x: right, y }, thickness: 0.5, color: rule });
    }
  });

  const bottomY = y;

  if (showBorders) {
    vLine(tableLeft, topY, bottomY);
    vLine(colX[0] - 4, topY, bottomY);
    if (!allSku && colX.length > 1) {
      vLine(colX[1] - 4, topY, bottomY);
    }
    vLine(qtyX - 4, topY, bottomY);
    vLine(tableRight, topY, bottomY);
  }

  return true;
}

/** Fallback when the table isn't recognised: cover the product-name column. */
function coverNames(page, items) {
  const header = items.find((it) => /^Nama\s*Produk/i.test(it.str.trim()) || /^Product\s*Name/i.test(it.str.trim()));
  if (!header) return;
  const hy = header.transform[5];
  const sku = items.find((it) => it.str.trim() === "SKU" && Math.abs(it.transform[5] - hy) < 4);
  if (!sku) return; // Never erase product names when there is no SKU on the label
  const x0 = header.transform[4] - 1;
  const x1 = sku.transform[4] - 2;
  for (const it of items) {
    const [, , , d, x, y] = it.transform;
    if (!it.str.trim() || x < x0 || x >= x1 || y > hy + 1) continue;
    const h = it.height || Math.abs(d) || 8;
    page.drawRectangle({ x: x - 0.5, y: y - h * 0.3, width: Math.min(it.width, x1 - x) + 1, height: h * 1.4, color: rgb(1, 1, 1) });
  }
}

async function processPart(merged, part, printSettings = {}) {
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
    if (!redrawItemTable(out.getPage(i), items, fonts, match?.items, printSettings)) coverNames(out.getPage(i), items);
  }

  if (printSettings.paper_size === "78x100") {
    const targetW = 221.10;
    const targetH = 283.46;
    for (const srcPage of out.getPages()) {
      const embedded = await merged.embedPage(srcPage);
      const page = merged.addPage([targetW, targetH]);
      const origW = srcPage.getWidth();
      const origH = srcPage.getHeight();
      const scale = Math.min(targetW / origW, targetH / origH);
      const w = origW * scale;
      const h = origH * scale;
      page.drawPage(embedded, {
        x: (targetW - w) / 2,
        y: (targetH - h) / 2,
        width: w,
        height: h,
      });
    }
  } else if (printSettings.paper_size === "100x100") {
    const targetW = 283.46;
    const targetH = 283.46;
    for (const srcPage of out.getPages()) {
      const embedded = await merged.embedPage(srcPage);
      const page = merged.addPage([targetW, targetH]);
      const origW = srcPage.getWidth();
      const origH = srcPage.getHeight();
      const scale = Math.min(targetW / origW, targetH / origH);
      const w = origW * scale;
      const h = origH * scale;
      page.drawPage(embedded, {
        x: (targetW - w) / 2,
        y: (targetH - h) / 2,
        width: w,
        height: h,
      });
    }
  } else if (printSettings.paper_size === "a4") {
    const targetW = 595.28;
    const targetH = 841.89;
    for (const srcPage of out.getPages()) {
      const embedded = await merged.embedPage(srcPage);
      const page = merged.addPage([targetW, targetH]);
      const origW = srcPage.getWidth();
      const origH = srcPage.getHeight();
      page.drawPage(embedded, {
        x: 42,
        y: targetH - origH - 42,
        width: origW,
        height: origH,
      });
    }
  } else {
    const pages = await merged.copyPages(out, out.getPageIndices());
    pages.forEach((p) => merged.addPage(p));
  }
}

const job = JSON.parse(await readFile(process.argv[2], "utf8"));
const printSettings = job.print_settings || {};
const merged = await PDFDocument.create();
for (const part of job.parts) await processPart(merged, part, printSettings);
await writeFile(job.output, await merged.save());
console.log(JSON.stringify({ ok: true, pages: merged.getPageCount() }));
