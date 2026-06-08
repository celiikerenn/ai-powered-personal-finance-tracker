"""
Per-user receipt merchant/category memory.
When the user saves a scanned receipt, keywords are stored; future scans reuse the category.
"""
from __future__ import annotations

import re
from typing import Optional

from sqlalchemy.orm import Session

from app.models_db import ExpenseCategory, ReceiptMerchantMemory

_STOPWORDS = frozenset(
    {
        "ve",
        "ile",
        "the",
        "and",
        "ltd",
        "sti",
        "as",
        "tic",
        "san",
        "sube",
        "şube",
        "merkez",
        "fatura",
        "receipt",
        "fis",
        "fiş",
        "tarih",
        "saat",
        "tel",
        "fax",
        "vergi",
        "vd",
        "no",
        "toplam",
        "tutar",
        "kdv",
        "nakit",
        "kredi",
    }
)

_BUSINESS_TRIGGERS: tuple[tuple[str, str], ...] = (
    ("eczane", "Health"),
    ("eczanesi", "Health"),
    ("ecz.", "Health"),
    ("pharmacy", "Health"),
    ("hastane", "Health"),
    ("hospital", "Health"),
    ("kebap", "Food"),
    ("kebab", "Food"),
    ("doner", "Food"),
    ("döner", "Food"),
    ("lahmacun", "Food"),
    ("pide", "Food"),
    ("restoran", "Food"),
    ("restaurant", "Food"),
    ("cafe", "Food"),
    ("kahve", "Food"),
    ("pastane", "Food"),
    ("firin", "Food"),
    ("fırın", "Food"),
    ("unlu mamul", "Food"),
    ("unlu mamuller", "Food"),
    ("baklava", "Food"),
    ("sut mamul", "Food"),
    ("sut mamulleri", "Food"),
    ("tatli", "Food"),
    ("chocolate", "Food"),
    ("cikolata", "Food"),
    ("sarayi", "Food"),
    ("yiyecek", "Food"),
    ("hamburger", "Food"),
    ("burger", "Food"),
    ("kizilkayalar", "Food"),
    ("bufe", "Food"),
    ("migros", "Groceries"),
    ("bim", "Groceries"),
    ("a101", "Groceries"),
    ("carrefour", "Groceries"),
    ("carrefoursa", "Groceries"),
    ("parrefour", "Groceries"),
    ("market", "Groceries"),
    ("ispark", "Transport"),
    ("otopark", "Transport"),
    ("shell", "Transport"),
    ("opet", "Transport"),
    ("benzin", "Transport"),
    ("motorin", "Transport"),
)

# Şirket unvanı / yanlış öğrenme — hafızaya yazılmaz, eşleşmede Transport zorlanmaz
_BLOCKED_MEMORY_KEYWORDS = frozenset(
    {
        "petrol",
        "oto",
        "benzin",
        "motorin",
        "akaryakit",
        "urunleri",
        "urun",
        "eksen",
        "san",
        "tic",
        "ltd",
        "sti",
        "gida",
        "ins",
        "kuy",
        "spbanci",
        "sabanci",
        "ziraat",
        "garanti",
        "bankasi",
        "bankas",
        "bank",
        "isyeri",
        "pos",
        "provizyon",
        "tek",
        "mrkezi",
    }
)

_FOOD_CHAIN_TRIGGERS = frozenset(
    {
        "hamburger",
        "burger",
        "kizilkayalar",
        "bufe",
        "kebap",
        "doner",
        "lahmacun",
        "pide",
        "restoran",
        "lokanta",
    }
)

_GROCERY_CHAIN_TRIGGERS = frozenset(
    {
        "migros",
        "bim",
        "a101",
        "carrefour",
        "carrefoursa",
        "parrefour",
        "hakmar",
        "makro",
        "sok",
        "kiler",
    }
)


def normalize_haystack(text: str) -> str:
    t = (text or "").lower()
    t = t.replace("ı", "i").replace("İ", "i").replace("ş", "s").replace("ğ", "g")
    t = t.replace("ö", "o").replace("ü", "u").replace("ç", "c")
    t = re.sub(r"\bparrefour\b", "carrefour", t)
    t = re.sub(r"\bcarrefoursa\b", "carrefour", t)
    t = re.sub(r"\s+", " ", t)
    return t.strip()


def _normalize_keyword(text: str) -> Optional[str]:
    kw = normalize_haystack(text)
    kw = re.sub(r"[^a-z0-9\s]", " ", kw)
    kw = re.sub(r"\s+", " ", kw).strip()
    if len(kw) < 3:
        return None
    return kw[:120]


def _token_keywords(text: str, *, min_len: int = 4) -> list[str]:
    norm = _normalize_keyword(text) or ""
    if not norm:
        return []
    out: list[str] = []
    for word in norm.split():
        if word in _STOPWORDS or len(word) < min_len:
            continue
        if word not in out:
            out.append(word)
    return out


