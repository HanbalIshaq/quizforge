import json


def normalize(s: str) -> str:
    return " ".join((s or "").strip().lower().split())


def grade_answer(question: dict, raw_answer) -> tuple[bool | None, float, bool]:
    """
    Returns (is_correct, points_earned, needs_manual).
    is_correct may be None for ungradable (e.g., poll, open-ended).
    """
    qtype = question["type"]
    points = float(question.get("points") or 1)
    correct = question.get("correct_answers")
    if isinstance(correct, str):
        try:
            correct = json.loads(correct)
        except Exception:
            correct = []
    correct = correct or []

    if raw_answer is None or raw_answer == "" or raw_answer == []:
        return (False if qtype in _AUTO_TYPES else None, 0.0, qtype in _MANUAL_TYPES)

    if qtype == "mcq_single":
        try:
            picked = int(raw_answer) if not isinstance(raw_answer, list) else int(raw_answer[0])
        except Exception:
            return False, 0.0, False
        ok = picked in correct
        return ok, points if ok else 0.0, False

    if qtype == "mcq_multi":
        picked = raw_answer if isinstance(raw_answer, list) else [raw_answer]
        try:
            picked = sorted({int(x) for x in picked})
        except Exception:
            return False, 0.0, False
        ok = picked == sorted(set(int(x) for x in correct))
        return ok, points if ok else 0.0, False

    if qtype == "true_false":
        try:
            picked = int(raw_answer) if not isinstance(raw_answer, list) else int(raw_answer[0])
        except Exception:
            return False, 0.0, False
        ok = picked in correct
        return ok, points if ok else 0.0, False

    if qtype in ("short_answer", "fill_blank"):
        val = raw_answer if isinstance(raw_answer, str) else (raw_answer[0] if raw_answer else "")
        accepted = [normalize(c) for c in correct]
        ok = normalize(val) in accepted if accepted else None
        if ok is None:
            return None, 0.0, True
        return ok, points if ok else 0.0, False

    if qtype == "long_answer":
        return None, 0.0, True

    # poll, rating, open_ended, word_cloud — not graded
    return None, 0.0, False


_AUTO_TYPES = {"mcq_single", "mcq_multi", "true_false", "short_answer", "fill_blank"}
_MANUAL_TYPES = {"long_answer"}


QUESTION_TYPES = [
    ("mcq_single", "Multiple Choice (single answer)"),
    ("mcq_multi", "Multiple Choice (multiple answers)"),
    ("true_false", "True / False (or Yes / No)"),
    ("short_answer", "Short Answer"),
    ("long_answer", "Long Answer / Essay"),
    ("fill_blank", "Fill in the Blank"),
    ("rating", "Rating (1–5 stars)"),
    ("nps", "NPS — Net Promoter Score (0–10)"),
    ("poll", "Poll choice (no correct answer)"),
    ("open_ended", "Open-ended (free text, ungraded)"),
    ("word_cloud", "Word Cloud (live)"),
]
