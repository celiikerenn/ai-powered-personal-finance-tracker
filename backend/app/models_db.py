"""
SQLAlchemy ORM modelleri - MySQL tablolarıyla eşleşir.
"""
from sqlalchemy import Boolean, Column, Integer, String, Text, Date, DateTime, Enum, DECIMAL, ForeignKey
from sqlalchemy.orm import relationship
from sqlalchemy.sql import func

from app.database import Base


class User(Base):
    __tablename__ = "users"

    user_id = Column(Integer, primary_key=True, autoincrement=True)
    name = Column(String(255), nullable=False)
    email = Column(String(255), unique=True, nullable=False)
    password = Column(String(255), nullable=False)
    role = Column(Enum("admin", "user"), default="user", nullable=False)
    monthly_budget = Column(DECIMAL(12, 2), nullable=True)
    email_notifications = Column(Boolean, default=True, nullable=False, server_default="1")
    anomaly_last_notified_month = Column(String(7), nullable=True)
    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())

    expenses = relationship("Expense", back_populates="user")


class ExpenseCategory(Base):
    __tablename__ = "expense_categories"

    category_id = Column(Integer, primary_key=True, autoincrement=True)
    name = Column(String(100), unique=True, nullable=False)

    expenses = relationship("Expense", back_populates="category")


class ReceiptMerchantMemory(Base):
    """User-specific OCR hints: keyword on receipt text → category."""

    __tablename__ = "receipt_merchant_memories"

    memory_id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, ForeignKey("users.user_id", ondelete="CASCADE"), nullable=False, index=True)
    keyword = Column(String(120), nullable=False)
    category_name = Column(String(100), nullable=False)
    use_count = Column(Integer, default=1, nullable=False)
    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())


class Expense(Base):
    __tablename__ = "expenses"

    expense_id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(Integer, ForeignKey("users.user_id", ondelete="CASCADE"), nullable=False)
    category_id = Column(Integer, ForeignKey("expense_categories.category_id", ondelete="RESTRICT"), nullable=False)
    amount = Column(DECIMAL(12, 2), nullable=False)
    description = Column(Text, nullable=True)
    receipt_image_path = Column(String(512), nullable=True)
    expense_date = Column(Date, nullable=False)
    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())

    user = relationship("User", back_populates="expenses")
    category = relationship("ExpenseCategory", back_populates="expenses")
