"""
Kategori listesi - Add Expense formundaki dropdown için.
"""
from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.database import get_db
from app.models_db import ExpenseCategory
from app.schemas import CategoryResponse

router = APIRouter(prefix="/categories", tags=["categories"])


@router.get("", response_model=list[CategoryResponse])
def list_categories(db: Session = Depends(get_db)):
    """Tüm harcama kategorilerini döner (category_id, name)."""
    categories = db.query(ExpenseCategory).order_by(ExpenseCategory.category_id.asc()).all()
    return [CategoryResponse(category_id=c.category_id, name=c.name) for c in categories]
