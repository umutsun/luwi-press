# Luwi Widgets — Elementor Widget Strategy

> Luwi Elementor theme'in Elementor panel'inde "Luwi Widgets" kategorisi altinda
> gorunecek custom widget'lar. Her widget tema ile standalone calisir,
> LuwiPress plugin aktifse AI superpowers kazanir.

## Widget Hierarchy: 3 Tier

### Tier 1: Theme-Only (LuwiPress gerekmez)
Tema ile gelen, herhangi bir plugin'e bagimli olmayan widget'lar.
Temanin degerini tek basina artiran, satis noktasi olan widget'lar.

### Tier 2: LuwiPress-Enhanced (Plugin aktifse zenginlesir)
Tek basina calisan ama LuwiPress varsa AI ozellikleri acilan widget'lar.
Cross-marketing'in kalbi — "LuwiPress'i kurun, bu widget canlansin."

### Tier 3: LuwiPress-Required (Plugin gerekli)
Sadece LuwiPress aktifken gorunen, plugin verisi gerektiren widget'lar.
Plugin satis motivasyonu.

---

## Tier 1: Theme-Only Widgets (8 widget)

### 1. Luwi Product Card (Advanced)
- **Standalone**: Gelismis urun karti — hover efektleri, badge'ler, quick view
- **LuwiPress+**: AI ile optimize edilmis kisa aciklama gosterir
- **Neden**: WooCommerce default urun karti cok basit, bu premium his katar

### 2. Luwi Hero Slider
- **Standalone**: Full-width hero section + parallax + video background + overlay
- **LuwiPress+**: AI ile dinamik CTA text onerileri
- **Neden**: Her e-ticaret sitesinin ihtiyaci var, tema satisi icin kritik

### 3. Luwi Category Showcase
- **Standalone**: Gorsel kategori kartlari grid'i (hover overlay, icon, count)
- **LuwiPress+**: Knowledge Graph'tan en yuksek firsatli kategorileri one cikarir
- **Neden**: Homepage'in temel yapitasi, rakip temalarda var

### 4. Luwi Testimonials
- **Standalone**: Musteri yorum carousel/grid (yildiz, avatar, isim)
- **LuwiPress+**: Review Analytics'ten sentiment highlight + AI ozet
- **Neden**: Trust building, her e-ticaret sitesi icin onemli

### 5. Luwi Trust Badges
- **Standalone**: Ikon + text trust gostergesi satiri (Free Shipping, Warranty, vb.)
- **Neden**: Basit ama etkili, her urun sayfasinda lazim

### 6. Luwi Before/After
- **Standalone**: Slider ile once/sonra karsilastirma (urun gorseli icin)
- **Neden**: Muzik enstrumani restorasyon, custom build gosterimi icin ideal

