# Knowledge Graph — Audit + Roadmap

**Last updated:** 2026-04-21 (end of session)
**Plugin version:** LuwiPress 3.1.3 + WebMCP 1.0.1
**Canlı test:** tapadum.com (128 products, 57 posts, 63 pages, 75 taxonomies)

---

## 📊 Session Özeti (2026-04-21)

Bu oturumda **4 ayrı 3.1.3 patch** shipped. Müşteri bildirimiyle başladı ("WebMCP çalışmıyor"), KG audit'e dönüştü, roadmap çıkarıldı, P0 + P1 + P2'nin tamamına yakını bitirildi.

### Yapılanlar

**Patch #1 — Knowledge Graph bug fixes**
- `kgAction` IIFE scope bug: `kgRefreshAndReopen` IIFE dışındaydı ama IIFE içindeki `updateStats/buildGraph/showDetailPanel` fonksiyonlarını çağırıyor, silent `ReferenceError` atıyordu → `window.lpKg` namespace ile expose
- `fetchGraph()` admin sayfasında `fresh=1` göndermiyordu → 5 dk transient cache'den eski veri dönüyordu
- JS `sections=` (çoğul) yolluyordu, backend `section=` (tekil) bekliyordu → parametre yok sayılıyordu, 20 section'ın hepsi yükleniyordu. Backend alias eklendi
- AI enrich/FAQ async meta yazıyor, `save_post` her zaman trigger olmuyor → `updated_post_meta` hook'u ile `_luwipress_*` + SEO meta key'lerinde cache invalidate

**Patch #2 — WebMCP + branding cleanup**
- Settings sayfası `luwipress_webmcp_enabled` option'ını default `0` ile okuyordu — companion yüklü + enabled'ken "Disabled" rozeti gösteriyordu → companion-aware default
- **32 dosyada** `n8nPress` / `n8n workflow` branding sızıntısı temizlendi:
  - MCP tool descriptions (7 sızıntı)
  - webmcp-client.js header, FEATURES.md, LICENSE, readme.txt
  - 20+ docblock + inline comment
  - Internal rename: `$n8n_webhook_url` → `$webhook_url`, `check_n8n_token()` → `check_api_token()`, `n8n_forwarded` response key kaldırıldı (dead code)

**Patch #3 — P0 (Cache + Search + Clickable cues)**
- `fetchGraph(forceFresh)` parametreli; default `false` (cache hit), Refresh butonu explicit `true` gönderir
- Header'a typeahead **Search** — products + posts + categories üzerinde. Kısayollar: `/` focus, `↑↓` navigate, `Enter` select, `Esc` close
- Seçilen node'a 2× zoom + pulse animation + detail panel otomatik açılır
- **Plugin Health** stat card eklendi (readiness_score) + click → detail panel
- Design Health + Plugin Health card'lara "View details →" hover cue
- Header'a **Cache badge** — `cached` (yeşil) veya `fresh (263ms)` (mor)
- Dead console.warn logs temizlendi

**Patch #4 — P1 (Pages + Order Analytics + Category Batch)**
- **Pages view** (3. tab): 63 sayfa, parent-child `child_of` edges, homepage/shop/blog/top-level/child role'lere göre renk + boyut
- **Page detail panel**: role badge, template, content_length, children count, recommendations
- **Order Analytics card**: 30-day revenue (currency-aware: €/$/£/₺) + click → Revenue panel:
  - Today / 7-day / 30-day revenue + AOV
  - **12-month SVG sparkline** (area chart)
  - Customer retention health bar (repeat rate %)
  - Top 5 sellers (revenue + quantity)
  - Inventory status (out-of-stock, backorder, no-price, on-sale)
  - Payment methods (% dağılımı)
  - Refund stats (last 90d)
- **Category batch actions**: "Enrich all in category" + "Translate to {LANG}" butonları; `collectProductIdsByCategory` helper ile cache'den ID toplama
- Backend `/translation/batch` endpoint'ine opsiyonel **`post_ids` whitelist** param eklendi

**Patch #5 — P1 #9 + #8 (Taxonomy Heatmap + Force Sim)**
- **Taxonomy Coverage card** + click → Heatmap panel:
  - Coverage matrix: taxonomy type × lang, 4-level heat coloring (≥95% green, 70-94% orange, 40-69% dark orange, <40% red)
  - Legend + hover tooltips (`N/M translated, K missing`)
  - Missing terms listesi her dil için chip'ler (ilk 20 + "+N more")
  - "Translate all" butonu — paralel `translation/taxonomy` POST'ları her tax type için
