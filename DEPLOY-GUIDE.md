# FoxDesk — Deploy na helpdesk.aenze.com

## Přehled kroků

```
Wedos IP:   95.168.198.51
Wedos IPv6: 2a01:0028:00ca:0112:0000:0000:0001:0657
Doména:     helpdesk.aenze.com
```

1. **Wedos:** Přidat subdoménu `helpdesk` (složka na FTP)
2. **Wedos:** Vytvořit databázi
3. **Cloudflare:** DNS záznamy (A + AAAA)
4. **FTP:** Nahrát soubory z `foxdesk-deploy.zip`
5. **Browser:** Spustit instalátor na https://helpdesk.aenze.com
6. **Wedos:** Nastavit CRON
7. **Cloudflare:** SSL + cache

---

## 1. Wedos — Subdoména

1. Přihlas se do **Wedos administrace** → Webhosting → `aenze.com`
2. V **FTP klientu** (FileZilla) se připoj na hosting
3. Vytvoř složku: `www/subdom/helpdesk/`
4. Tím se aktivuje subdoména `helpdesk.aenze.com`
5. Document root pro subdoménu = `www/subdom/helpdesk/`

> **Pozor:** Wedos automaticky detekuje subdomény podle složek v `www/subdom/`.
> Název složky = název subdomény (bez domény).

---

## 2. Wedos — Databáze

1. Ve Wedos administraci → **Nová databáze** (levé menu)
2. Název databáze: `foxdesk` (max 7 znaků bez diakritiky)
   - Reálný název bude `wXXXXX_foxdesk` (prefix přidá Wedos)
3. Zapamatuj si:
   - **DB host:** `localhost` (DB běží na stejném serveru jako hosting)
   - **DB user:** `wXXXXX` (Wedos admin user)
   - **DB pass:** Heslo z administrace
   - **DB name:** `wXXXXX_foxdesk`
4. Ověř přístup přes **phpMyAdmin**: https://pma.wedos.net

---

## 3. Cloudflare — DNS

1. Přihlas se do **Cloudflare Dashboard** → `aenze.com` → **DNS** → **Records**
2. Klikni **Add record** a přidej tyto **dva** záznamy:

### Záznam 1 — IPv4 (A)

```
Type:    A
Name:    helpdesk
Content: 95.168.198.51
Proxy:   Proxied (oranžový mráček) ✅
TTL:     Auto
```

### Záznam 2 — IPv6 (AAAA)

```
Type:    AAAA
Name:    helpdesk
Content: 2a01:0028:00ca:0112:0000:0000:0001:0657
Proxy:   Proxied (oranžový mráček) ✅
TTL:     Auto
```

> **Proč Proxied?** Cloudflare proxy skryje IP serveru, přidá DDoS ochranu,
> cache pro statické soubory a automatický HTTPS.

### Cloudflare SSL nastavení

1. Cloudflare → `aenze.com` → **SSL/TLS** → **Overview**
2. Nastav režim: **Full** (ne Full strict — Wedos nemusí mít cert na subdoménu hned)
   - Jakmile Wedos vygeneruje Let's Encrypt certifikát, přepni na **Full (strict)**
3. **Edge Certificates** → Always Use HTTPS: ✅ ON
4. **Edge Certificates** → Minimum TLS Version: **TLS 1.2**

### Cloudflare Page Rules (volitelné, doporučené)

Přidej pravidlo pro lepší cache statických souborů:

```
URL:     helpdesk.aenze.com/assets/*
Setting: Cache Level → Cache Everything
Edge TTL: 1 month
```

### Ověření DNS

Po přidání záznamů ověř propagaci (může trvat 1–5 minut díky Cloudflare):

```bash
nslookup helpdesk.aenze.com
# Měl by vrátit Cloudflare IP (104.x.x.x), NE Wedos IP
# To je správně — Cloudflare proxy schová skutečnou IP

curl -I https://helpdesk.aenze.com
# Měl by vrátit HTTP 200 nebo 302 (redirect na login)
```

---

## 4. Nahrát soubory

### Rozbal `foxdesk-deploy.zip`

Obsah ZIP nahraj do `www/subdom/helpdesk/` přes FTP:

```
www/subdom/helpdesk/
├── .htaccess
├── index.php
├── install.php
├── upgrade.php
├── rescue.php
├── image.php
├── config.example.php
├── version.json
├── tailwind.min.css
├── theme.css
├── README.md
├── INSTALL.md
├── LICENSE.md
├── MANUAL.md
├── assets/
│   └── js/
├── includes/
│   ├── api/
│   ├── components/
│   ├── lang/
│   └── schema.sql
├── pages/
│   └── admin/
├── bin/
├── uploads/       ← prázdná, musí být zapisovatelná
├── backups/       ← prázdná, musí být zapisovatelná
└── storage/
    └── tickets/   ← prázdná, musí být zapisovatelná
```

### FTP nastavení (FileZilla)

```
Host:     ftp.wedos.net (nebo dle administrace)
User:     wXXXXX (Wedos FTP user)
Pass:     (tvé FTP heslo)
Port:     21 (FTP) nebo 990 (FTPS)
```

### Po nahrání — oprávnění

Wedos obvykle nastavuje oprávnění automaticky. Pokud ne:

```
uploads/          → 755 nebo 775
backups/          → 755 nebo 775
storage/tickets/  → 755 nebo 775
```

---

## 5. Spustit instalátor

