"""
Receipt image OCR and lightweight field extraction.
Requires Tesseract OCR installed on the host (see README).
"""
from __future__ import annotations

import re
from datetime import date, timedelta
from decimal import Decimal, InvalidOperation
from io import BytesIO
from typing import Optional

from PIL import Image, ImageOps

try:
    import pytesseract

    TESSERACT_AVAILABLE = True
except ImportError:
    pytesseract = None  # type: ignore
    TESSERACT_AVAILABLE = False


class OcrNotAvailableError(RuntimeError):
    pass


def _ensure_tesseract() -> None:
    if not TESSERACT_AVAILABLE:
        raise OcrNotAvailableError(
            "pytesseract is not installed. Run: pip install pytesseract Pillow"
        )
    try:
        pytesseract.get_tesseract_version()
    except Exception as exc:  # pragma: no cover
        raise OcrNotAvailableError(
            "Tesseract OCR is not installed or not on PATH. "
            "Install from https://github.com/tesseract-ocr/tesseract "
            "and ensure `tesseract` is available in your terminal."
        ) from exc


def _image_from_bytes(data: bytes) -> Image.Image:
    img = Image.open(BytesIO(data))
    img = ImageOps.exif_transpose(img)
    return img.convert("RGB")


def extract_text_from_image(data: bytes) -> str:
    _ensure_tesseract()
    img = _image_from_bytes(data)
    # Turkish + English receipts
    text = pytesseract.image_to_string(img, lang="eng+tur", config="--psm 6")
    if not text.strip():
        text = pytesseract.image_to_string(img, config="--psm 6")
    return text.strip()


def _token_has_decimal_mark(raw: str) -> bool:
    return bool(re.search(r"[.,]\d{2}\s*$", raw.strip()))


def _salvage_glued_amount(value: Decimal, raw_token: str) -> Decimal:
    """120677 OCR (virgül yok) → 120,67 gibi makul tutarlara çevir."""
    if _token_has_decimal_mark(raw_token):
        return value
    if value != value.to_integral_value():
        return value
    s = str(int(value))
    if len(s) == 5:
        return Decimal(f"{s[:-2]}.{s[-2:]}")
    if len(s) == 6:
        as_end = Decimal(f"{s[:-2]}.{s[-2:]}")
        as_mid = Decimal(f"{s[:3]}.{s[3:5]}")
        if as_mid < Decimal("50000"):
            return as_mid
        return as_end
    return value


def _normalize_amount_token(raw: str) -> Optional[Decimal]:
    """Parse Turkish (1.234,56) and dotted-decimal (230.06) amount strings."""
    s = raw.strip()
    s = re.sub(r"\s*(?:TL|TRY|₺)\s*$", "", s, flags=re.IGNORECASE).strip()
    s = re.sub(r"\s+", "", s)
    if not s or not re.search(r"\d", s):
        return None

    if "," in s and "." in s:
        if s.rfind(",") > s.rfind("."):
            s = s.replace(".", "").replace(",", ".")
        else:
            s = s.replace(",", "")
    elif "," in s:
        if re.search(r",\d{2}$", s):
            s = s.replace(".", "").replace(",", ".")
        else:
            s = s.replace(",", "")
    elif "." in s:
        if re.search(r"\.\d{2}$", s) and s.count(".") == 1:
            pass
        elif s.count(".") > 1:
            s = s.replace(".", "")
        elif re.search(r"\.\d{3}$", s):
            s = s.replace(".", "")

    try:
        value = Decimal(s)
        if value <= 0:
            return None
        if not _token_has_decimal_mark(raw) and value >= Decimal("1000"):
            value = _salvage_glued_amount(value, raw)
        if value >= Decimal("500000"):
            return None
        return value
    except InvalidOperation:
        return None


# Do not treat space as thousands separator (OCR: "428 236,08" must not become 428236,08).
_AMOUNT_PATTERNS = (
    r"\d{1,3}(?:\.\d{3})+(?:,\d{2})",  # 1.234,56
    r"\d+[.,]\s*\d{2}",  # 230,00 or 597, 95 (OCR space before kuruş)
    r"(?<!\d)\d{2,5}(?!\d)",  # 230 without decimals (after * cleanup)
)

_TOTAL_LABEL_ON_LINE = re.compile(
    r"(?:^|\b)(?:TOP|TOPLAM|ÖDENECEK|ODENECEK|GENEL\s*TOPLAM)(?:\b|$)",
    re.IGNORECASE,
)

# Ödeme kırılımı — genel toplam değil (Nakit 120,67 + Kart 64,70 gibi)
_PARTIAL_PAYMENT_LINE = re.compile(
    r"(?:"
    r"NAK[Iİ]T|KRED[Iİ]|Kart|Karekod|TechPos|BKM|ÖDEME|ODEME|"
    r"Alinan\s*Tutar|Alınan\s*Tutar|Para\s*[UÜ]st[uü]|ÖDENEN|ODENEN"
    r")",
    re.IGNORECASE,
)

