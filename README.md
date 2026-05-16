# ⚡ GigBase — Lead Hunting Platform for Freelance Web Developers

Find businesses without websites. Send personalized pitches. Close deals at ₹15K-50K.

![GigBase](assets/gigbase-logo-full.png)

## What is GigBase?

GigBase helps freelance web developers find local businesses that don't have websites using Google Places API, then helps you send personalized pitches via WhatsApp and email to close deals.

### Features

- **Lead Hunter** — Search any niche + city via Google Places API
- **Active Business Score** — AI scores leads as Hot/Warm/Cold based on reviews, ratings, photos
- **Cache-First Architecture** — Free users get cached results (₹0 API cost), Pro users get live data
- **WhatsApp Pitch Generator** — 5 battle-tested templates with auto-fill (business name, portfolio, pricing)
- **Pipeline CRM** — Track leads: New → Contacted → Negotiating → Closed → Export to Excel
- **Revenue Tracker** — Multi-currency goals, monthly charts, savings targets
- **Portfolio** — Upload work samples, auto-embed in pitches
- **Playbook** — Math-backed blueprint with daily routine and pricing guide
- **Monthly/Weekly Limits** — Not daily. Binge when you're in flow.
- **Extended Packs** — Claude-style "buy more searches" when limit hits

### Pricing

| Plan | India | Global | Searches/Month |
|------|-------|--------|---------------|
| Free | ₹0 | $0 | 10 (cached) |
| Pro | ₹889/mo | $19/mo | 150 (live) |
| Pro+ | ₹1,699/mo | $29/mo | 500 (live) |
| Elite | ₹2,999/mo | $49/mo | 1,500 (live) |

## Tech Stack

- **Frontend:** HTML5, Tailwind CSS (CDN), Vanilla JavaScript
- **Backend:** PHP 8.0+, PDO MySQL
- **Database:** MySQL / MariaDB
- **Auth:** Email OTP (passwordless, zero dependencies)
- **Email:** Raw SMTP sockets (no PHPMailer, no Composer)
- **APIs:** Google Places API, Razorpay
- **Hosting:** Hostinger (or any PHP hosting)

## Setup

### 1. Clone the repo

```bash
git clone https://github.com/yourusername/gigbase.git
cd gigbase
```

### 2. Create config file

```bash
cp includes/config.example.php includes/config.php
```

Edit `includes/config.php` with your credentials:
- Database credentials
- Google Places API key
- Razorpay keys
- SMTP credentials

### 3. Set up database

Import `gigbase_v3.sql` in phpMyAdmin or run:

```bash
mysql -u your_user -p your_database < gigbase_v3.sql
```

### 4. Folder structure

```
public_html/
├── index.php                 # Main SPA
├── includes/
│   ├── config.example.php    # Template (committed)
│   └── config.php            # Your credentials (gitignored)
├── api/
│   ├── auth.php
│   ├── leads.php
│   ├── places.php
│   ├── pitches.php
│   ├── portfolio.php
│   ├── revenue.php
│   ├── settings.php
│   ├── stats.php
│   └── upload.php
├── assets/
│   ├── gigbase-logo-full.png
│   ├── gigbase-icon-512.png
│   ├── gigbase-favicon-32.png
│   └── ...
├── uploads/
│   └── portfolio/
├── .gitignore
└── README.md
```

### 5. Get Google Places API Key

1. Go to [console.cloud.google.com](https://console.cloud.google.com)
2. Create project → Enable "Places API" (legacy, not "New")
3. Create API key → Restrict to Places API
4. Enable billing ($200/month free credits)
5. Add key to `config.php`

### 6. Deploy

Upload to Hostinger (or any PHP hosting with MySQL).

## Architecture

- **Cache-first:** Free users never touch the live API. Results cached for 14 days.
- **Lazy detail loading:** Text Search (1 API call) first, Details fetched only on click.
- **Monthly limits:** Not daily. Users can binge when hunting.
- **Extended packs:** Pay-per-use when monthly limit hits.
- **Active Business Score:** Rates businesses 0-100 based on rating, reviews, photos, phone, hours.

## Contributing

PRs welcome. Please keep the zero-dependency philosophy — no Composer, no npm, no build tools.

## License

MIT

---

Built by [GigBase](https://gigbaseapp.com) — Hunt. Pitch. Close.
