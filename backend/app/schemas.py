"""
Pydantic şemaları - API istek/cevap modelleri.
"""
from datetime import date, datetime
from decimal import Decimal
from typing import Optional

from pydantic import BaseModel, Field


# ---------- Auth ----------
class UserRegister(BaseModel):
    """Kayıt isteği."""
    name: str = Field(..., min_length=1, max_length=255)
    email: str = Field(..., max_length=255)
    password: str = Field(..., min_length=6)
    role: str = Field("user", pattern="^(admin|user)$")


class UserLogin(BaseModel):
    """Giriş isteği."""
    email: str
    password: str


class UserResponse(BaseModel):
    """Giriş/kayıt sonrası kullanıcı bilgisi (şifre gönderilmez)."""
    id: int
    name: str
    email: str
    role: str
    monthly_budget: Optional[Decimal] = None

    class Config:
        from_attributes = True


# ---------- Expense ----------
class ExpenseCreate(BaseModel):
    """Harcama oluşturma isteği (Laravel'den gönderilir)."""
    user_id: int = Field(..., description="Laravel oturumundaki kullanıcı id")
    category_id: int = Field(..., description="expense_categories tablosundaki kategori id")
    amount: Decimal = Field(..., gt=0, description="Harcama tutarı")
    description: Optional[str] = Field(None, max_length=2000)
    expense_date: date = Field(..., description="Harcamanın yapıldığı tarih")


class ExpenseUpdate(BaseModel):
    """Harcama güncelleme (opsiyonel - ileride eklenebilir)."""
    category_id: Optional[int] = None
    amount: Optional[Decimal] = None
    description: Optional[str] = None
    expense_date: Optional[date] = None


class ExpenseResponse(BaseModel):
    """Tek harcama cevabı."""
    id: int
    user_id: int
    category_id: int
    category_name: str
    amount: Decimal
    description: Optional[str]
    expense_date: date
    created_at: datetime

    class Config:
        from_attributes = True


class ExpenseListResponse(BaseModel):
    """Kullanıcının harcama listesi cevabı."""
    expenses: list[ExpenseResponse]
    total: int


# ---------- Monthly Summary ----------
class MonthlyTotalResponse(BaseModel):
    """Aylık toplam cevabı."""
    user_id: int
    year: int
    month: int
    total_amount: Decimal
    expense_count: int


# ---------- Categories ----------
class CategoryResponse(BaseModel):
    """Kategori listesi (dropdown için)."""
    id: int
    name: str

    class Config:
        from_attributes = True