_CARD_SLIP_TOTAL_LINE = re.compile(
    r"(?:TEMASSIZ|TUTAR\s*KAR[SŞ]ILI[GD]I|SATI[SŞ]|MasterCard|Visa|ONAY\s*KODU)",
    re.IGNORECASE,
)

_GRAND_TOTAL_RE = re.compile(
    r"(?i)\bTOPLAM(?!\s*KDV)\b[^\d]{0,25}(\d{1,4}[.,]\s*\d{2})",
)

_PHARMACY_RECEIPT_RE = re.compile(
    r"(?:eczane|eczanesi|ecz\.|hkp\s*emek|emekli|ilac\s*fiyat|fiyat\s*fark)",
    re.IGNORECASE,
)

_CARD_PAYMENT_CONTEXT_RE = re.compile(
    r"(?:TechPos|BKM|Kart|Karekod|TEMASSIZ|MasterCard|Visa|ONAY\s*KODU|"
    r"SAT[IİŞ]|TUTAR\s*KAR)",
    re.IGNORECASE,
)

_PRODUCT_LINE = re.compile(
    r"(?:OTOPARK|LT\s*X|EXCELLIUM|MOTORIN|D[Iİ]ZEL|BENZIN|BENZİN)",
    re.IGNORECASE,
)

# VAT / subtotal lines — never use these for the expense total
_VAT_OR_SUBTOTAL_LINE = re.compile(
    r"(?:"
    r"TOPLAM\s*KDV|KDV\s*TOPLAM|TOP\s*KDV|TOPKDV|"
    r"KDV\s*TUTAR|KDV\s*MATRAH|"
    r"ARA\s*TOPLAM|"
    r"KDV\s*%|"
    r"^\s*KDV\b"
    r")",
    re.IGNORECASE,
)

_STRONG_TOTAL_LINE = re.compile(
    r"(?:GENEL\s*TOPLAM|ÖDENECEK|ODENECEK)",
    re.IGNORECASE,
)

_KDV_DAHIL_TOTAL_LINE = re.compile(
    r"KDV\s*DAH[Iİ]L",
    re.IGNORECASE,
)


def _clean_line_for_amounts(line: str) -> str:
    """Normalize Turkish receipt quirks before amount extraction."""
    line = re.sub(r"\*+", " ", line)
    # OCR: "597, 95" — space between comma and kuruş
    line = re.sub(r"(\d)([,.])\s+(\d{2})\b", r"\1\2\3", line)
    # OCR often reads leading * on amounts as digit 4 (TOP *230 -> TOP 4230).
    line = re.sub(
        r"(?i)((?:^|\b)(?:TOP|TOPLAM|TUTAR|NAK[Iİ]T|KRED[Iİ]|ÖDENECEK)\b\s*)4(\d{2,}(?:[.,]\d{2})?)",
        r"\1\2",
        line,
    )
    # Glued label+amount: TOPLAM284,94 or TOPLAM284008
    line = re.sub(
        r"(?i)\b(TOPLAM|TOP|TUTAR)\s*4?(\d{2,5})[,.;:]?(\d{2})\b",
        lambda m: f"{m.group(1)} {m.group(2)},{m.group(3)}",
        line,
    )
    return line


def _fix_leading_four_misread(value: Decimal, line: str) -> Decimal:
    """Drop spurious leading 4 when * was OCR'd as 4 on a total line."""
    if _is_vat_or_subtotal_line(line) or value < Decimal("400"):
        return value
    text = format(value, "f")
    if "." in text:
        whole, frac = text.split(".", 1)
    else:
        whole, frac = text, ""
    if not whole.startswith("4") or len(whole) < 3:
        return value
    alt_whole = whole[1:]
    if not alt_whole:
        return value
    try:
        alt = Decimal(f"{alt_whole}.{frac}" if frac else alt_whole)
    except InvalidOperation:
        return value
    if alt <= 0 or alt >= value:
        return value
    if alt < Decimal("1000000"):
        return alt
    return value


def _pick_line_amount(amounts: list[Decimal]) -> Decimal:
    """On a receipt line, the grand total is usually the largest monetary value."""
    return max(amounts)


def _parse_liters_token(raw: str) -> Optional[Decimal]:
    """Turkish fuel receipts use 3 decimal places for liters (e.g. 4,570 L)."""
    s = raw.strip().replace(" ", "")
    if re.fullmatch(r"\d+,\d{3}", s):
        return Decimal(s.replace(",", "."))
    return _normalize_amount_token(raw)


def _parse_fuel_line_total(line: str) -> Optional[Decimal]:
    """Fuel receipts: 4,570 LT X 62,35 → liters × unit price."""
    match = re.search(
        r"(\d+[.,]\d+)\s*(?:LT|Ll|L)\s*X\s*(\d+[.,]\d+)",
        line,
        re.IGNORECASE,
    )
    if not match:
        return None
    liters = _parse_liters_token(match.group(1))
    unit = _normalize_amount_token(match.group(2))
    if liters is None or unit is None:
        return None
    total = liters * unit
    return total if total > 0 else None


