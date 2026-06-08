"""
Kayıt ve giriş - Laravel veritabanına erişmediği için auth FastAPI'de.
Laravel formu gönderir, FastAPI user oluşturur/doğrular, Laravel oturum açar.
"""
import base64
import hashlib
import hmac
import os

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from app.database import get_db
from app.models_db import User
from app.schemas import UserRegister, UserLogin, UserResponse

router = APIRouter(prefix="/auth", tags=["auth"])

PBKDF2_ITERATIONS = 210_000
PBKDF2_SALT_BYTES = 16


def hash_password(password: str) -> str:
    """Şifreyi hash'li sakla (plaintext saklama)."""
    pwd = str(password)
    salt = os.urandom(PBKDF2_SALT_BYTES)
    dk = hashlib.pbkdf2_hmac("sha256", pwd.encode("utf-8"), salt, PBKDF2_ITERATIONS)
    # Format: pbkdf2_sha256$<iterations>$<salt_b64>$<hash_b64>
    return (
        "pbkdf2_sha256$"
        f"{PBKDF2_ITERATIONS}$"
        f"{base64.b64encode(salt).decode('ascii')}$"
        f"{base64.b64encode(dk).decode('ascii')}"
    )


def verify_password(plain: str, hashed: str) -> bool:
    """Şifre doğrulama (PBKDF2)."""
    try:
        algo, iterations_s, salt_b64, hash_b64 = str(hashed).split("$", 3)
        if algo != "pbkdf2_sha256":
            return False
        iterations = int(iterations_s)
        salt = base64.b64decode(salt_b64.encode("ascii"))
        expected = base64.b64decode(hash_b64.encode("ascii"))
        dk = hashlib.pbkdf2_hmac("sha256", str(plain).encode("utf-8"), salt, iterations)
        return hmac.compare_digest(dk, expected)
    except Exception:
        return False


@router.post("/register", response_model=UserResponse)
def register(data: UserRegister, db: Session = Depends(get_db)):
    """Yeni kullanıcı kaydı. Laravel register formundan çağrılır."""
    try:
        if db.query(User).filter(User.email == data.email).first():
            raise HTTPException(status_code=400, detail="This email is already registered.")

        hashed = hash_password(data.password)
        user = User(
            name=data.name,
            email=data.email,
            password=hashed,
            role=data.role,
        )
        db.add(user)
        db.commit()
        db.refresh(user)
        return UserResponse(
            user_id=user.user_id,
            name=user.name,
            email=user.email,
            role=str(user.role),
            monthly_budget=user.monthly_budget,
            email_notifications=bool(getattr(user, "email_notifications", True)),
        )
    except HTTPException:
        raise
    except Exception as e:
        db.rollback()
        # Tüm hataları yakala ve gerçek mesajı göster
        err_msg = str(e)
        if hasattr(e, "orig"):
            err_msg = str(e.orig)
        elif hasattr(e, "__cause__") and e.__cause__:
            err_msg = str(e.__cause__)
        raise HTTPException(status_code=503, detail=f"Veritabanı hatası: {err_msg}") from e


@router.post("/login", response_model=UserResponse)
def login(data: UserLogin, db: Session = Depends(get_db)):
    """E-posta/şifre ile giriş. Başarılıysa kullanıcı bilgisi döner; Laravel session'a yazar."""
    user = db.query(User).filter(User.email == data.email).first()
    if not user or not verify_password(data.password, user.password):
        raise HTTPException(status_code=401, detail="Invalid email or password.")
    return UserResponse(
        user_id=user.user_id,
        name=user.name,
        email=user.email,
        role=str(user.role),
        monthly_budget=user.monthly_budget,
        email_notifications=bool(getattr(user, "email_notifications", True)),
    )


@router.get("/users-with-notifications")
def users_with_notifications(db: Session = Depends(get_db)):
    """Users opted in to email alerts (for Laravel scheduler)."""
    rows = db.query(User).filter(User.email_notifications.is_(True)).all()
    return [
        {"user_id": u.user_id, "email": u.email, "name": u.name}
        for u in rows
    ]


@router.post("/update-notification-settings")
def update_notification_settings(payload: dict, db: Session = Depends(get_db)):
    user_id = int(payload.get("user_id", 0) or 0)
    if user_id < 1:
        raise HTTPException(status_code=400, detail="Invalid user id.")

    user = db.query(User).filter(User.user_id == user_id).first()
    if not user:
        raise HTTPException(status_code=404, detail="User not found.")

    if "email_notifications" in payload:
        user.email_notifications = bool(payload.get("email_notifications"))

    db.add(user)
    db.commit()
    db.refresh(user)

    return {
        "user_id": user.user_id,
        "email_notifications": bool(user.email_notifications),
    }


@router.post("/update-budget")
def update_budget(
    payload: dict,
    db: Session = Depends(get_db),
):
    """
    Kullanıcının aylık bütçesini günceller.
    Laravel Profile & Settings sayfasından çağrılır.
    """
    user_id = int(payload.get("user_id", 0) or 0)
    monthly_budget_raw = payload.get("monthly_budget", 0)

    try:
        monthly_budget = float(monthly_budget_raw or 0)
    except (TypeError, ValueError):
        raise HTTPException(status_code=400, detail="monthly_budget must be a number.")

    if user_id <= 0:
        raise HTTPException(status_code=400, detail="Invalid user id.")
    if monthly_budget < 0:
        raise HTTPException(status_code=400, detail="monthly_budget cannot be negative.")

    user = db.query(User).filter(User.user_id == user_id).first()
    if not user:
        raise HTTPException(status_code=404, detail="User not found.")

    user.monthly_budget = monthly_budget
    db.add(user)
    db.commit()
    db.refresh(user)

    return {
        "user_id": user.user_id,
        "name": user.name,
        "email": user.email,
        "role": str(user.role),
        "monthly_budget": float(user.monthly_budget or 0),
    }


@router.post("/change-password")
def change_password(
    payload: dict,
    db: Session = Depends(get_db),
):
    """
    Kullanıcının şifresini günceller.
    Laravel Profile & Settings sayfasındaki Change Password formundan çağrılır.
    """
    user_id_raw = payload.get("user_id", 0)
    current_password = str(payload.get("current_password") or "")
    new_password = str(payload.get("new_password") or "")

    try:
        user_id = int(user_id_raw or 0)
    except (TypeError, ValueError):
        user_id = 0

    if user_id <= 0:
        raise HTTPException(status_code=400, detail="Invalid user id.")
    if not current_password or not new_password:
        raise HTTPException(status_code=400, detail="Both current and new passwords are required.")

    user = db.query(User).filter(User.user_id == user_id).first()
    if not user:
        raise HTTPException(status_code=404, detail="User not found.")

    if not verify_password(current_password, user.password):
        raise HTTPException(status_code=400, detail="Current password is incorrect.")

    user.password = hash_password(new_password)
    db.add(user)
    db.commit()
    db.refresh(user)

    return UserResponse(user_id=user.user_id, name=user.name, email=user.email, role=str(user.role))
