# FoxDesk self-hosted screenshots

These screenshots document the current self-hosted FoxDesk UI. They are generated
from a clean local Docker and Playwright installation with a deterministic,
populated synthetic dataset. The capture contains multiple organizations, agents,
tickets, comments, time entries, due dates, rates, and a running timer.

Regenerate them from the repository root:

```bash
npm run local:screenshots
```

Generated files:

- `dashboard-light.png`
- `dashboard-dark.png`
- `tickets-light.png`
- `tickets-dark.png`
- `ticket-detail-light.png`
- `ticket-detail-dark.png`
- `reports-light.png`
- `reports-dark.png`
- `clients-light.png`
- `clients-dark.png`
- `ticket-detail-mobile-light.png`

All names, messages, addresses, and work records are fictional. The generator only
uses reserved `.example` and `.test` domains. Do not replace these assets with
screenshots from a customer or production workspace.

This folder is only for the open-source self-hosted release channel. It must not
contain FoxDesk Cloud administration, billing, tenant management, or private SaaS
screens.
