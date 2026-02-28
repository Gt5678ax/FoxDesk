# FoxDesk

Open source helpdesk and ticketing system built with PHP, Tailwind CSS, and Alpine.js.

**Website:** [foxdesk.org](https://foxdesk.org)

---

## Features

**Ticket Management**
- Create, assign, comment, resolve, and archive tickets
- Custom statuses, priorities, and ticket types
- Tags, due dates, and organization assignment
- Public ticket sharing via secure links
- Bulk actions and advanced filtering

**Time Tracking**
- Built-in timers (start, pause, resume, stop)
- Manual time entry with descriptions
- Quick Start mode for instant timer
- Billable hours with configurable rounding
- Time reports with PDF and CSV export

**Email Integration**
- IMAP email-to-ticket ingest
- SMTP notifications for ticket updates
- Email templates with customizable content
- CC recipients on ticket replies

**User Roles**
- **Admin** - Full system access, settings, user management
- **Agent** - Ticket handling, time tracking, internal notes
- **Client** - Submit tickets, view own tickets, reply

**Reporting**
- Report builder with custom filters
- PDF and CSV export
- Public shareable report links
- Time tracking summaries by user, organization, date

**Multi-language**
- English, Czech, German, Spanish, Italian
- Per-user language preference

**Auto-Updates**
- WordPress-style update checking
- One-click download and install from admin panel
- Manual ZIP upload option
- Automatic backup before each update

**Other**
- Dark mode
- Responsive design (mobile-friendly)
- Recurring tasks
- Keyboard shortcuts
- Markdown ticket import/export
- AI agent API for automation

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP         | 8.1     |
| MySQL       | 5.7+ / MariaDB 10.2+ |
| Disk space  | 50 MB   |

Required PHP extensions: `pdo_mysql`, `mbstring`, `json`, `openssl`

---

## Quick Start

1. Upload files to your web server
2. Create a MySQL database
3. Copy `config.example.php` to `config.php` and edit credentials
4. Open `https://your-domain.tld/install.php`
5. Follow the installer (database setup + admin account)
6. Delete `install.php`
7. Log in and start using FoxDesk

See [INSTALL.md](INSTALL.md) for detailed instructions including shared hosting, VPS, Nginx, cron jobs, and email setup.

---

## Tech Stack

- **Backend:** PHP 8+ (no framework)
- **Database:** MySQL / MariaDB
- **Frontend:** Tailwind CSS (CDN), Alpine.js
- **Styling:** Custom `theme.css` with dark mode support

---

## Project Structure

```
index.php              Entry point
config.example.php     Configuration template
install.php            Web installer
theme.css              Custom styles + dark mode
tailwind.min.css       Tailwind CSS
assets/js/             JavaScript modules
includes/              Core PHP (auth, DB, functions, API, lang)
pages/                 Page controllers
pages/admin/           Admin panel pages
bin/                   CLI scripts (cron, email ingest)
```

---

## Cron Jobs

```bash
# Email ingest (every 5 min, if IMAP enabled)
*/5 * * * * php /path/to/bin/ingest-emails.php

# Recurring tasks (hourly)
0 * * * * php /path/to/bin/process-recurring-tasks.php

# Maintenance (daily)
0 3 * * * php /path/to/bin/run-maintenance.php
```

---

## API

FoxDesk includes a REST API for automation and integrations.

**Agent API endpoints:**
- `POST agent-create-ticket` - Create tickets
- `POST agent-add-comment` - Add comments
- `POST agent-log-time` - Log time entries
- `GET agent-list-tickets` - List tickets

Authentication via Bearer token (generated in Settings > Users > AI Agents).

---

## License

Open source. Created by [Lukas Hanes](https://lukashanes.com).