def _parse_glued_total_digits(line: str) -> Optional[Decimal]:
    """TOPLAM284008 or TOPLAM 4284,94 — digits stuck to label or OCR noise."""
    match = re.search(
        r"(?i)\b(?:TOPLAM|TOP|TUTAR)\b[^\d]{0,6}(\d{4,7})",
        line,
    )
    if not match:
        return None
    digits = match.group(1)
    if digits.startswith("4") and len(digits) >= 5:
        digits = digits[1:]
    if len(digits) < 4:
        return None
    candidates: list[Decimal] = []
    candidates.append(Decimal(f"{digits[:-2]}.{digits[-2:]}"))
    if len(digits) == 5:
        candidates.append(Decimal(digits) / 100)
    if len(digits) == 6:
        trimmed = digits.rstrip("0")
        if len(trimmed) >= 5:
            candidates.append(Decimal(trimmed) / 100)
    for value in candidates:
        if Decimal("1") <= value < Decimal("500000"):
            return value
    return None


def _parse_tagged_amount(line: str) -> Optional[Decimal]:
    """e.g. #284;043 on product lines (OCR uses ; instead of ,)."""
    match = re.search(r"#?\s*(\d{2,4})[;,](\d{2,3})", line)
    if not match:
        return None
    whole, frac = match.group(1), match.group(2)[:2]
    return _normalize_amount_token(f"{whole},{frac}")


def _amounts_on_line(line: str, *, partial_payment: bool = False) -> list[Decimal]:
    cleaned = _clean_line_for_amounts(line)
    found: list[Decimal] = []
    seen: set[str] = set()
    decimal_spans: list[tuple[int, int]] = []

    for pattern in _AMOUNT_PATTERNS[:2]:
        for match in re.finditer(pattern, cleaned):
            decimal_spans.append(match.span())
            token = match.group(0)
            if token in seen:
                continue
            seen.add(token)
            value = _normalize_amount_token(token)
            if value is None:
                continue
            if partial_payment and not _token_has_decimal_mark(token) and value >= Decimal("1000"):
                continue
            if _TOTAL_LABEL_ON_LINE.search(line):
                value = _fix_leading_four_misread(value, line)
            found.append(value)

    if partial_payment:
        return found

    for match in re.finditer(_AMOUNT_PATTERNS[2], cleaned):
        start, end = match.span()
        if any(start >= ds and end <= de for ds, de in decimal_spans):
            continue
        token = match.group(0)
        if token in seen:
            continue
        seen.add(token)
        value = _normalize_amount_token(token)
        if value is None:
            continue
        if _TOTAL_LABEL_ON_LINE.search(line):
            value = _fix_leading_four_misread(value, line)
        found.append(value)

    return found


def _is_vat_or_subtotal_line(line: str) -> bool:
    if _VAT_OR_SUBTOTAL_LINE.search(line):
        return True
    # e.g. "TOPLAM KDV 41,68" — VAT total, not grand total
    if re.search(r"TOPLAM", line, re.IGNORECASE) and re.search(r"\bKDV\b", line, re.IGNORECASE):
        if _KDV_DAHIL_TOTAL_LINE.search(line):
            return False
        return True
    return False


def _line_total_priority(line: str) -> int:
    """
    Score lines that likely carry the grand total (higher = better).
    Returns 0 when the line should not be used (VAT/subtotal/etc.).
    """
    if _is_vat_or_subtotal_line(line):
        return 0
    if _STRONG_TOTAL_LINE.search(line):
        return 4
    if _KDV_DAHIL_TOTAL_LINE.search(line) and re.search(
        r"TOPLAM|TUTAR|TOTAL", line, re.IGNORECASE
    ):
        return 3
    if re.match(r"^\s*TOPLAM\b", line, re.IGNORECASE):
        return 3
    if re.search(r"\bTOP\b", line, re.IGNORECASE) and not re.search(
        r"TOPKDV", line, re.IGNORECASE
    ):
        return 3
    if re.search(r"\bTOPLAM\b", line, re.IGNORECASE):
        return 2
    if re.search(r"\bTUTAR\b", line, re.IGNORECASE) and _CARD_SLIP_TOTAL_LINE.search(line):
        return 2
    return 0


def _collect_grand_total_amounts(text: str) -> list[Decimal]:
    found: list[Decimal] = []
    for match in _GRAND_TOTAL_RE.finditer(text):
        value = _normalize_amount_token(match.group(1))
        if value is not None:
            found.append(value)
    return found


