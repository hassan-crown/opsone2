# Automation rules (ADR-0042) — screenshots

Showcase dataset (`ShowcaseSeeder`: the five templates plus a «thank you» rule published on 2026-07-15 as release 2,
the daily timer/worker pass through the story, the «thank you» rule stopped on 2026-09-10), owner account, desktop
1440 px unless noted. Taken on the local `opsone_nc4` stack.

| File | Screen |
|---|---|
| `automation-rules-ar.png` | Settings › automation: the ready templates, each rule as «عند … وإذا … فافعل …», release state, 7-day counts, the stopped rule with its reason |
| `stop-confirm-ar.png` | The kill switch asking for a reason before stopping a rule at once |
| `builder-payment-reminder-ar.png` | Builder: payment due soon (3 days) → message the customer (WhatsApp, else SMS) |
| `builder-overdue-task-ar.png` | Builder: payment overdue 7 days and remaining > 0 → follow-up task in 2 working days + order note |
| `builder-template-lead-ar.png` | A new rule from the «lead untouched 48 h → alert the manager» template |
| `try-installation-ar.png` | «Try it» on the latest 5 real appointment confirmations: skipped (not an installation) and matched, with the rendered message; nothing sent |
| `log-installation-ar.png` | The run log of a rule: matched / skipped and why, actions and their result |
| `log-stopped-rule-ar.png` | The run log of the stopped rule (`killed` after the stop) |
| `release-with-rules-ar.png` | Release 2: the automation section in the change log and what a rollback would change |
| `automation-rules-en.png`, `builder-overdue-task-en.png` | The same pages in English (LTR) |
| `automation-rules-mobile-ar.png`, `builder-mobile-ar.png` | At 390 px |
