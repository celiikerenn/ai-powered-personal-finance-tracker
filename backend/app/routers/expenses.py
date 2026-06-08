"""
Harcama CRUD ve aylık özet API endpoint'leri.
Laravel, oturumdaki user_id ile istek atar.
"""
from datetime import date
from decimal import Decimal

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session, joinedload
from sqlalchemy import func, extract

from app.database import get_db
from app.models_db import Expense, ExpenseCategory, User
from app.schemas import (
    AnomalyCheckResponse,
    ExpenseCreate,
    ExpenseUpdate,
    ExpenseResponse,
    ExpenseListResponse,
    MonthlyTotalResponse,
)
from app.services.expense_analytics import (
    compute_spending_anomaly,
    category_spending_for_month,
)
from app.services.report_charts import build_report_chart_images

router = APIRouter(prefix="/expenses", tags=["expenses"])


def expense_to_response(exp: Expense) -> ExpenseResponse:
    """ORM Expense -> API response."""
    return ExpenseResponse(
        expense_id=exp.expense_id,
        user_id=exp.user_id,
        category_id=exp.category_id,
        category_name=exp.category.name if exp.category else "",
        amount=exp.amount,
        description=exp.description,
        receipt_image_path=exp.receipt_image_path,
        expense_date=exp.expense_date,
        created_at=exp.created_at,
    )


@router.post("", response_model=ExpenseResponse)
def create_expense(data: ExpenseCreate, db: Session = Depends(get_db)):
    """
    Yeni harcama ekler.
    Laravel, giriş yapmış kullanıcının id'sini user_id olarak gönderir.
    """
    try:
        # Kategori var mı kontrol
        if data.expense_date > date.today():
            raise HTTPException(status_code=400, detail="Expense date cannot be in the future.")

        category = db.query(ExpenseCategory).filter(ExpenseCategory.category_id == data.category_id).first()
        if not category:
            raise HTTPException(status_code=400, detail="Invalid category id.")

        user = db.query(User).filter(User.user_id == data.user_id).first()
        if not user:
            raise HTTPException(status_code=400, detail="Invalid user id. Please login/register again.")

        expense = Expense(
            user_id=data.user_id,
            category_id=data.category_id,
            amount=data.amount,
            description=data.description,
            receipt_image_path=data.receipt_image_path,
            expense_date=data.expense_date,
        )
        # Response için category ilişkisini hazır tut
        expense.category = category
        db.add(expense)
        db.commit()
        db.refresh(expense)
        return expense_to_response(expense)
    except HTTPException:
        raise
    except Exception as e:
        db.rollback()
        err_msg = str(e)
        if hasattr(e, "orig"):
            err_msg = str(e.orig)
        elif hasattr(e, "__cause__") and e.__cause__:
            err_msg = str(e.__cause__)
        raise HTTPException(status_code=503, detail=f"Veritabanı hatası: {err_msg}") from e


@router.get("", response_model=ExpenseListResponse)
def list_expenses_by_user(
    user_id: int = Query(..., description="Laravel'den gelen kullanıcı id"),
    skip: int = Query(0, ge=0),
    limit: int = Query(50, ge=1, le=200),
    db: Session = Depends(get_db),
):
    """
    Kullanıcıya ait harcamaları listeler (tarih sırasına göre, en yeni önce).
    """
    query = (
        db.query(Expense)
        .options(joinedload(Expense.category))
        .filter(Expense.user_id == user_id)
        .order_by(Expense.expense_date.desc(), Expense.created_at.desc())
    )
    total = query.count()
    expenses = query.offset(skip).limit(limit).all()
    return ExpenseListResponse(
        expenses=[expense_to_response(e) for e in expenses],
        total=total,
    )

@router.get("/check-anomalies", response_model=AnomalyCheckResponse)
def check_anomalies(
    user_id: int = Query(...),
    mark_notified: bool = Query(False, description="Set true after email sent"),
    db: Session = Depends(get_db),
):
    if user_id < 1:
        raise HTTPException(status_code=400, detail="Invalid user id.")

    user = db.query(User).filter(User.user_id == user_id).first()
    if not user:
        raise HTTPException(status_code=404, detail="User not found.")

    result = compute_spending_anomaly(db, user_id)
    month = result["month"]
    already = user.anomaly_last_notified_month == month
    should_notify = (
        bool(user.email_notifications)
        and result["has_anomalies"]
        and not already
    )

    if mark_notified and should_notify:
        user.anomaly_last_notified_month = month
        db.add(user)
        db.commit()

    return AnomalyCheckResponse(
        month=month,
        has_anomalies=result["has_anomalies"],
        current_month_total=result["current_month_total"],
        baseline_average=result["baseline_average"],
        increase_percent=result["increase_percent"],
        should_notify=should_notify,
        already_notified=already,
    )


