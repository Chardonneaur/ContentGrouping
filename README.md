# ContentGrouping Plugin for Matomo

Group page URLs into named content groups using regex or prefix rules, with full reporting and goal metrics.

## Features

- **Flexible URL matching** — Define rules using URL prefix or regex patterns
- **Priority-based evaluation** — Rules are evaluated in priority order (lowest number first); first match wins
- **Per-site configuration** — Each website has its own set of content grouping rules
- **Full reporting** — Dedicated Content Groups report under Actions with:
  - Pageviews and unique pageviews (correctly computed at group level)
  - Bounce rate, average time on page, exit rate
  - Goal conversions and revenue per group
  - Expandable subtables showing individual URLs within each group
- **Admin UI** — Manage rules, test URLs, and invalidate reports from the Matomo admin panel
- **Site selector** — Switch between websites directly from the admin page
- **Report invalidation** — Re-process archived reports per-site after changing rules

## Requirements

- Matomo >= 5.0.0, < 6.0.0

## Installation

1. Copy the `ContentGrouping` folder into your Matomo `plugins/` directory
2. Activate the plugin in **Administration > Plugins**
3. The database table is created automatically on activation

## Configuration

Navigate to **Administration > Websites > Content Groups** to manage rules.

### Adding a Rule

| Field | Description |
|-------|-------------|
| **Group Name** | The label shown in reports (e.g., "Blog", "Product Pages") |
| **Pattern** | The URL prefix or regex pattern to match |
| **Match Type** | `Prefix` — matches URLs starting with the pattern; `Regex` — full regex matching |
| **Priority** | Lower number = higher priority. Rules with the same priority are evaluated by creation order |

### Match Type Examples

**Prefix:**
| Pattern | Matches |
|---------|---------|
| `https://example.org/blog` | `https://example.org/blog`, `https://example.org/blog/post-1`, etc. |
| `/products` | `/products`, `/products/shoes`, etc. |

**Regex:**
| Pattern | Matches |
|---------|---------|
| `/page[1-3]` | URLs containing `/page1`, `/page2`, or `/page3` |
| `/(news\|pricing)` | URLs containing `/news` or `/pricing` |
| `^https://example\.org/blog/\d+` | Blog posts with numeric IDs |

### Testing Rules

Use the **Test URL** field at the bottom of the admin page to verify which group a URL matches before saving.

### Re-processing Reports

After adding or changing rules, click **Invalidate Reports** to clear cached data for the selected website. Reports will be re-processed with the updated rules on the next archiving run or page load.

## Report Location

The Content Groups report is available under **Actions > Content Groups** in the Matomo reporting interface.

## API Methods

All methods are available via the Matomo HTTP API.

### Reporting

| Method | Access | Description |
|--------|--------|-------------|
| `ContentGrouping.getContentGroups` | View | Get the Content Groups report |

### Rule Management

| Method | Access | Description |
|--------|--------|-------------|
| `ContentGrouping.getRules` | Admin | List all rules for a site |
| `ContentGrouping.addRule` | Admin | Create a new rule |
| `ContentGrouping.updateRule` | Admin | Update an existing rule |
| `ContentGrouping.deleteRule` | Admin | Delete a rule |
| `ContentGrouping.testUrl` | Admin | Test which group a URL matches |
| `ContentGrouping.invalidateReports` | Admin | Invalidate cached reports for a site |

## Security

- All API methods enforce proper access control (admin for management, view for reports)
- SQL queries are fully parameterized
- Regex patterns are validated for syntax and checked for ReDoS-prone nested quantifiers
- Runtime regex evaluation uses a reduced PCRE backtrack limit with error logging
- Template output is HTML-escaped against XSS

## License

GPL v3 or later
