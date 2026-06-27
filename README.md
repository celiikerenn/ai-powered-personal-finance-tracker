# 💰 FinTrack — AI-Powered Personal Finance Tracker

A full-stack personal finance web application built as a graduation project. Users can track expenses, scan receipts with OCR, visualize spending with charts, export monthly reports, and manage budget settings.

---

## 🚀 Live Demo

- **Web App:** [https://ai-powered-personal-finance-tracker.up.railway.app](https://ai-powered-personal-finance-tracker.up.railway.app)
- **API Docs:** [https://ai-powered-personal-finance-tracker-production.up.railway.app/docs](https://ai-powered-personal-finance-tracker-production.up.railway.app/docs)

---

## 🏗️ Architecture

```
Browser → Laravel (Blade, sessions) → HTTP → FastAPI (business logic) → MySQL
            SQLite (sessions/cache only)
```

| Layer | Technology | Role |
|-------|-----------|------|
| Web UI | Laravel 12, PHP 8.2+ | Pages, forms, PDF/CSV export, receipt scan UI |
| API | FastAPI, SQLAlchemy, Pydantic | Auth, expenses, categories, receipt OCR |
| App data | MySQL | `users`, `expenses`, `expense_categories` |
| Laravel internals | SQLite | Sessions, cache, jobs |

---

## ✨ Features

- **Auth** — Register, login, logout (session-based)
- **Dashboard** — Monthly spend vs budget overview
- **Charts** — Spending visualizations by category
- **My Expenses** — List with month picker, filters, pagination, edit/delete
- **Add Expense** — Manual entry with category, amount, date, description
- **Receipt Scan** — Upload a receipt; OCR (Tesseract) suggests amount, date, and category
- **Reports** — Per-month CSV/PDF download; bulk ZIP export
- **Settings** — Currency, monthly budget, fixed expense templates, change password

---

## 🛠️ Tech Stack

- **Frontend:** Laravel 12, Blade, PHP 8.2+
- **Backend:** FastAPI, Python 3.11+, SQLAlchemy, Pydantic
- **Database:** MySQL
- **OCR:** Tesseract (`eng+tur`)
- **Deployment:** Railway

---

## 📁 Repository Structure

```
ai-powered-personal-finance-tracker/
├── backend/                    # FastAPI service
│   ├── .env.example
│   ├── requirements.txt
│   ├── Dockerfile
│   └── app/
│       ├── routers/            # auth, expenses, categories, receipts
│       ├── services/           # receipt_ocr.py
│       └── models_db.py
├── laravel_app/                # Laravel web app
│   ├── .env.example
│   ├── resources/views/        # Blade UI
│   └── routes/web.php
└── README.md
```

---

## ⚙️ Local Setup

### Prerequisites

- PHP 8.2+, Composer
- Python 3.11+
- MySQL
- Tesseract OCR — [install guide](https://github.com/tesseract-ocr/tesseract)

### 1. MySQL

Create a database (e.g. `finance_tracker`) in phpMyAdmin or MySQL CLI.

### 2. Backend (FastAPI)

```bash
cd backend
python -m venv .venv

# Windows
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
copy .env.example .env
```

Edit `backend/.env`:

```env
MYSQL_HOST=127.0.0.1
MYSQL_PORT=3306
MYSQL_USER=root
MYSQL_PASSWORD=
MYSQL_DATABASE=finance_tracker
```

Start the API:

```bash
.\.venv\Scripts\python.exe -m uvicorn app.main:app --reload --host 127.0.0.1 --port 8001
```

### 3. Laravel (Web UI)

```bash
cd laravel_app
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Important `.env` values:

```env
FASTAPI_URL=http://127.0.0.1:8001
DB_CONNECTION=sqlite
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000)

---

## 🚢 Deployment (Railway)

This project is deployed on [Railway](https://railway.app) with 3 services:

1. **MySQL** — Railway MySQL plugin
2. **FastAPI** — Dockerfile build with Tesseract OCR
3. **Laravel** — PHP service connected to FastAPI via `FASTAPI_URL`

---

## 📄 License

This project is provided as-is for academic and portfolio use.