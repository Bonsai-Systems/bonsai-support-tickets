# Design — Support Desk theme

Clean, editorial and accessible: square buttons, hairline cards, calm motion, AA-safe colours.

## Colour tokens (`assets/css/base.css`)

The `--bonsai-*` names are internal. With the Support Desk plugin active, the brand colours below are replaced by **Support → Settings → Appearance** (`inc/support.php`, `bsup_color_tokens()`), and the tint by **Site settings → Branding → Tint background**.

| Token | Default | Plugin colour | Use |
|---|---|---|---|
| `--bonsai-accent` | `#4f46e5` | Accent | Full stops, rules, icons, large text |
| `--bonsai-accent-hover` | `#4338ca` | Button hover | Button hover fill (white text must pass AA) |
| `--bonsai-accent-text` | `#4338ca` | Accent text | Small accent text, section tags, focus rings |
| `--bonsai-black` | `#111827` | Buttons and headings | Headings, buttons, dark sections |
| `--bonsai-grey-dark` | `#374151` | Body text | Paragraphs |
| `--bonsai-warm` | `#f9fafb` | Page background | Page background |
| `--bonsai-white` | `#fff` | Cards | Cards, form panels |
| `--bonsai-blue` | `#eef2ff` | (Site settings: Tint background) | Tint band, icon tiles |
| `--bonsai-grey-mid` | `#6b6b6b` | — | Meta text (AA on the page background and white) |

Module backgrounds: White (`default`), Page background (`warm`), Tint (`blue`), Dark (`black`). Cards stay white on any background.

## Type

- Display headings: **Bricolage Grotesque** 500, tight tracking (`.display-heading`), accent full stop via `.has-stop` (skipped when the heading ends in punctuation).
- Everything else: **Plus Jakarta Sans**.
- Both self-hosted (`assets/fonts`, SIL OFL 1.1), Latin and Latin Extended.
- Section tag: 12px, uppercase, 0.15em tracking, accent text colour.

## Components

- Buttons: square, uppercase, dark. Hover fills with a skewed sweep from the left (`.btn-primary`, `.btn-secondary`, `.btn-light`, `.btn-outline-light`).
- Cards: white, 1px hairline border, no radius, border darkens on hover.
- Ticket UI comes from the plugin (`bst-frontend.css`) and follows the same brand colours.

## Motion

Calm only: 16px fade-up on scroll (`.reveal-on-scroll`), card stagger of 80ms per column (`data-reveal-cards`). All of it is off under `prefers-reduced-motion`, and content is never hidden without JS.