def _looks_like_truncated_total(low: Decimal, high: Decimal) -> bool:
    """OCR drops leading 4: TOPLAM 46,25 vs kart slip 446,25."""
    if low <= 0 or high <= low or low >= Decimal("200"):
        return False
    if high < Decimal("100"):
        return False
    diff = high - low
    if abs(diff - Decimal("400")) < Decimal("0.05"):
        return True
    if abs(diff - Decimal("40")) < Decimal("0.05") and high < Decimal("500"):
        return True
    ratio = high / low
    return Decimal("8.5") <= ratio <= Decimal("10.5")


def _reconcile_receipt_total(
    amounts: list[Decimal],
    *,
    prefer: Optional[Decimal] = None,
) -> Optional[Decimal]:
    if not amounts:
        return prefer
    unique = list(dict.fromkeys(amounts))
    if prefer is not None and prefer not in unique:
        unique.append(prefer)
    high = max(unique)
    lows = [a for a in unique if a < high]
    for low in lows:
        if _looks_like_truncated_total(low, high):
            return high
    if prefer is not None and prefer >= high:
        return prefer
    if prefer is not None:
        for low in lows:
            if _looks_like_truncated_total(low, prefer):
                return prefer
    # Same total repeated on card slip + KDV table
    if unique.count(high) >= 1:
        matches = sum(1 for a in amounts if abs(a - high) < Decimal("0.01"))
        if matches >= 2:
            return high
    return high if len(unique) == 1 else max(unique, key=lambda v: (amounts.count(v), v))


def _sum_efatura_line_totals(lines: list[str]) -> Optional[Decimal]:
    """E-arşiv: %01 189,00 gibi kalem sonu tutarları topla."""
    item_amounts: list[Decimal] = []
    for line in lines:
        if _is_vat_or_subtotal_line(line) or not re.search(
            r"%\s*\d{1,2}\b", line, re.IGNORECASE
        ):
            continue
        values: list[Decimal] = []
        for match in re.finditer(r"(\d{1,4}[.,]\s*\d{2})", line):
            value = _normalize_amount_token(match.group(1))
            if value is None or value >= Decimal("50000"):
                continue
            values.append(value)
        if values:
            item_amounts.append(values[-1])
    if len(item_amounts) >= 2:
        return sum(item_amounts)
    return None


def _sum_item_line_amounts(lines: list[str]) -> Optional[Decimal]:
    """Birden fazla Adet satırı varsa (eczane fişi) kalem tutarlarını topla."""
    item_amounts: list[Decimal] = []
    for line in lines:
        if not re.search(r"adet|/adet|kilo", line, re.IGNORECASE):
            continue
        line_values: list[Decimal] = []
        for match in re.finditer(r"(\d{1,4}[.,]\s*\d{2})", line):
            value = _normalize_amount_token(match.group(1))
            if value is None or value >= Decimal("50000"):
                continue
            line_values.append(value)
        if line_values:
            item_amounts.append(line_values[-1])
    if len(item_amounts) >= 2:
        return sum(item_amounts)
    if len(item_amounts) == 1:
        return item_amounts[0]
    return None


def _parse_card_slip_total(lines: list[str]) -> Optional[Decimal]:
    """POS slip: TUTAR / TEMASSIZ satırındaki onaylı tutar (ör. 64,70 TL)."""
    candidates: list[tuple[int, int, Decimal]] = []
    n = len(lines)
    for idx, line in enumerate(lines):
        priority = 0
        if re.search(r"TEMASSIZ", line, re.IGNORECASE):
            priority = 5
        elif _CARD_SLIP_TOTAL_LINE.search(line):
            priority = 4
        elif idx >= max(0, int(n * 0.55)):
            priority = 2
        else:
            continue

        line_clean = _clean_line_for_amounts(line)
        for token_match in re.finditer(r"(\d{1,4}[.,]\s*\d{2})(?:\s*TL)?", line_clean, re.IGNORECASE):
            value = _normalize_amount_token(token_match.group(1))
            if value is None or value >= Decimal("500000"):
                continue
            if priority >= 4 or re.search(r"\sTL\b", line, re.IGNORECASE):
                candidates.append((priority, idx, value))

    if not candidates:
        return None
    candidates.sort(key=lambda item: (item[0], item[1]))
    return candidates[-1][2]


def _is_pharmacy_split_payment(lines: list[str]) -> bool:
    """Eczane fişi: SGK/nakit + kart — kullanıcı sadece kart kısmını öder."""
    blob = "\n".join(lines)
    if not _PHARMACY_RECEIPT_RE.search(blob):
        return False
    has_card = any(_CARD_PAYMENT_CONTEXT_RE.search(line) for line in lines)
    has_nakit = any(re.search(r"NAK[Iİ]T", line, re.IGNORECASE) for line in lines)
    multi_item = sum(1 for line in lines if re.search(r"adet|/adet", line, re.IGNORECASE)) >= 2
    return bool(has_card and (has_nakit or multi_item))


