---
name: Smart Inventory System
description: A restrained Arabic RTL inventory operations interface built for frequent daily use
colors:
  primary: "#059669"
  primary-hover: "#047857"
  navigation-dark: "#0f172a"
  neutral-bg: "#f1f5f9"
  surface: "#ffffff"
  text-strong: "#1e293b"
  text-muted: "#64748b"
  border: "#e2e8f0"
  info: "#4f46e5"
  warning: "#fef3c7"
  warning-text: "#92400e"
  success-bg: "#d1fae5"
  success-text: "#065f46"
  danger: "#e11d48"
typography:
  body:
    fontFamily: "Cairo, Figtree, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
  title:
    fontFamily: "Cairo, Figtree, sans-serif"
    fontSize: "24px"
    fontWeight: 700
    lineHeight: 1.3
  label:
    fontFamily: "Cairo, Figtree, sans-serif"
    fontSize: "12px"
    fontWeight: 600
    lineHeight: 1.25
rounded:
  sm: "2px"
  md: "6px"
  lg: "8px"
  xl: "12px"
  2xl: "16px"
  full: "9999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    padding: "8px 16px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    padding: "8px 16px"
  input-default:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text-strong}"
    rounded: "{rounded.lg}"
    padding: "8px 12px"
  nav-active:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    padding: "12px 16px"
---

# Design System: Smart Inventory System

## Overview

**Creative North Star: "The Operational Ledger"**

The incumbent interface is a practical Arabic RTL operations workspace. It favors readable data, familiar controls, and low-friction task completion over visual spectacle. Surfaces are light, borders are quiet, navigation is persistent, and status is communicated with restrained semantic color.

The character is refined and restrained: compact enough for daily work, but with clear spacing and a consistent component vocabulary. Keep the existing Ledger-like information density while avoiding decorative treatments that compete with inventory data.

**Key Characteristics:**
- Arabic-first right-to-left structure
- Clean light surfaces on a cool slate-neutral page
- Emerald actions and selection with indigo reserved for import tools
- Consistent rounded panels, inputs, tables, and buttons
- Desktop task density with responsive collapse rather than fluid typography

## Colors

### Primary
- **Operational Emerald** (`{colors.primary}`): Main affirmative actions, selected inventory navigation, success accents, and active status.
- **Operational Emerald Deep** (`{colors.primary-hover}`): Hover and focus-adjacent treatment for primary actions.

### Secondary
- **Import Indigo** (`{colors.info}`): Excel import actions, background progress, and import-specific workflows.

### Neutral
- **Deep Navigation Slate** (`{colors.navigation-dark}`): Dashboard sidebar and strong dark action surfaces.
- **Workspace Neutral** (`{colors.neutral-bg}`): Page background behind cards, tables, and application chrome.
- **Clean Surface** (`{colors.surface}`): Cards, panels, inputs, tables, and menus.
- **Strong Text** (`{colors.text-strong}`): Headings, table values, and high-priority content.
- **Muted Text** (`{colors.text-muted}`): Supporting copy, labels, and secondary information.
- **Quiet Border** (`{colors.border}`): Card boundaries, table dividers, and form outlines.

### Semantic
- **Warning Amber** (`{colors.warning}`) with **Amber Text** (`{colors.warning-text}`): Pending codes, rollback actions, and caution states.
- **Success Mint** (`{colors.success-bg}`) with **Success Text** (`{colors.success-text}`): Positive status and successful records.
- **Danger Rose** (`{colors.danger}`): Destructive actions, errors, and stopped states.

**The Accent Rarity Rule.** Emerald leads actions and current location; indigo is reserved for import tools, and neutral surfaces remain visually dominant.

## Typography

**Display Font:** Cairo (with Figtree and system sans-serif fallbacks)
**Body Font:** Cairo in dashboard views; Figtree in Breeze component views
**Label/Mono Font:** Cairo for labels and controls; browser monospace for codes and phone numbers

**Character:** The interface uses a single soft geometric Arabic voice for operational copy. Supporting type remains readable and familiar rather than expressive.

### Hierarchy
- **Title** (700, 24px, 1.3): Page titles and principal section headings in the dashboard layout.
- **Section Title** (700, 20px, 1.3): Card and panel headings.
- **Body** (400–600, 14px, 1.5): Forms, tables, descriptions, and working content.
- **Label** (600, 12px, 1.25): Field labels, metadata, compact badges, and table headings.

