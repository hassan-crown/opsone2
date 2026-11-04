# UI batch A — before/after (ar, RTL)

Showcase data, owner account, Playwright Chromium. `before/` = main (318118d), `after/` = this branch.

| File | What to look at |
|---|---|
| `01-dashboard-*` | Grouped sidebar (البيع، التنفيذ، المالية…), notifications badge |
| `02-interests-*` | Pager: «التالي» now points left in RTL (finding 10) |
| `03-appt-request-show-*`, `05-help-*`, `06-catalog-product-new-*` | Back arrows no longer double-flipped |
| `04-release-show-*` | Breadcrumb separator mirrored through `Icon` |
| `07-drawer-375` | Phone drawer: groups, nav scrolls on its own, help in the footer |
| `break-ui/worst-*` | Worst case injected through Inertia props: 12,345,678.90 / −12,345,678.90 ر.س, 1,284 notifications, long workshop and user names, at 320/375/768/1440 |

Control font on touch screens: computed `--o1-fs-control` = 15px before, 16px after (`pointer: coarse`).
