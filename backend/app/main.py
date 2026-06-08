"""
Personal Finance Tracker - FastAPI Service Layer
Tüm iş mantığı ve MySQL erişimi bu katmanda.
Laravel sadece HTTP istekleri ile bu API'yi kullanır.
"""
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.routers import expenses, categories, auth, receipts
from app.database import Base, engine, SessionLocal
import app.models_db  # noqa: F401  (Base metadata'ya modelleri kaydetmek için)

app = FastAPI(
    title="Personal Finance Tracker API",
    description="Harcama CRUD ve aylık özet. Laravel web katmanı bu API'yi kullanır.",
    version="1.0.0",
)

# Laravel farklı portta çalışacağı için CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://127.0.0.1:8000", "http://localhost:8000"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(expenses.router, prefix="/api")
app.include_router(categories.router, prefix="/api")
app.include_router(auth.router, prefix="/api")
app.include_router(receipts.router, prefix="/api")
DEFAULT_CATEGORIES = [
    "Food",
    "Transport",
    "Rent",
    "Utilities",
    "Groceries",
    "Health",
    "Education",
    "Entertainment",
    "Clothing",
    "Other",
]

# Eski Türkçe isimler → İngilizce (tek seferlik taşıma / birleştirme)
CATEGORY_TR_TO_EN = {
    "Yemek": "Food",
    "Ulaşım": "Transport",
    "Kira": "Rent",
    "Faturalar": "Utilities",
    "Market": "Groceries",
    "Sağlık": "Health",
    "Eğitim": "Education",
    "Eğlence": "Entertainment",
    "Giyim": "Clothing",
    "Diğer": "Other",
}


def _migrate_category_names_tr_to_en(db) -> None:
    """Türkçe kategori satırlarını İngilizce adlara taşır; hedef ad zaten varsa harcamalar birleştirilir."""
    from app.models_db import Expense, ExpenseCategory

    for tr_name, en_name in CATEGORY_TR_TO_EN.items():
        old_cat = db.query(ExpenseCategory).filter(ExpenseCategory.name == tr_name).first()
        if old_cat is None:
            continue
        en_cat = db.query(ExpenseCategory).filter(ExpenseCategory.name == en_name).first()
        if en_cat is None:
            old_cat.name = en_name
        elif en_cat.category_id != old_cat.category_id:
            db.query(Expense).filter(Expense.category_id == old_cat.category_id).update(
                {Expense.category_id: en_cat.category_id},
                synchronize_session=False,
            )
            db.delete(old_cat)
    db.commit()


def _ensure_receipt_image_column() -> None:
    """Add receipt_image_path to expenses when upgrading an existing database."""
    from sqlalchemy import inspect, text

    insp = inspect(engine)
    if "expenses" not in insp.get_table_names():
        return
    cols = {c["name"] for c in insp.get_columns("expenses")}
    if "receipt_image_path" in cols:
        return
    with engine.connect() as conn:
        conn.execute(
            text("ALTER TABLE expenses ADD COLUMN receipt_image_path VARCHAR(512) NULL")
        )
        conn.commit()