**The One Voice Rule.** Do not introduce display or script faces into inventory UI; headings, controls, labels, and data stay in the product’s established sans-serif family.

## Layout

The dashboard uses a fixed right sidebar at desktop widths and a flexible content column with overflow protection. Content is grouped into full-width panels with `space-y-6` between major sections and tighter internal gaps inside forms and toolbars.

The main dashboard content uses 24–32px page spacing, while cards use 24px padding. Forms commonly use 3–5 responsive columns, collapsing to one column on small screens. Tables remain horizontally scrollable instead of squeezing identifiers and actions below readability.

Breakpoints follow Tailwind conventions: `sm` 640px, `md` 768px, `lg` 1024px. The sidebar structure is hidden or mobile-adapted through the existing layout components rather than being replaced by a new navigation paradigm.

## Elevation & Depth

The system is flat at rest with borders and subtle tonal shifts defining structure. Panels use a small diffuse shadow, while the dark sidebar and occasional floating import widgets provide stronger structural depth.

### Shadow Vocabulary
- **Panel Lift** (`box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05)`): Card and panel surfaces.
- **Floating Widget** (`box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25)`): Persistent or modal-like overlays that must separate from the page.

**The Flat-by-Default Rule.** Borders define normal surfaces; shadows appear only when a panel must visually lift or an overlay must escape the page.

## Shapes

Corners are consistently rounded, but never ornamental: `6px` for compact controls, `8px` for buttons and fields, `12px` for cards and modals, and `16px` for larger floating surfaces. Pill-shaped chips use `9999px` only for statuses and counts.

Panels use a `1px` slate border and white fill. Text inputs use slate borders at rest and emerald or indigo focus borders depending on the active product area.

## Components

### Buttons
- **Shape:** Rounded (`8px`) with compact horizontal and vertical padding.
- **Primary:** Emerald fill, white text, and a deep emerald hover state.
- **Secondary:** Slate, white, emerald-tint, or light semantic fills depending on the action.
- **Danger:** Rose or amber treatment with explicit destructive labels; avoid icon-only destructive controls.
- **Hover / Focus:** Short state transitions, emerald or indigo visible focus, and no decorative movement.

### Inputs
- **Shape:** White, slate-border, `8px` fields with `8–12px` vertical/horizontal padding.
- **Focus:** Emerald or indigo border/ring with a clear `2px` offset-capable focus ring in shared Breeze components.
- **Validation:** Error copy stays close to the control and uses rose state styling.
- **Numeric and codes:** Use left alignment and monospace treatment where the value is intrinsically LTR.

### Cards and Panels
- **Style:** White surface, quiet slate border, `12px` radius, and small shadow.
- **Use:** Group a single workflow or information table; do not stack cards within cards.
- **Density:** Keep 24px panel padding and 16px action gaps.

### Navigation
- **Desktop Sidebar:** Dark slate, right-aligned, with compact labelled links and `8px` selection radius.
- **Active State:** Emerald fill and white text in dashboard views.
- **Mobile Navigation:** Existing hamburger and vertical menu behavior rather than a custom alternate pattern.

### Chips and Status
- **Style:** Small pill-shaped semantic labels with matching light fill, medium text color, and a quiet border when applicable.
- **Use:** Stock count, account status, client status, import progress, and OTP state.

### Tables
- **Style:** Right-aligned Arabic headers, white rows, slate dividers, and restrained slate header surfaces.
- **Behavior:** Desktop density is preserved; overflow-x scrolling is mandatory before row text becomes unreadable.
- **Actions:** Group compact text actions near the row edge rather than introducing icon-only affordances.

## Do's and Don'ts

### Do:
- Preserve the Arabic RTL structure and existing terminology.
- Use direct, concise Arabic action labels beside familiar inventory concepts.
- Keep tables and data values easy to scan with consistent alignment and semantic status color.
- Use emerald for primary affirmative actions and current inventory navigation.
- Use slate borders and tonal surfaces before introducing shadow.
- Preserve keyboard focus, error recovery, loading feedback, and mobile overflow behavior.

### Don't:
- Introduce an unrequested visual world, hero treatment, or marketing-style layout.
- Mix emoji, ornamental icons, and line-icon systems; use the existing controls and symbols consistently.
- Replace familiar forms and table affordances with decorative cards or custom widgets.
- Use a display font, oversized heading, gradient text, or saturated inactive state.
- Color every section with a different accent; reserve indigo and semantic colors for their workflows.
- Delete or weaken established permission, validation, and data-integrity behavior during visual refinement.