def _parse_pharmacy_user_paid(lines: list[str]) -> Optional[Decimal]:
    """
    SGK/nakit kısmı hariç — kart / temassız slip tutarı (hasta payı).
    """
    candidates: list[tuple[int, int, Decimal]] = []
    n = len(lines)

    for idx, line in enumerate(lines):
        if _is_vat_or_subtotal_line(line):
            continue
        lower = line.lower()
        priority = 0
        if re.search(r"temassiz", lower):
            priority = 6
        elif re.search(r"onay\s*kodu", lower):
            priority = 5
        elif re.search(r"satis|satış", lower):
            priority = 4
        elif re.search(r"techpos|bkm|kart|karekod", lower):
            priority = 3
        elif idx >= int(n * 0.5) and re.search(r"\sTL\b", line, re.IGNORECASE):
            priority = 2
        else:
            continue

        line_clean = _clean_line_for_amounts(line)
        for match in re.finditer(r"(\d{1,4}[.,]\s*\d{2})(?:\s*TL)?", line_clean, re.IGNORECASE):
            value = _normalize_amount_token(match.group(1))
            if value is None or value < Decimal("0.5") or value >= Decimal("500000"):
                continue
            candidates.append((priority, idx, value))

    if candidates:
        candidates.sort(key=lambda item: (item[0], item[1]))
        return candidates[-1][2]

    slip = _parse_card_slip_total(lines)
    if slip is not None:
        return slip

    item_amounts: list[Decimal] = []
    for line in lines:
        if not re.search(r"adet|/adet", line, re.IGNORECASE):
            continue
        values: list[Decimal] = []
        for match in re.finditer(r"(\d{1,4}[.,]\d{2})", line):
            value = _normalize_amount_token(match.group(1))
            if value is not None and value < Decimal("500000"):
                values.append(value)
        if values:
            item_amounts.append(values[-1])

    if len(item_amounts) >= 2:
        return min(item_amounts)

    return None


