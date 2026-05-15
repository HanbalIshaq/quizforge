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

    if qtype == "matching":
        # raw_answer: list of strings (the picked "b" for each prompt index)
        # correct = [<right side of each pair>] in same order as options
        # options = [{"a": "...", "b": "..."}, ...]
        options = question.get("options")
        if isinstance(options, str):
            try:
                options = json.loads(options)
            except Exception:
                options = []
        options = options or []
        if not isinstance(raw_answer, list):
            return False, 0.0, False
        ok_count = 0
        for i, pair in enumerate(options):
            expected = (pair or {}).get("b", "") if isinstance(pair, dict) else ""
            picked = raw_answer[i] if i < len(raw_answer) else ""
            if normalize(picked) == normalize(expected):
                ok_count += 1
        # Partial credit: proportion of pairs matched
        if not options:
            return False, 0.0, False
        ratio = ok_count / len(options)
        return ratio == 1.0, points * ratio, False

    if qtype == "ordering":
        # raw_answer: list of indices = student's ordering of the options
        # correct: list of indices = correct ordering
        options = question.get("options")
        if isinstance(options, str):
            try: options = json.loads(options)
            except Exception: options = []
        options = options or []
        if not isinstance(raw_answer, list):
            return False, 0.0, False
        correct_order = correct if correct else list(range(len(options)))
        try:
            picked = [int(x) for x in raw_answer]
        except Exception:
            return False, 0.0, False
        ok = picked == correct_order
        return ok, points if ok else 0.0, False

    if qtype == "drag_drop":
        # options = [{"item":"...","bin":"..."}, ...]
        # raw_answer = list of strings (picked bin for each item)
        options = question.get("options")
        if isinstance(options, str):
            try: options = json.loads(options)
            except Exception: options = []
        options = options or []
        if not isinstance(raw_answer, list):
            return False, 0.0, False
        ok_count = 0
        for i, pair in enumerate(options):
            expected = (pair or {}).get("bin", "") if isinstance(pair, dict) else ""
            picked = raw_answer[i] if i < len(raw_answer) else ""
            if normalize(picked) == normalize(expected):
                ok_count += 1
        if not options:
            return False, 0.0, False
        ratio = ok_count / len(options)
        return ratio == 1.0, points * ratio, False

    if qtype == "hotspot":
        # options = [{"x": float 0..1, "y": float 0..1, "r": float 0..1, "label": str}]
        # raw_answer: {"x": float, "y": float}
        options = question.get("options")
        if isinstance(options, str):
            try: options = json.loads(options)
            except Exception: options = []
        options = options or []
        if not isinstance(raw_answer, dict):
            return False, 0.0, False
        try:
            x = float(raw_answer.get("x", -1))
            y = float(raw_answer.get("y", -1))
        except Exception:
            return False, 0.0, False
        for hs in options:
            try:
                hx, hy, hr = float(hs.get("x")), float(hs.get("y")), float(hs.get("r", 0.05))
            except Exception:
                continue
            if ((x - hx) ** 2 + (y - hy) ** 2) ** 0.5 <= hr:
                return True, points, False
        return False, 0.0, False

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
    # Form fields (for kind='form' — JotForm-style data collection)
    ("email", "Email address (form field)"),
    ("phone", "Phone number (form field)"),
    ("date", "Date picker (form field)"),
    ("number", "Number (form field)"),
    ("dropdown", "Dropdown / Select (form field)"),
    # Complex interactive types
    ("matching", "Matching (pair left side with right side)"),
    ("ordering", "Ordering / Sequencing (arrange in correct order)"),
    ("drag_drop", "Drag & Drop (sort items into categories)"),
    ("hotspot", "Image Hotspot (click on the correct area)"),
]