def extract_learn_keywords(
    description: Optional[str],
    raw_text: str,
    merchant: Optional[str] = None,
) -> list[str]:
    """Build keyword list to store for this receipt."""
    keywords: list[str] = []
    seen: set[str] = set()

    def add(raw: Optional[str]) -> None:
        if not raw:
            return
        kw = _normalize_keyword(raw)
        if kw and kw not in seen:
            seen.add(kw)
            keywords.append(kw)

    desc = (description or "").strip()
    if len(desc) >= 3:
        add(desc)
        for token in _token_keywords(desc, min_len=3):
            if token not in seen:
                seen.add(token)
                keywords.append(token)

    if merchant and merchant.strip():
        add(merchant.strip())
        for token in _token_keywords(merchant, min_len=3):
            if token not in seen:
                seen.add(token)
                keywords.append(token)

    hay = normalize_haystack(raw_text)
    for trigger, _cat in _BUSINESS_TRIGGERS:
        tn = normalize_haystack(trigger)
        if not tn or tn in _BLOCKED_MEMORY_KEYWORDS:
            continue
        if tn in hay and tn not in seen:
            seen.add(tn)
            keywords.append(tn)

    for line in (raw_text or "").splitlines()[:3]:
        line = line.strip()
        if 4 <= len(line) <= 60 and sum(c.isalpha() for c in line) >= len(line) * 0.4:
            if re.search(r"bank|ziraat|garanti|spbanci|sabanci|pos|isyeri", line, re.I):
                continue
            for token in _token_keywords(line, min_len=5):
                if token in _BLOCKED_MEMORY_KEYWORDS:
                    continue
                if token not in seen and len(keywords) < 12:
                    seen.add(token)
                    keywords.append(token)

    return keywords[:10]


def lookup_category(
    db: Session,
    user_id: int,
    raw_text: str,
    merchant: Optional[str] = None,
) -> Optional[str]:
    haystack = normalize_haystack(raw_text)
    if merchant:
        haystack = f"{haystack} {normalize_haystack(merchant)}"

    if not haystack.strip():
        return None

    for trigger in _FOOD_CHAIN_TRIGGERS:
        if trigger in haystack:
            return "Food"

    if _haystack_has_food_signal(haystack):
        return "Food"

    for trigger in _GROCERY_CHAIN_TRIGGERS:
        if trigger in haystack:
            return "Groceries"

    rules = (
        db.query(ReceiptMerchantMemory)
        .filter(ReceiptMerchantMemory.user_id == user_id)
        .order_by(ReceiptMerchantMemory.use_count.desc())
        .all()
    )
    if not rules:
        return None

    rules_sorted = sorted(rules, key=lambda r: len(r.keyword or ""), reverse=True)
    for rule in rules_sorted:
        kw = (rule.keyword or "").strip()
        if len(kw) < 3:
            continue
        if kw in _BLOCKED_MEMORY_KEYWORDS:
            continue
        if kw in haystack:
            if rule.category_name == "Transport" and _haystack_has_food_signal(haystack):
                continue
            if rule.category_name == "Food" and _haystack_has_grocery_signal(haystack):
                continue
            if rule.category_name == "Groceries" and _haystack_has_food_signal(haystack):
                continue
            if rule.category_name != "Health" and _haystack_has_health_signal(haystack):
                continue
            return rule.category_name
    return None


def _haystack_has_grocery_signal(haystack: str) -> bool:
    if any(t in haystack for t in _GROCERY_CHAIN_TRIGGERS):
        return True
    if re.search(r"carrefoursa\s+kart|csa\s+kart", haystack):
        return True
    if re.search(r"parrefour|carrefour", haystack):
        return True
    kg = re.search(r"\d+[.,]?\d*\s*kg\b", haystack)
    if kg and re.search(r"\b(?:un|ceviz|deterjan|sut)\b", haystack):
        return True
    return False


def _haystack_has_food_signal(haystack: str) -> bool:
    return bool(
        re.search(
            r"kebap|kebab|restoran|rest\.?\s*hiz|lokanta|yiy?ecek|icecek|pastane|firin|"
            r"pide|doner|döner|chocolate|cikolata|sarayi|"
            r"sut mamul|tatli|dondurma|hamburger|burger|kizilkayalar|bufe|bistro",
            haystack,
        )
    )


def _haystack_has_health_signal(haystack: str) -> bool:
    return bool(
        re.search(
            r"eczane|eczanesi|pharmacy|hastane|hospital|klinik|ecz\.?\s*[a-z]{2,}",
            haystack,
        )
    )


def learn_from_receipt(
    db: Session,
    user_id: int,
    category_id: int,
    description: Optional[str],
    raw_text: str,
) -> int:
    category = db.query(ExpenseCategory).filter(ExpenseCategory.category_id == category_id).first()
    if not category:
        return 0

    category_name = category.name
    keywords = extract_learn_keywords(description, raw_text)
    if not keywords:
        return 0

    saved = 0
    for kw in keywords:
        if kw in _BLOCKED_MEMORY_KEYWORDS:
            continue
        row = (
            db.query(ReceiptMerchantMemory)
            .filter(
                ReceiptMerchantMemory.user_id == user_id,
                ReceiptMerchantMemory.keyword == kw,
            )
            .first()
        )
        if row:
            if row.category_name != category_name:
                row.category_name = category_name
            row.use_count = (row.use_count or 0) + 1
        else:
            db.add(
                ReceiptMerchantMemory(
                    user_id=user_id,
                    keyword=kw,
                    category_name=category_name,
                    use_count=1,
                )
            )
        saved += 1
    db.commit()
    return saved