def _migrate_pk_column_names() -> None:
    """Rename generic id PK columns to descriptive names (users.user_id, etc.)."""
    from sqlalchemy import inspect, text

    insp = inspect(engine)
    if "users" not in insp.get_table_names():
        return

    user_cols = {c["name"] for c in insp.get_columns("users")}
    if "user_id" in user_cols and "id" not in user_cols:
        return
    if "id" not in user_cols:
        return

    def _drop_foreign_keys(conn, table: str) -> None:
        rows = conn.execute(
            text(
                """
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND REFERENCED_TABLE_NAME IS NOT NULL
                """
            ),
            {"table_name": table},
        ).fetchall()
        for (constraint_name,) in rows:
            conn.execute(text(f"ALTER TABLE `{table}` DROP FOREIGN KEY `{constraint_name}`"))

    with engine.connect() as conn:
        _drop_foreign_keys(conn, "expenses")
        if "receipt_merchant_memories" in insp.get_table_names():
            _drop_foreign_keys(conn, "receipt_merchant_memories")

        conn.execute(
            text("ALTER TABLE users CHANGE COLUMN id user_id INT NOT NULL AUTO_INCREMENT")
        )
        conn.execute(
            text(
                "ALTER TABLE expense_categories CHANGE COLUMN id category_id INT NOT NULL AUTO_INCREMENT"
            )
        )
        conn.execute(
            text("ALTER TABLE expenses CHANGE COLUMN id expense_id INT NOT NULL AUTO_INCREMENT")
        )
        if "receipt_merchant_memories" in insp.get_table_names():
            memory_cols = {c["name"] for c in insp.get_columns("receipt_merchant_memories")}
            if "id" in memory_cols:
                conn.execute(
                    text(
                        "ALTER TABLE receipt_merchant_memories "
                        "CHANGE COLUMN id memory_id INT NOT NULL AUTO_INCREMENT"
                    )
                )

        conn.execute(
            text(
                """
                ALTER TABLE expenses
                ADD CONSTRAINT fk_expenses_user
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
                """
            )
        )
        conn.execute(
            text(
                """
                ALTER TABLE expenses
                ADD CONSTRAINT fk_expenses_category
                FOREIGN KEY (category_id) REFERENCES expense_categories(category_id) ON DELETE RESTRICT
                """
            )
        )
        if "receipt_merchant_memories" in insp.get_table_names():
            conn.execute(
                text(
                    """
                    ALTER TABLE receipt_merchant_memories
                    ADD CONSTRAINT fk_receipt_memory_user
                    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
                    """
                )
            )
        conn.commit()


def _ensure_user_notification_columns() -> None:
    from sqlalchemy import inspect, text

    insp = inspect(engine)
    if "users" not in insp.get_table_names():
        return
    cols = {c["name"] for c in insp.get_columns("users")}
    alters = []
    if "email_notifications" not in cols:
        alters.append("ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1")
    if "anomaly_last_notified_month" not in cols:
        alters.append("ADD COLUMN anomaly_last_notified_month VARCHAR(7) NULL")
    if not alters:
        return
    with engine.connect() as conn:
        for clause in alters:
            conn.execute(text(f"ALTER TABLE users {clause}"))
        conn.commit()


@app.on_event("startup")
def startup_init_db():
    """
    Uygulama açılışında MySQL tablolarını oluşturur (yoksa) ve varsayılan kategorileri ekler.
    Alembic/migration kullanılmayan basit kurulum senaryosu için.
    """
    Base.metadata.create_all(bind=engine)
    _migrate_pk_column_names()
    _ensure_receipt_image_column()
    _ensure_user_notification_columns()

    db = SessionLocal()
    try:
        _migrate_category_names_tr_to_en(db)
        _ensure_default_categories(db)
    finally:
        db.close()


def _ensure_default_categories(db) -> None:
    """Varsayılan kategori adları eksikse ekler (tablo boşalsa veya yanlışlıkla silinse bile)."""
    from app.models_db import ExpenseCategory

    for name in DEFAULT_CATEGORIES:
        exists = db.query(ExpenseCategory).filter(ExpenseCategory.name == name).first()
        if exists is None:
            db.add(ExpenseCategory(name=name))
    db.commit()


@app.get("/")
def root():
    return {"service": "Finance Tracker API", "docs": "/docs"}


@app.get("/api/health")
def health():
    return {"status": "ok"}


@app.get("/api/health/db")
def health_db():
    """
    MySQL bağlantısını dener. Hata varsa gerçek hata mesajını döner (sorun tespiti için).
    Tarayıcıda http://127.0.0.1:8001/api/health/db açarak kontrol edin.
    """
    try:
        from sqlalchemy import text
        from app.database import engine
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
        return {"status": "ok", "database": "connected"}
    except Exception as e:
        return {
            "status": "error",
            "database": "failed",
            "message": str(e),
        }