### 7. Luwi Color Mode Toggle
- **Standalone**: Light/dark mode degistirme butonu (header'a eklenir)
- **Neden**: Temanin fark yaratan ozelligi, widget olarak her yere eklenebilir

### 8. Luwi Countdown Timer
- **Standalone**: Kampanya geri sayim sayaci (sale, product launch, event)
- **Neden**: Urgency/FOMO, e-ticaret icin etkili

---

## Tier 2: LuwiPress-Enhanced Widgets (6 widget)

### 9. Luwi Smart Search
- **Standalone**: Canli arama input'u (WooCommerce urun arama)
- **LuwiPress+**: BM25 indeks + AI-powered arama sonuclari, NLP anlama
- **Cross-sell**: "LuwiPress ile AI-powered arama aktif edin"
- **Maps to**: Customer Chat BM25 engine, product index

### 10. Luwi FAQ Accordion
- **Standalone**: Standart accordion/FAQ widget (manuel icerik)
- **LuwiPress+**: AEO engine'den otomatik FAQ cekmek, FAQPage schema inject
- **Cross-sell**: "LuwiPress ile AI-generated FAQ'lari otomatik gosterin"
- **Maps to**: AEO module (FAQ Schema, HowTo Schema)

### 11. Luwi Product Comparison
- **Standalone**: 2-4 urun yan yana karsilastirma tablosu
- **LuwiPress+**: AI ile ozellik farklari highlight, akilli oneri
- **Cross-sell**: "LuwiPress AI ile otomatik urun karsilastirma"
- **Maps to**: AI Content, Knowledge Graph product data

### 12. Luwi Related Products (Smart)
- **Standalone**: WooCommerce related products (tag/category bazli)
- **LuwiPress+**: AI ile akilli oneri (satis verisi + musteri segmenti bazli)
- **Cross-sell**: "LuwiPress CRM ile kisisellestirilmis oneriler"
- **Maps to**: CRM Bridge segments, Knowledge Graph

### 13. Luwi Newsletter
- **Standalone**: Email toplama formu (MailChimp/FluentCRM connect)
- **LuwiPress+**: Segment bazli dinamik mesaj, AI ile konu onerileri
- **Cross-sell**: "LuwiPress CRM ile segmente ozel kampanyalar"
- **Maps to**: CRM Bridge, Email Proxy

### 14. Luwi SEO Score Badge
- **Standalone**: Gorsel SEO skor gostergesi (basit checklist bazli)
- **LuwiPress+**: Rank Math/Yoast'tan gercek skor + AI improvement onerileri
- **Cross-sell**: "LuwiPress AI ile SEO'nuzu otomatik optimize edin"
- **Maps to**: SEO module, Plugin Detector

---

## Tier 3: LuwiPress-Required Widgets (6 widget)

### 15. Luwi AI Chat
- **Requires**: LuwiPress Customer Chat module
- **Gosterim**: Elementor'da konfigre edilebilir chat widget
- **Ozellikler**: Pozisyon, renk, mesaj, escalation (WhatsApp/Telegram)
- **Maps to**: Customer Chat (RAG, BM25, session history)

### 16. Luwi Knowledge Graph
- **Requires**: LuwiPress Knowledge Graph module
- **Gosterim**: D3.js interaktif magaza zeka grafi (embed edilebilir)
- **Kullanim**: Admin dashboard'da veya ozel sayfalarda
- **Maps to**: Knowledge Graph (20-section store analysis)

### 17. Luwi Marketplace Status
- **Requires**: LuwiPress Marketplace module
- **Gosterim**: Urunun hangi marketplace'lerde aktif oldugunu gosteren badge satiri
- **Kullanim**: Urun detay sayfasinda "Available on: Amazon, Etsy, Trendyol"
- **Maps to**: Marketplace adapters (8 platforms)

### 18. Luwi Translation Switcher
- **Requires**: LuwiPress Translation + WPML/Polylang
- **Gosterim**: Gelismis dil secici (bayrak + completion %, missing indicator)
- **Kullanim**: Header'da veya sayfa icinde
- **Maps to**: Translation module (missing report, quality check)

### 19. Luwi Content Freshness
- **Requires**: LuwiPress SEO module (thin/stale detection)
- **Gosterim**: Icerik guncellik durumu badge'i
- **Kullanim**: Blog post'larda "Last AI-reviewed: 3 days ago" gostergesi
- **Maps to**: Stale content detection, Internal Linker

### 20. Luwi AI Writer
- **Requires**: LuwiPress AI Engine + Content Scheduler
- **Gosterim**: Frontend'de AI icerik uretim arayuzu (admin-only)
- **Kullanim**: Inline editing, blog yazmak icin
- **Maps to**: Content Scheduler, AI Engine dispatch

---

## Implementation Priority

### Phase 1 (v1.0.0 — Theme Launch)
Must-have for Envato submission:

| Widget | Tier | Effort | Impact |
|--------|------|--------|--------|
| Luwi Product Card | T1 | Medium | Very High |
| Luwi Hero Slider | T1 | Medium | Very High |
| Luwi Category Showcase | T1 | Low | High |
| Luwi Trust Badges | T1 | Low | High |
| Luwi Color Mode Toggle | T1 | Low | Medium |
| Luwi Countdown Timer | T1 | Low | Medium |

### Phase 2 (v1.1.0 — LuwiPress Integration)
Cross-marketing launch:

| Widget | Tier | Effort | Impact |
|--------|------|--------|--------|
| Luwi FAQ Accordion | T2 | Medium | High |
| Luwi Smart Search | T2 | High | Very High |
| Luwi AI Chat | T3 | Low (exists) | High |
| Luwi Testimonials | T1 | Medium | Medium |

### Phase 3 (v1.2.0 — Full Ecosystem)

| Widget | Tier | Effort | Impact |
|--------|------|--------|--------|
| Luwi Related Products | T2 | Medium | High |
| Luwi Knowledge Graph | T3 | Medium | Medium |
| Luwi Marketplace Status | T3 | Low | Medium |
| Luwi Newsletter | T2 | Medium | Medium |
| Luwi Product Comparison | T2 | High | Medium |
| Luwi Translation Switcher | T3 | Low | Low |
| Luwi Before/After | T1 | Medium | Low |
| Luwi SEO Score Badge | T2 | Low | Low |
| Luwi Content Freshness | T3 | Low | Low |
| Luwi AI Writer | T3 | High | Medium |

---

## Cross-Marketing UI Patterns

### "Powered by LuwiPress" Badge
Tier 2 widget'larda, LuwiPress inaktifken:
```
┌─────────────────────────────────────┐
│  ⚡ This widget gains AI powers     │
│  with LuwiPress plugin              │
│  [Learn More] [Install Now]         │
└─────────────────────────────────────┘
```

### Elementor Panel Description
```
Luwi Smart Search
Search widget with live results.
🔌 Enhanced with LuwiPress: AI-powered search,
   natural language queries, product recommendations.
```

### Admin Notice (theme active, plugin not)
```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🎯 Unlock 12 AI-powered widgets
   Install LuwiPress to supercharge
   your Luwi Elementor theme.
   [Install LuwiPress] [Dismiss]
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

---

## Technical Architecture

### Widget Base Class
```php
abstract class Luwi_Widget extends \Elementor\Widget_Base {
    public function get_categories() {
        return ['luwi-widgets'];
    }

    protected function is_luwipress_active() {
        return class_exists('LuwiPress');
    }

    protected function render_luwipress_notice() {
        if (!$this->is_luwipress_active()) {
            echo '<div class="luwi-widget-notice">...</div>';
        }
    }
}
```

### Widget Category Registration
```php
// In class-luwi-elementor.php
function register_widget_categories($elements_manager) {
    $elements_manager->add_category('luwi-widgets', [
        'title' => __('Luwi Widgets', 'luwi-ruby'),
        'icon'  => 'eicon-apps',
    ]);
}
```

### File Structure
```
luwi-ruby/
└── widgets/
    ├── class-luwi-widget-base.php
    ├── class-luwi-product-card.php
    ├── class-luwi-hero-slider.php
    ├── class-luwi-category-showcase.php
    ├── class-luwi-trust-badges.php
    ├── class-luwi-testimonials.php
    ├── class-luwi-faq-accordion.php
    ├── class-luwi-smart-search.php
    ├── class-luwi-countdown.php
    ├── class-luwi-color-toggle.php
    ├── class-luwi-before-after.php
    ├── class-luwi-newsletter.php
    ├── class-luwi-comparison.php
    ├── class-luwi-related-products.php
    ├── class-luwi-seo-badge.php
    ├── class-luwi-ai-chat.php
    ├── class-luwi-knowledge-graph.php
    ├── class-luwi-marketplace-status.php
    ├── class-luwi-translation-switcher.php
    ├── class-luwi-content-freshness.php
    └── class-luwi-ai-writer.php
```