@router.get("/report-chart-images")
def get_report_chart_images(
    user_id: int = Query(...),
    year: int = Query(..., ge=2000, le=2100),
    month: int = Query(..., ge=1, le=12),
    db: Session = Depends(get_db),
):
    if user_id < 1:
        raise HTTPException(status_code=400, detail="Invalid user id.")
    cat_totals = category_spending_for_month(db, user_id, year, month)
    return build_report_chart_images(cat_totals)


@router.get("/monthly-total", response_model=MonthlyTotalResponse)
def get_monthly_total(
    user_id: int = Query(..., description="Kullanıcı id"),
    year: int = Query(..., ge=2000, le=2100),
    month: int = Query(..., ge=1, le=12),
    db: Session = Depends(get_db),
):
    """
    Belirtilen ay için kullanıcının toplam harcamasını ve adetini döner.
    Not: Bu endpoint dinamik /{expense_id} rotasından ÖNCE tanımlanmalıdır.
    """
    result = (
        db.query(
            func.coalesce(func.sum(Expense.amount), 0).label("total_amount"),
            func.count(Expense.expense_id).label("expense_count"),
        )
        .filter(Expense.user_id == user_id)
        .filter(extract("year", Expense.expense_date) == year)
        .filter(extract("month", Expense.expense_date) == month)
        .first()
    )
    total_amount = Decimal(str(result.total_amount)) if result else Decimal("0")
    count = result.expense_count if result else 0
    return MonthlyTotalResponse(
        user_id=user_id,
        year=year,
        month=month,
        total_amount=total_amount,
        expense_count=count,
    )

@router.get("/{expense_id}", response_model=ExpenseResponse)
def get_expense(
    expense_id: int,
    user_id: int = Query(..., description="Kullanıcı id (Laravel session)"),
    db: Session = Depends(get_db),
):
    """Tek bir harcamayı döner (kullanıcıya ait olmalı)."""
    exp = (
        db.query(Expense)
        .options(joinedload(Expense.category))
        .filter(Expense.expense_id == expense_id, Expense.user_id == user_id)
        .first()
    )
    if not exp:
        raise HTTPException(status_code=404, detail="Expense not found.")
    return expense_to_response(exp)


@router.put("/{expense_id}", response_model=ExpenseResponse)
def update_expense(
    expense_id: int,
    data: ExpenseUpdate,
    user_id: int = Query(..., description="Kullanıcı id (Laravel session)"),
    db: Session = Depends(get_db),
):
    """Harcama günceller (kullanıcıya ait olmalı)."""
    exp = db.query(Expense).filter(Expense.expense_id == expense_id, Expense.user_id == user_id).first()
    if not exp:
        raise HTTPException(status_code=404, detail="Expense not found.")

    if data.category_id is not None:
        category = db.query(ExpenseCategory).filter(ExpenseCategory.category_id == data.category_id).first()
        if not category:
            raise HTTPException(status_code=400, detail="Invalid category id.")
        exp.category_id = data.category_id
        exp.category = category

    if data.amount is not None:
        exp.amount = data.amount
    if data.description is not None:
        exp.description = data.description
    if data.receipt_image_path is not None:
        exp.receipt_image_path = data.receipt_image_path
    if data.expense_date is not None:
        if data.expense_date > date.today():
            raise HTTPException(status_code=400, detail="Expense date cannot be in the future.")
        exp.expense_date = data.expense_date

    db.commit()
    db.refresh(exp)
    # category lazy-load olmasın
    exp = (
        db.query(Expense)
        .options(joinedload(Expense.category))
        .filter(Expense.expense_id == expense_id, Expense.user_id == user_id)
        .first()
    )
    return expense_to_response(exp)


@router.delete("/{expense_id}")
def delete_expense(
    expense_id: int,
    user_id: int = Query(..., description="Kullanıcı id (Laravel session)"),
    db: Session = Depends(get_db),
):
    """Harcama siler (kullanıcıya ait olmalı)."""
    exp = db.query(Expense).filter(Expense.expense_id == expense_id, Expense.user_id == user_id).first()
    if not exp:
        raise HTTPException(status_code=404, detail="Expense not found.")
    db.delete(exp)
    db.commit()
    return {"status": "ok", "deleted_expense_id": expense_id}