1. Otevři v prohlížeči: **https://helpdesk.aenze.com**
2. Aplikace automaticky přesměruje na `install.php`
3. Vyplň:
   - **DB Host:** `localhost` (DB je na stejném serveru)
   - **DB Port:** `3306`
   - **DB Name:** `wXXXXX_foxdesk`
   - **DB User:** `wXXXXX` (Wedos admin user)
   - **DB Pass:** (heslo z administrace)
   - **App URL:** `https://helpdesk.aenze.com`
   - **App Name:** `FoxDesk` (nebo vlastní název)
   - **Admin email:** `lukas@aenze.com` (nebo jiný)
   - **Admin password:** (silné heslo, minimálně 12 znaků!)
4. Klikni **Install**
5. Instalátor:
   - Vytvoří `config.php`
   - Spustí `schema.sql` (27 tabulek)
   - Vytvoří admin účet
   - Nastaví výchozí statusy, priority, typy
6. Po instalaci **smaž install.php** (bezpečnost):
   - Přes FTP smaž `www/subdom/helpdesk/install.php`
   - Nebo přejmenuj na `install.php.bak`

---

## 6. CRON úlohy

FoxDesk potřebuje CRON pro:
- **Email ingest** (příchozí emaily → tickety)
- **Recurring tasks** (opakující se úlohy)
- **Maintenance** (čištění logů, expired sessions)

### Nastavení ve Wedos administraci

Wedos CRON spouští PHP skripty. Nastav tyto úlohy:

| Úloha | Cesta ke skriptu | Interval |
|-------|-----------------|----------|
| Email ingest | `/www/subdom/helpdesk/bin/ingest-emails.php` | 5 min* |
| Recurring tasks | `/www/subdom/helpdesk/bin/process-recurring-tasks.php` | 1 hodina |
| Maintenance | `/www/subdom/helpdesk/bin/run-maintenance.php` | 1 hodina |

> *Výchozí Wedos CRON má minimum 1 hodinu. Pro 5minutový interval
> potřebuješ **CRON+ addon** (10 úloh, min. 5 minut).
> Pokud email ingest není priorita, stačí 1× za hodinu.

---

## 7. Nastavení po instalaci

Po přihlášení jako admin:

### SMTP (odesílání emailů)

Wedos nemá vlastní SMTP relay. Doporučené možnosti:

**A) Vlastní email na aenze.com (pokud máš):**
```
SMTP Host:       smtp.wedos.net (nebo dle poskytovatele)
SMTP Port:       587
Encryption:      TLS
Username:        support@aenze.com
Password:        (heslo emailu)
From email:      support@aenze.com
From name:       FoxDesk Support
```

**B) Služba třetích stran (Mailgun, SendGrid, Brevo):**
```
SMTP Host:       smtp.mailgun.org
SMTP Port:       587
Encryption:      TLS
Username:        (z dashboardu služby)
Password:        (API key / password)
From email:      helpdesk@aenze.com
From name:       FoxDesk Support
```

### Měna a jazyk

Settings → General:
- **Currency:** USD (nebo CZK / EUR)
- **Language:** Czech / English
- **Time format:** 24h
- **Timezone:** Nastaveno v `config.php` → `date_default_timezone_set('Europe/Prague')`

### Uživatelé

Vytvoř reálné uživatelské účty (ne demo účty!):
1. Admin účet (ty)
2. Agenti (kolegové kteří řeší tickety)
3. Klienti se přidají sami nebo je pozveš

---

## 8. Wedos PHP nastavení

V administraci hostingu → **PHP nastavení**:

| Parametr | Doporučená hodnota |
|----------|-------------------|
| PHP verze | **8.1** nebo **8.2** (nejnovější dostupná) |
| memory_limit | 256M (nebo výchozí 512M) |
| upload_max_filesize | 20M |
| post_max_size | 25M |
| max_execution_time | 120 |
| max_input_vars | 3000 |

> Wedos NoLimit má `memory_limit = 512M` ve výchozím nastavení.

### Požadované PHP rozšíření (Wedos má ve výchozím stavu):
- `pdo_mysql` ✅
- `mbstring` ✅
- `json` ✅
- `openssl` ✅
- `curl` ✅
- `imap` (nutné pro email ingest — pokud ho chceš)
- `gd` nebo `imagick` (pro zpracování obrázků)

---

## Checklist před spuštěním

- [ ] DNS propagace dokončena (`nslookup helpdesk.aenze.com`)
- [ ] HTTPS funguje (Cloudflare + Wedos SSL)
- [ ] Instalátor proběhl úspěšně
- [ ] `install.php` smazán
- [ ] Admin login funguje
- [ ] SMTP odesílání emailů funguje (Settings → Emails → Test)
- [ ] CRON úlohy nastaveny
- [ ] `uploads/` je zapisovatelný (zkus nahrát přílohu k ticketu)
- [ ] `.htaccess` funguje (zkus přímý přístup na `/includes/` → má vrátit 403)

---

## Troubleshooting

### "500 Internal Server Error"
- Zkontroluj PHP verzi (min. 8.1)
- Zkontroluj `.htaccess` — Wedos podporuje mod_rewrite

### "Could not connect to database"
- DB host je `localhost` (DB i web jsou na stejném Wedos serveru)
- Ověř DB name s prefixem (`wXXXXX_foxdesk`)
- Ověř DB user a heslo (stejné jako v administraci Wedos)

### SMTP neodesílá
- Zkontroluj SMTP host/port/encryption
- Wedos může blokovat odchozí porty 25/465 — použij port 587

### Cloudflare "Too many redirects"
- Nastav SSL mode na **Full (strict)**, ne "Flexible"

### Stránka se nenačítá po DNS změně
- DNS propagace trvá 1–48 hodin
- Zkus `https://helpdesk.aenze.com` s vymazanou cache
- Ověř: `nslookup helpdesk.aenze.com` → měla by vrátit Cloudflare IP
