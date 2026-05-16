# FinTrack — AI-Powered Personal Finance Tracker

Graduation project: a personal expense tracking web application with a **Laravel** front end and a **FastAPI** API layer. Users register, log expenses, scan receipts with OCR, view dashboards and charts, export monthly reports, and manage budget settings.

Application data (users, expenses, categories) is stored in **MySQL**. Laravel keeps its own **SQLite** database only for framework concerns (sessions, cache, queues)—not for expense records.

## Architecture

```
Browser  →  Laravel (Blade, sessions)  →  HTTP  →  FastAPI (business logic)  →  MySQL
              SQLite (sessions/cache only)              phpMyAdmin / XAMPP
```

| Layer | Technology | Role |
|-------|------------|------|
| Web UI | Laravel 12, PHP 8.2+ | Pages, forms, PDF/CSV export, receipt scan UI |
| API | FastAPI, SQLAlchemy, Pydantic | Auth, expenses, categories, receipt OCR |
| App data | **MySQL** | `users`, `expenses`, `expense_categories` |
| Laravel internals | **SQLite** (default in `.env.example`) | Sessions, cache, jobs—not expense data |

**Why SQLite in Laravel’s `.env`?**  
Laravel needs a local database for sessions and cache when `SESSION_DRIVER=database` and `CACHE_STORE=database` are set. That is separate from the MySQL database used by FastAPI. All expense CRUD goes through the API (`FASTAPI_URL`).

Default local URLs:

| Service | URL |
|---------|-----|
| Laravel | http://127.0.0.1:8000 |
| FastAPI | http://127.0.0.1:8001 |
| API docs | http://127.0.0.1:8001/docs |

## Features

- **Auth** — Register, login, logout (session-based UI; passwords validated via API)
- **Dashboard** — Monthly spend vs budget, quick overview
- **Charts** — Spending visualizations
- **My Expenses** — List with month picker, optional date/category filters, pagination, edit/delete
- **Add Expense** — Manual entry with category, amount, date, description
- **Receipt Scan** — Upload or capture a receipt; OCR (Tesseract) suggests amount, date, and category; confirm before save
- **Reports** — Per-month CSV/PDF download; bulk **Download all CSV (ZIP)** and **Download all PDF (ZIP)**
- **Settings** — Currency, monthly budget, fixed monthly expense templates, change password

Default expense categories (seeded on API startup if missing): Food, Transport, Rent, Utilities, Groceries, Health, Education, Entertainment, Clothing, Other.

## Repository layout

```
ai-powered-personal-finance-tracker/
├── backend/                    # FastAPI service
│   ├── .env.example            # MySQL template (copy to .env)
│   ├── requirements.txt
│   ├── test_db.py              # Optional: MySQL connection test script
│   └── app/
│       ├── routers/            # auth, expenses, categories, receipts
│       ├── services/             # receipt_ocr.py
│       └── models_db.py
├── laravel_app/                # Laravel web app
│   ├── .env.example            # Laravel + FASTAPI_URL (copy to .env)
│   ├── resources/views/        # Blade UI
│   └── routes/web.php
└── README.md
```

## Prerequisites

- **PHP** 8.2+, **Composer**
- **Python** 3.11+ (3.14 supported in development)
- **MySQL** (e.g. XAMPP / phpMyAdmin) and an empty database (e.g. `finance_tracker`)
- **Tesseract OCR** (required for receipt scan) — [install guide](https://github.com/tesseract-ocr/tesseract)  
  On Windows, install Tesseract and ensure `tesseract` is on your `PATH`.

## Setup

### 1. MySQL

Create a database (example name: `finance_tracker`) in phpMyAdmin or the MySQL CLI.

### 2. Backend (FastAPI)

```bash
cd backend
python -m venv .venv

# Windows (PowerShell):
.\.venv\Scripts\python.exe -m pip install -U pip
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
copy .env.example .env
```

Edit `backend/.env` with your MySQL credentials:

```env
MYSQL_HOST=127.0.0.1
MYSQL_PORT=3306
MYSQL_USER=root
MYSQL_PASSWORD=
MYSQL_DATABASE=finance_tracker
```

Optional connection test:

```bash
.\.venv\Scripts\python.exe test_db.py
```

Start the API:

```bash
.\.venv\Scripts\python.exe -m uvicorn app.main:app --reload --host 127.0.0.1 --port 8001
```

- Swagger UI: http://127.0.0.1:8001/docs  
- DB health: http://127.0.0.1:8001/api/health/db  

Tables are created on first API startup; default categories are ensured if the table is empty.

### 3. Laravel (web UI)

```bash
cd laravel_app
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
```

Important `.env` values:

```env
FASTAPI_URL=http://127.0.0.1:8001
DB_CONNECTION=sqlite
```

`DB_CONNECTION=sqlite` is intentional (Laravel sessions/cache). Do **not** point Laravel’s default DB at MySQL unless you deliberately want Laravel to use MySQL for its own tables too.

Start Laravel:

```bash
php artisan serve
```

Open http://127.0.0.1:8000

## Running the full stack

1. Start **MySQL** and ensure the database from `backend/.env` exists.
2. Start **FastAPI** on port **8001**.
3. Start **Laravel** on port **8000** with `FASTAPI_URL` pointing to FastAPI.

## Environment files (summary)

| File | In Git? | Purpose |
|------|---------|---------|
| `backend/.env.example` | Yes | MySQL settings template for FastAPI |
| `backend/.env` | No | Your real MySQL password and DB name |
| `laravel_app/.env.example` | Yes | Laravel template (`APP_KEY` empty, `FASTAPI_URL`, SQLite) |
| `laravel_app/.env` | No | Your `APP_KEY`, local overrides |

## Receipt scan notes

- Supported images: JPG, PNG, WEBP (max 5 MB).
- OCR uses **Tesseract** (`eng+tur`) on the API server.
- Parsed fields are suggestions; the user confirms before saving.
- If OCR fails, check that Tesseract is installed and on `PATH`, and that `Pillow` / `pytesseract` are installed in the Python venv.

## Reports notes

- Each month with expenses can be downloaded as CSV or PDF.
- Bulk ZIP exports include **every** month that has expenses (not only the current reports page).
- ZIP archives require PHP’s `ZipArchive` extension (enabled by default on most PHP installs).

## License

This project is provided as-is for academic and portfolio use. Adjust licensing if you publish it formally.
