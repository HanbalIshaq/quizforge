"""Generate a branded PDF certificate for a passing attempt."""
import io
import secrets

from reportlab.lib.colors import HexColor
from reportlab.lib.pagesizes import landscape, A4
from reportlab.lib.units import cm
from reportlab.pdfgen import canvas as pdfcanvas


def make_serial() -> str:
    """Short, easy-to-read certificate serial."""
    alphabet = "ABCDEFGHJKMNPQRSTUVWXYZ23456789"
    return "-".join(
        "".join(secrets.choice(alphabet) for _ in range(4)) for _ in range(3)
    )


def render_certificate_pdf(
    *,
    recipient_name: str,
    quiz_title: str,
    score: float,
    max_score: float,
    percentage: float,
    serial: str,
    issued_at_str: str,
    verify_url: str,
    issuer: str = "QuizForge",
    brand_color: str = "#4f46e5",
) -> bytes:
    """Render and return a single-page landscape A4 PDF."""
    buf = io.BytesIO()
    c = pdfcanvas.Canvas(buf, pagesize=landscape(A4))
    W, H = landscape(A4)
    primary = HexColor(brand_color)
    text_color = HexColor("#0f172a")
    muted = HexColor("#64748b")

    # Outer border
    c.setStrokeColor(primary)
    c.setLineWidth(2)
    c.rect(0.8 * cm, 0.8 * cm, W - 1.6 * cm, H - 1.6 * cm)
    # Inner thin border
    c.setLineWidth(0.5)
    c.rect(1.2 * cm, 1.2 * cm, W - 2.4 * cm, H - 2.4 * cm)

    # Title
    c.setFillColor(primary)
    c.setFont("Helvetica-Bold", 36)
    c.drawCentredString(W / 2, H - 4.2 * cm, "Certificate of Achievement")

    # Subtitle
    c.setFillColor(muted)
    c.setFont("Helvetica", 13)
    c.drawCentredString(W / 2, H - 5.3 * cm, "This certifies that")

    # Recipient
    c.setFillColor(text_color)
    c.setFont("Helvetica-Bold", 28)
    c.drawCentredString(W / 2, H - 7.2 * cm, recipient_name or "Recipient")

    # Decorative line
    c.setStrokeColor(primary)
    c.setLineWidth(1.5)
    c.line(W / 2 - 6 * cm, H - 7.7 * cm, W / 2 + 6 * cm, H - 7.7 * cm)

    # Achievement statement
    c.setFillColor(text_color)
    c.setFont("Helvetica", 13)
    c.drawCentredString(W / 2, H - 8.9 * cm, "has successfully completed the assessment")
    c.setFont("Helvetica-Bold", 16)
    c.drawCentredString(W / 2, H - 9.7 * cm, quiz_title or "")

    # Score row
    c.setFont("Helvetica", 12)
    c.setFillColor(muted)
    score_line = f"Score: {score:.1f} / {max_score:.0f}   ·   {percentage:.0f}%"
    c.drawCentredString(W / 2, H - 11.0 * cm, score_line)

    # Footer / metadata
    c.setFont("Helvetica", 10)
    c.setFillColor(muted)
    c.drawString(2 * cm, 2 * cm, f"Issued: {issued_at_str}")
    c.drawString(2 * cm, 1.5 * cm, f"Issued by: {issuer}")
    c.drawRightString(W - 2 * cm, 2 * cm, f"Serial: {serial}")
    c.drawRightString(W - 2 * cm, 1.5 * cm, f"Verify: {verify_url}")

    c.showPage()
    c.save()
    return buf.getvalue()