def _parse_amount(lines: list[str]) -> Optional[Decimal]:
    blob = "\n".join(lines)

    if _is_pharmacy_split_payment(lines):
        user_paid = _parse_pharmacy_user_paid(lines)
        if user_paid is not None:
            return user_paid

    grand_amounts = _collect_grand_total_amounts(blob)

    total_candidates: list[tuple[int, int, Decimal]] = []
    with_currency: list[Decimal] = []
    all_amounts: list[Decimal] = []
    partial_payment = _PARTIAL_PAYMENT_LINE

    for idx, line in enumerate(lines):
        fuel_total = _parse_fuel_line_total(line)
        if fuel_total is not None:
            total_candidates.append((5, idx, fuel_total))

        is_partial = bool(partial_payment.search(line))
        amounts = _amounts_on_line(line, partial_payment=is_partial)
        priority = _line_total_priority(line)
        if is_partial:
            priority = 0

        if priority > 0 and not amounts:
            glued = _parse_glued_total_digits(line)
            if glued is not None:
                total_candidates.append((priority, idx, glued))

        if not amounts:
            tagged = _parse_tagged_amount(line)
            if tagged is not None and priority == 0:
                all_amounts.append(tagged)
            continue

        if not _PRODUCT_LINE.search(line) and not _is_vat_or_subtotal_line(line):
            all_amounts.extend(amounts)

        if priority > 0:
            late_boost = 1 if idx >= max(0, len(lines) - max(3, len(lines) // 3)) else 0
            total_candidates.append((priority + late_boost, idx, _pick_line_amount(amounts)))
        if re.search(r"(?:TL|TRY|₺)\b", line, re.IGNORECASE) and not _is_vat_or_subtotal_line(line):
            with_currency.extend(amounts)

    card_total = _parse_card_slip_total(lines)
    item_total = _sum_item_line_amounts(lines)
    if not item_total:
        item_total = _sum_efatura_line_totals(lines)

    reconcile_pool: list[Decimal] = list(grand_amounts)
    if card_total is not None:
        reconcile_pool.append(card_total)
    if item_total is not None:
        reconcile_pool.append(item_total)
    if total_candidates:
        reconcile_pool.extend(c[2] for c in total_candidates)

    resolved = _reconcile_receipt_total(
        reconcile_pool,
        prefer=card_total or item_total,
    )
    if resolved is not None:
        return resolved

    if total_candidates:
        total_candidates.sort(key=lambda item: (item[0], item[1]))
        return total_candidates[-1][2]

    if not _is_pharmacy_split_payment(lines):
        if item_total is not None:
            return item_total

    if card_total is not None:
        return card_total

    if with_currency:
        reasonable_cc = [a for a in with_currency if a < Decimal("500000")]
        if reasonable_cc:
            return reasonable_cc[-1]
    if all_amounts:
        reasonable = [
            a
            for a in all_amounts
            if a < Decimal("500000") and a >= Decimal("1")
        ]
        if reasonable:
            return max(reasonable)
    return None


_OCR_TEXT_FIXES: tuple[tuple[str, str], ...] = (
    (r"p2trol", "petrol"),
    (r"p3trol", "petrol"),
    (r"1\s*spark", "ispark"),
    (r"i\s*spark", "ispark"),
    (r"excelll?um", "excellium"),
    (r"dan[iı]sm", "danismanlik"),
    (r"eczanes[iı1l]", "eczanesi"),
    (r"eczane[sıi]?", "eczane"),
    (r"süt\s*mam", "sut mam"),
    (r"sut\s*mam", "sut mam"),
    (r"tatl[iı1l]", "tatli"),
)

def _fix_ocr_year(year: int) -> int:
    """OCR often reads 2026 as 2926."""
    today_y = date.today().year
    if 2000 <= year <= today_y + 1:
        return year
    if year > 2100:
        # 2926 → 2026
        candidate = year - 900
        if 2000 <= candidate <= today_y + 1:
            return candidate
        digits = list(str(year))
        if len(digits) == 4 and digits[0] == "2" and digits[2] == "9":
            try_y = int(f"{digits[0]}{digits[1]}0{digits[3]}")
            if 2000 <= try_y <= today_y + 1:
                return try_y
    return year


def _normalize_year(year: int) -> int:
    """DD/MM/YY on Turkish receipts → full year (26 → 2026)."""
    if year < 100:
        year = 2000 + year if year <= 50 else 1900 + year
    return _fix_ocr_year(year)


def _coerce_receipt_date(day: int, month: int, year: int) -> Optional[date]:
    year = _normalize_year(year)
    try:
        parsed = date(year, month, day)
    except ValueError:
        return None
    today = date.today()
    # Fiş tarihi bugünden birkaç gün ileri olabilir (saat dilimi / OCR)
    if parsed < today - timedelta(days=730) or parsed > today + timedelta(days=3):
        return None
    return parsed


def _normalize_receipt_date_text(text: str) -> str:
    """OCR: '25.05. 2026' → '25.05.2026' before pattern matching."""
    text = re.sub(r"(\d{2}),(\d{2})([.,/]\d{4})", r"\1.\2\3", text)
    text = re.sub(r"(\d{2}[.,/]\d{2})[.\s,]+(\d{4})\b", r"\1.\2", text)
    # OCR: '20/05/2' → '20/05/2026' (truncated year)
    today_y = date.today().year
    text = re.sub(
        r"(\d{2}[.,/\-]\d{2}[.,/\-])(\d{1})\b(?!\d)",
        lambda m: f"{m.group(1)}{today_y}" if int(m.group(2)) == (today_y % 10) else m.group(0),
        text
    )
    return text


def _date_match_score(line: str, *, labeled_pattern: bool) -> int:
    """Higher = prefer this date (TARIH header yes, POS slip no)."""
    score = 100 if labeled_pattern else 25
    lower = line.lower()
    if re.search(r"tarih|tar\s*ih", lower):
        score += 80
    if re.search(r"saat", lower) and not re.search(
        r"islem|isley|stan|batch|onay|provizyon|isyeri\s*no", lower
    ):
        score += 40
    if re.search(
        r"islem|isley|stan|batch|onay\s*kodu|provizyon|kart\s*no|"
        r"isyeri\s*no|pos\s*no|sat[iı]s\s*$",
        lower,
    ):
        score -= 90
    return score


def _parse_date_groups(groups: tuple[str, ...]) -> Optional[date]:
    g = groups
    try:
        if len(g[0]) == 4:
            return _coerce_receipt_date(int(g[2]), int(g[1]), int(g[0]))
        return _coerce_receipt_date(int(g[0]), int(g[1]), int(g[2]))
    except (ValueError, TypeError):
        return None


def _parse_date(lines: list[str]) -> Optional[date]:
    patterns: list[tuple[str, bool]] = [
        (r"(?:tarih|tar\s*ih)\s*[:\s]*(\d{2})[.,/\-](\d{2})[.,/\-\s]*(\d{4})\b", True),
        (r"(?:tarih|tar\s*ih)\s*[:\s]*(\d{2})[.,/\-](\d{2})[.,/\-](\d{2})\b", True),
        (r"(\d{2})[.,/\-](\d{2})[.,/\-](\d{4})\b", False),
        (r"(\d{2})[.,/\-](\d{2})[.,/\-](\d{2})\b", False),
        (r"(\d{4})[.,/\-](\d{2})[.,/\-](\d{2})\b", False),
    ]

    candidates: list[tuple[int, date]] = []
    for line in lines:
        line_norm = _normalize_receipt_date_text(line)
        for pattern, labeled in patterns:
            for match in re.finditer(pattern, line_norm, re.IGNORECASE):
                parsed = _parse_date_groups(match.groups())
                if parsed is None:
                    continue
                score = _date_match_score(line, labeled_pattern=labeled)
                candidates.append((score, parsed))

    if not candidates:
        return None

    max_score = max(s for s, _ in candidates)
    top_dates = [d for s, d in candidates if s == max_score]
    today = date.today()
    # Aynı öncelikte birden fazla yıl varsa bugüne en yakın (genelde doğru fiş yılı)
    return min(top_dates, key=lambda d: abs((today - d).days))


_MERCHANT_SKIP = re.compile(
    r"(fatura|receipt|fiş|fis|tel|phone|vergi|v\.?d\.?|tckn|www\.|http|@|"
    r"toplam|tutar|kdv|nakit|kredi|pos|fiş\s*no|fis\s*no|saat|tarih|"
    r"adres|address|iban|mersis)",
    re.IGNORECASE,
)

_MERCHANT_HINT = re.compile(
    r"(eczane|ecz\.|pharmacy|market|migros|bim|a101|restoran|restaurant|cafe|kahve|"
    r"kebap|kebab|doner|döner|firin|fırın|pastane|unlu|mamul|bakkal|"
    r"ltd|şti|sti|a\.ş|tic\.|san\.)",
    re.IGNORECASE,
)

_PHARMACIST_LINE = re.compile(
    r"ecz\.?\s*[a-zçğıöşü]{2,}",
    re.IGNORECASE,
)

_PHARMACY_NAME = re.compile(
    r"[a-zçğıöşü]{2,}\s+eczanesi\b",
    re.IGNORECASE,
)

_HEALTH_SIGNAL = re.compile(
    r"(?:eczane|eczanesi|pharmacy|hastane|hospital|klinik|clinic|ecz\.?\s*[a-z]{2,})",
    re.IGNORECASE,
)


def _score_merchant_line(clean: str, line_index: int) -> int:
    if len(clean) < 3 or len(clean) > 72:
        return 0
    if _MERCHANT_SKIP.search(clean):
        return 0
    if re.fullmatch(r"[\d\W]+", clean):
        return 0
    digits = sum(c.isdigit() for c in clean)
    if digits > len(clean) * 0.45:
        return 0

    score = 1
    letters = sum(c.isalpha() for c in clean)
    if letters >= len(clean) * 0.5:
        score += 2
    if _MERCHANT_HINT.search(clean):
        score += 4
    if re.search(
        r"kebap|kebab|restoran|lokanta|eczane|pastane|sut\s*mamul|tatli|dondurma",
        clean,
        re.IGNORECASE,
    ):
        score += 5
    if re.search(r"ecz\.?\s*[A-Za-zÇĞİÖŞÜçğıöşü]{2,}", clean, re.IGNORECASE):
        score += 8
    if _CORPORATE_LINE.search(clean) and line_index > 0:
        score -= 3
    if line_index <= 2:
        score += 2
    elif line_index <= 5:
        score += 1
    if clean.isupper() and letters >= 4:
        score += 1
    if re.search(r"\d{2}[./]\d{2}[./]\d{2,4}", clean):
        score -= 3
    return score


def _extract_pharmacy_label(line: str) -> Optional[str]:
    """Ecz. AD SOYAD veya AD ECZANESİ satırından okunabilir açıklama."""
    match = re.search(
        r"ECZ\.?\s*([A-ZÇĞİÖŞÜ][A-ZÇĞİÖŞÜa-zçğıöşü\.\s\-]{2,50})",
        line,
        re.IGNORECASE,
    )
    if match:
        name = re.sub(r"\s+", " ", match.group(1)).strip(" .-_")
        name = re.sub(r"[^A-Za-zÇĞİÖŞÜçğıöşü\s\.]", "", name).strip()
        words: list[str] = []
        for word in name.split():
            low = word.lower()
            if low in {"ss", "aa", "aaa", "nn", "xs", "sss", "sq", "sw", "osg", "inr"}:
                break
            if len(word) >= 2 and re.search(r"[A-Za-zÇĞİÖŞÜçğıöşü]{2}", word):
                words.append(word)
            elif words:
                break
        name = " ".join(words)
        if len(name) >= 3:
            return f"Ecz. {name}"[:120]

    match_shop = re.search(
        r"([A-ZÇĞİÖŞÜ][A-ZÇĞİÖŞÜa-zçğıöşü\s]{2,35})\s+ECZANES[Iİ1L]",
        line,
        re.IGNORECASE,
    )
    if match_shop:
        shop = re.sub(r"\s+", " ", match_shop.group(1)).strip()
        if len(shop) >= 3:
            return f"{shop} Eczanesi"[:120]
    return None


_CHAIN_MERCHANT_RE = re.compile(
    r"\b(carrefoursa|carrefour\s*sa|parrefour|migros|bim|a101|sok\s*market|hakmar)\b",
    re.IGNORECASE,
)


def _extract_chain_merchant(lines: list[str]) -> Optional[str]:
    """Zincir adı fişin altında olabilir; banka başlığı yerine bunu kullan."""
    for line in lines:
        match = _CHAIN_MERCHANT_RE.search(line)
        if match:
            name = match.group(1)
            if re.search(r"parrefour", name, re.I):
                return "CarrefourSA"
            if re.search(r"carrefour", name, re.I):
                return "CarrefourSA"
            return name.title()[:120]
    return None


def _parse_merchant(lines: list[str]) -> Optional[str]:
    chain = _extract_chain_merchant(lines)
    if chain:
        return chain

    for line in lines[:10]:
        pharma = _extract_pharmacy_label(line)
        if pharma:
            return pharma

    candidates: list[tuple[int, int, str]] = []
    for idx, line in enumerate(lines[:14]):
        clean = line.strip()
        score = _score_merchant_line(clean, idx)
        if score > 0:
            candidates.append((score, -idx, clean))
    if not candidates:
        return None
    candidates.sort(reverse=True)
    return candidates[0][2][:120]


def _normalize_for_keywords(text: str) -> str:
    t = text.lower()
    t = t.replace("ı", "i").replace("İ", "i").replace("ş", "s").replace("ğ", "g")
    t = t.replace("ö", "o").replace("ü", "u").replace("ç", "c")
    for pattern, replacement in _OCR_TEXT_FIXES:
        t = re.sub(pattern, replacement, t, flags=re.IGNORECASE)
    return t


# Şirket unvanı satırları (petrol ürünleri, oto, ltd) — yakıt istasyonu değil
_CORPORATE_LINE = re.compile(
    r"(?:"
    r"ltd|şti|sti\b|san\.|tic\.|ve\s+tic|ins\.|kuy\.|gida\s+san|"
    r"petrol\s+urun|petrol\s+ürün|urunleri\s+oto|ürünleri\s+oto"
    r")",
    re.IGNORECASE,
)

def parse_receipt_fields(raw_text: str, user_id: Optional[int] = None, db=None) -> dict:
    lines = [ln.strip() for ln in raw_text.splitlines() if ln.strip()]
    amount = _parse_amount(lines)
    expense_date = _parse_date(lines)
    merchant = _parse_merchant(lines)
    from app.services.receipt_category import detect_receipt_category

    category_name, category_source = detect_receipt_category(raw_text, merchant, lines)
    if category_source == "none":
        category_source = None

    if user_id is not None and db is not None:
        from app.services.receipt_merchant_memory import lookup_category

        from app.services.receipt_category import (
            has_strong_food_signal,
            has_strong_grocery_signal,
        )

        hay_norm = _normalize_for_keywords(raw_text)
        grocery_signal = has_strong_grocery_signal(hay_norm, lines)
        food_signal = has_strong_food_signal(hay_norm, merchant or "")

        memory_cat = lookup_category(db, user_id, raw_text, merchant)
        if memory_cat:
            keep_detected = (
                category_source == "priority"
                and category_name in ("Transport", "Health")
                and memory_cat != category_name
            )
            if grocery_signal and memory_cat == "Food":
                keep_detected = True
            if food_signal and memory_cat == "Groceries":
                keep_detected = True
            if category_name == "Groceries" and memory_cat == "Food":
                keep_detected = True
            if category_name == "Food" and memory_cat == "Groceries":
                keep_detected = True
            if not keep_detected:
                if category_name == "Food" and memory_cat == "Transport":
                    pass
                elif category_name == "Health" and memory_cat != "Health":
                    pass
                else:
                    category_name = memory_cat
                    category_source = "memory"
        if food_signal and category_name != "Food":
            category_name = "Food"
            category_source = "keywords"
        elif grocery_signal and category_name != "Groceries":
            category_name = "Groceries"
            category_source = "store"

    return {
        "raw_text": raw_text,
        "amount": round(float(amount), 2) if amount is not None else None,
        "expense_date": expense_date.isoformat() if expense_date else None,
        "description": None,
        "category_name": category_name,
        "category_source": category_source,
        "description_source": None,
        "confidence": _confidence(amount, expense_date, category_name, None),
    }


def _confidence(
    amount: Optional[Decimal],
    expense_date: Optional[date],
    category_name: Optional[str],
    description: Optional[str] = None,
) -> str:
    score = sum(
        [
            amount is not None,
            expense_date is not None,
            category_name is not None,
            description is not None,
        ]
    )
    if score >= 2:
        return "high"
    if score == 1:
        return "medium"
    return "low"


def scan_receipt_image(data: bytes, user_id: Optional[int] = None, db=None) -> dict:
    raw_text = extract_text_from_image(data)
    if not raw_text:
        return {
            "raw_text": "",
            "amount": None,
            "expense_date": None,
            "description": None,
            "category_name": None,
            "category_source": None,
            "confidence": "low",
            "message": "No text detected on the receipt. Try a clearer photo or add the expense manually.",
        }
    fields = parse_receipt_fields(raw_text, user_id=user_id, db=db)
    fields["message"] = "Review the detected values before saving."
    return fields