- Force simulation dinamik parametreler (node count'a göre alphaDecay 0.025→0.06, collision tuning, charge strength)

**Patch #6 — P2 (Export + Presets + A11y + Cross-sell edge colors)**
- **Preset dropdown** — 6 filter: All / Needs SEO / Not enriched / Thin content / Translation backlog / High opportunity
  - `applyPreset()` `buildGraph`'a entegre, view switch ile birleşik çalışır
  - Active preset rozet olarak butonda görünür
- **Export dropdown** — 4 format:
  - CSV — Opportunity list (opportunity_score desc sıralı, UTF-8 BOM)
  - CSV — Missing SEO (Edit URL kolonu dahil)
  - JSON — Raw graph
  - PNG — SVG → Canvas viewport snapshot
- **Keyboard shortcuts**: `/` search, `r` refresh, `1/2/3` view switch, `Esc` close panel, `?` help dialog
- **A11y pass**: SVG `role="img"` + `aria-label`, node'lara `tabindex="0"` + `role="button"` + dinamik `aria-label`
- Cross-sell/upsell edge color map (Tapadum'da veri yok ama hazır)

### Canlı Ölçümler (2026-04-21 sonu)

| Metrik | Değer |
|---|---|
| Endpoint latency | 263ms cache miss / <10ms cache hit (1.5s full fresh) |
| Payload | 275 KB (tüm 20 section) |
| Node types | 14 backend / 5 UI rendered (products, posts, pages, categories, languages) |
| Detail panels | 6 (product, post, page, category, language, design audit, plugin health, revenue, taxonomy heatmap) |
| Stat cards | 8 (+3 clickable: design health, plugin health, revenue, taxonomy) |
| Keyboard shortcuts | 6 + help |
| Export formats | 4 |
| Preset filters | 6 |

### Tapadum özeti

- Products: 128, SEO coverage 37.5%, Enrichment 20.3%, AEO FAQ 25.8%
- Preset counts: Needs SEO (80), Not enriched (102), Thin content (3), High opportunity (100)
- Translation: FR/IT/ES tümü %100 (ürün), taxonomy 89%
- Pages: 63 (shop + homepage detect edildi)
- Revenue: €5,292 / 30 gün, €107,874 lifetime, 247 order, AOV €436
- Top opportunity: #2789 "9 Bridge Special Santur" (score 54)
- Plugin readiness: 79.2%
- Design health: 84%

---

## ✅ Completed — Roadmap Progress

### P0 — ✅ Tamamlandı
- [x] **#1** Cache default (fresh=0, Refresh=1)
- [x] **#2** Search input + typeahead + keyboard
- [x] **#3** Design Audit + Plugin Health clickable cues

### P1 — ✅ Tamamlandı
- [x] **#4** Pages view + hierarchy + detail panel
- [x] **#5** Order analytics card + sparkline + detail panel
- [x] **#6** Store intelligence (Revenue panel'ine entegre edildi: top sellers + stock alerts)
- [x] **#7** Category detail panel batch action buttons
- [x] **#8** Force simulation performance (dynamic tuning)
- [x] **#9** Taxonomy coverage heatmap

### P2 — Kısmen Tamamlandı
- [ ] **#10** WebGL rendering for 1000+ products — **Tapadum ölçeğinde gerek yok** (128 ürün), 1000+ store gerekince yapılır
- [x] **#11** Export actions (CSV × 2, JSON, PNG)
- [ ] **#12** Real-time SSE updates — **altyapı değişikliği**, ayrı ürün kararı
- [ ] **#13** Customer segments view — **bekliyor** (veri hazır, UI yapılmadı)
- [x] **#14** Cross-sell/upsell edge rendering (Tapadum'da veri yok, frontend hazır)
- [ ] **#15** Elementor page audit drill-down — **bekliyor** (panel var, drill-down yok)
- [x] **#16** Saved views / presets
- [x] **#17** Keyboard shortcuts + accessibility pass

---

## 🔜 Kalan Maddeler

### P2 — Bekliyor

**#12 Real-time SSE updates**
- `/knowledge-graph/stream` endpoint — SSE veya long-poll
- `save_post` hook invalidasyonu client'a push
- "🟢 Live" badge + partial graph diff
- **Karar:** Tek kullanıcılı admin için overkill; multi-user team için değerli. Müşteri talebi gelmeden yapılmayabilir.

**#13 Customer segments view**
- 4. view tab: "Customers"
- VIP / at-risk / dormant node cluster'ları
- Segment detail + "Send win-back email" CTA (email_proxy üzerinden)
- CRM bridge read-only kuralı korunur
- **Karar:** 8 segment veri hazır; UI sprint'i 2-3 saat. Bir sonraki session için güzel iş.

**#15 Elementor page audit drill-down**
- Design audit panel → page click → Elementor detail panel
- Widget counts, responsive issues, Kit CSS coverage breakdown
- "Apply CSS fix" buttons — luwipress-webmcp elementor tools'u kullanır
- **Karar:** Tapadum gibi Elementor kullanan store'lar için yüksek değer. Medium-effort.

### P3 — Yeni Ortaya Çıkanlar

**#18 Enrichment batch monitoring**
- Category'den "Enrich all" tetiklenince current batch status'unu göster
- Progress bar + job_id tracking
- `/product/enrich-batch/status` endpoint'i zaten var

**#19 CSV upload → update meta**
- Export CSV'yi edit edip upload ile SEO meta batch update
- Reverse flow: kullanıcı offline çalışsın

**#20 Graph layout persistence**
- User'ın node'ları sürüklediği pozisyonlar `localStorage`'a kaydedilsin
- `?layout=custom` ile hatırlanan düzen

### P-Out-of-Scope

**WebGL (P2 #10)** ve **SSE (P2 #12)** — bu store ölçeği için overkill, talep gelince yapılır.

---

## 🧹 Refactor / Maintenance Debts

1. **1,525 satırlık tek PHP dosyası** (`knowledge-graph-page.php`) — JS'i `assets/js/knowledge-graph.js`'e taşımak gerek. `wp_localize_script` config geçirir. Cache + debug için iyi olur. **~2 saat iş**.

2. **CSS code splitting** — 143 `.kg-*` rule (250+ satır) hâlâ `admin.css` içinde (4500+ satır). Ayrı `knowledge-graph.css` + conditional enqueue. Minor perf, kod organizasyonu ana fayda.

3. ~~**`MODE_N8N` + `forward_to_n8n()` dead stubs**~~ — **✅ Shipped in 3.1.8.** Tamamen silindi, 5 dead if-branch + `get_mode()` + `MODE_LOCAL` + `luwipress_processing_mode` option + `luwipress_seo_webhook_url` exposure da gitti.

4. **Design audit Elementor-specific** — Elementor olmayan store'larda `"0%"` yerine `"N/A"` göstermeli (graceful degrade).

5. **Backend `$all_sections` documentation** — register_endpoints args listesinde `'design_audit'` tanımsız (handler işliyor). Yalnız doc inconsistency.

---

## 📁 Files Touched This Session

### Core plugin (luwipress 3.1.3)
- `luwipress.php` — n8n migration guard silindi (dead code)
- `LICENSE` — n8nPress → LuwiPress
- `readme.txt` — Installation, FAQ, Requirements, Contact sections rewrite (n8n dependency discourse kaldırıldı)
- `admin/knowledge-graph-page.php` — **major rewrite** — 1225 → 1525 satır. Tüm P0+P1+P2 eklendi
- `admin/settings-page.php` — WebMCP rozet default düzeltildi
- `assets/css/admin.css` — ~250 satır yeni `.kg-*` + dropdown + heatmap + sparkline + search + cache badge
- `includes/class-luwipress-knowledge-graph.php` — `maybe_invalidate_on_meta` hook eklendi, sections alias
- `includes/class-luwipress-translation.php` — `/translation/batch` `post_ids` whitelist + n8n comment cleanup
- `includes/class-luwipress-ai-engine.php` — n8n docblocks cleanup, `async_forwarded` rename
- `includes/class-luwipress-ai-content.php` — `$n8n_webhook_url` → `$webhook_url`
- `includes/class-luwipress-internal-linker.php` — `check_n8n_token` → `check_api_token`
- `includes/class-luwipress-review-analytics.php` — aynı rename
- Multiple other comment-only files (plugin-detector, hmac, email-proxy, api, prompts, permission, site-config, token-tracker, content-scheduler, aeo)

### WebMCP companion (1.0.1 — version unchanged)
- `includes/class-luwipress-webmcp.php` — 7 tool description + docblock cleanup
- `assets/js/webmcp-client.js` — header rewrite
- `docs/FEATURES.md` — n8n references removed
- `phpstan-baseline.neon` — property rename reflected

---

## 🗺️ Önerilen Sonraki Session Planı

**Öncelik sırası:**

1. **Tapadum enrichment prompt testi** (next-session-prompt'tan — önceden roadmap dışıydı)
   - 8-blok Tapadum şablonunu `/enrich/settings`'e yazmak
   - Bir ürün üzerinde `/product/enrich` çağırıp çıktıyı review etmek
   - Gerekli prompt iterasyonları

2. **P2 #13 Customer segments view** (UI sprint, veri hazır)

3. **P2 #15 Elementor page audit drill-down** (Tapadum için yüksek değer)

4. **Refactor debt:** KG JS'i ayrı dosyaya split + CSS split

5. **Bekleyen roadmap maddeleri** session sonunda müşteri geri bildirimine göre önceliklendirilir.
