"""
Bulk-import questions from text, CSV, or Word (.docx).

Supported text formats:

Aiken format (one block per question):
    What is the capital of France?
    A) Berlin
    B) Madrid
    C) Paris
    D) Rome
    ANSWER: C

GIFT-like simple:
    1. What is 2+2?
    a. 3
    *b. 4         (asterisk marks correct)
    c. 5

True/False:
    Q: The earth is flat.
    ANSWER: False

Short answer:
    Q: What does HTTP stand for?
    A: Hypertext Transfer Protocol
"""
import csv
import io
import json
import re

try:
    import docx  # python-docx
except Exception:
    docx = None


def parse_text(text: str) -> list[dict]:
    blocks = re.split(r"\n\s*\n", text.strip())
    out = []
    for block in blocks:
        q = _parse_block(block.strip())
        if q:
            out.append(q)
    return out


_OPTION_RE = re.compile(r"^\s*(\*?)\s*([A-Za-z]|\d+)\s*[\.\)]\s+(.*)$")
_ANSWER_RE = re.compile(r"^\s*ANSWER\s*[:\-]\s*(.+)$", re.I)
_SHORT_ANS_RE = re.compile(r"^\s*A\s*[:\-]\s*(.+)$", re.I)
_QPREFIX_RE = re.compile(r"^(?:Q\s*[:\-]|\d+[\.\)])\s*", re.I)


def _parse_block(block: str) -> dict | None:
    lines = [l for l in block.splitlines() if l.strip()]
    if not lines:
        return None
    # Strip leading numbering on first line
    first = _QPREFIX_RE.sub("", lines[0]).strip()
    options = []
    correct_marks = []  # indexes flagged with * in option line
    answer_line = None
    short_answer_line = None
    rest = lines[1:]
    body_lines = [first]
    for line in rest:
        m_ans = _ANSWER_RE.match(line)
        m_sa = _SHORT_ANS_RE.match(line)
        m_opt = _OPTION_RE.match(line)
        if m_ans:
            answer_line = m_ans.group(1).strip()
        elif m_sa and not m_opt:
            short_answer_line = m_sa.group(1).strip()
        elif m_opt:
            star, _label, content = m_opt.groups()
            if star == "*":
                correct_marks.append(len(options))
            options.append(content.strip())
        else:
            # extra question-text line
            body_lines.append(line.strip())
    text = " ".join(body_lines).strip()

    # Decide type
    if options:
        # Resolve correct via ANSWER: line if present
        correct = list(correct_marks)
        if answer_line:
            for token in re.split(r"[,\s/]+", answer_line):
                token = token.strip()
                if not token:
                    continue
                if token.isalpha() and len(token) == 1:
                    idx = ord(token.upper()) - ord("A")
                    if 0 <= idx < len(options):
                        correct.append(idx)
                elif token.isdigit():
                    idx = int(token) - 1
                    if 0 <= idx < len(options):
                        correct.append(idx)
        correct = sorted(set(correct))
        # Detect true/false written as options
        opt_lower = [o.lower() for o in options]
        if set(opt_lower) <= {"true", "false"} and len(options) == 2:
            return {
                "type": "true_false",
                "text": text,
                "options": ["True", "False"],
                "correct_answers": [opt_lower.index("true")] if "true" in opt_lower and correct and opt_lower[correct[0]] == "true" else [opt_lower.index("false")] if correct and opt_lower[correct[0]] == "false" else [],
                "points": 1,
            }
        qtype = "mcq_multi" if len(correct) > 1 else "mcq_single"
        return {
            "type": qtype,
            "text": text,
            "options": options,
            "correct_answers": correct,
            "points": 1,
        }
    if answer_line:
        if answer_line.lower() in ("true", "false", "t", "f"):
            return {
                "type": "true_false",
                "text": text,
                "options": ["True", "False"],
                "correct_answers": [0 if answer_line.lower() in ("true", "t") else 1],
                "points": 1,
            }
        return {
            "type": "short_answer",
            "text": text,
            "options": [],
            "correct_answers": [answer_line],
            "points": 1,
        }
    if short_answer_line:
        return {
            "type": "short_answer",
            "text": text,
            "options": [],
            "correct_answers": [short_answer_line],
            "points": 1,
        }
    # Fallback: long answer / open-ended
    return {
        "type": "long_answer",
        "text": text,
        "options": [],
        "correct_answers": [],
        "points": 1,
    }


def parse_csv(text: str) -> list[dict]:
    """
    Columns:
      type,text,option1,option2,option3,option4,option5,option6,correct,points
    correct: letter (A,B), index (1-based), or pipe-separated for multi: "A|C"
    For true_false: correct = "True" or "False"
    For short_answer: correct = the expected answer (or multiple separated by |)
    """
    reader = csv.DictReader(io.StringIO(text))
    out = []
    for row in reader:
        qtype = (row.get("type") or "mcq_single").strip().lower()
        qtext = (row.get("text") or "").strip()
        if not qtext:
            continue
        opts = []
        for i in range(1, 9):
            v = row.get(f"option{i}")
            if v and v.strip():
                opts.append(v.strip())
        correct_raw = (row.get("correct") or "").strip()
        points = int(row.get("points") or 1)
        correct: list = []
        if qtype in ("mcq_single", "mcq_multi"):
            for tok in re.split(r"[|,\s]+", correct_raw):
                tok = tok.strip()
                if not tok:
                    continue
                if tok.isalpha() and len(tok) == 1:
                    correct.append(ord(tok.upper()) - ord("A"))
                elif tok.isdigit():
                    correct.append(int(tok) - 1)
            if qtype == "mcq_single" and len(correct) > 1:
                qtype = "mcq_multi"
        elif qtype == "true_false":
            opts = ["True", "False"]
            correct = [0] if correct_raw.lower() in ("true", "t", "1") else [1]
        elif qtype in ("short_answer", "fill_blank"):
            correct = [c.strip() for c in correct_raw.split("|") if c.strip()]
            opts = []
        else:
            opts = opts or []
            correct = []
        out.append({
            "type": qtype,
            "text": qtext,
            "options": opts,
            "correct_answers": correct,
            "points": points,
        })
    return out


def parse_json(text: str) -> list[dict]:
    """Parse a JSON payload — either a flat list of question dicts,
    or one of the templates from demo_content (object with `.questions`)."""
    data = json.loads(text)
    # Could be a dict mapping kind -> template, or a single template, or a flat list
    if isinstance(data, dict):
        if "questions" in data and isinstance(data["questions"], list):
            data = data["questions"]
        else:
            # If multiple templates passed, flatten all their questions
            collected = []
            for v in data.values():
                if isinstance(v, dict) and "questions" in v:
                    collected.extend(v["questions"])
            data = collected
    if not isinstance(data, list):
        return []
    out = []
    for q in data:
        if not isinstance(q, dict) or not q.get("text"):
            continue
        out.append({
            "type": q.get("type") or "mcq_single",
            "text": q["text"],
            "options": q.get("options") or [],
            "correct_answers": q.get("correct_answers") or [],
            "points": int(q.get("points") or 1),
            "explanation": q.get("explanation") or "",
            "time_limit_seconds": int(q.get("time_limit_seconds") or 0),
            "image_url": q.get("image_url"),
            "is_required": 0 if q.get("is_required") is False else 1,
        })
    return out


def parse_docx(path: str) -> list[dict]:
    if docx is None:
        raise RuntimeError("python-docx is not installed")
    document = docx.Document(path)
    lines = []
    for p in document.paragraphs:
        line = p.text
        if line is None:
            continue
        lines.append(line)
    text = "\n".join(lines)
    return parse_text(text)


def to_db_rows(questions: list[dict]) -> list[tuple]:
    rows = []
    for i, q in enumerate(questions):
        rows.append((
            q["type"],
            q["text"],
            json.dumps(q.get("options") or []),
            json.dumps(q.get("correct_answers") or []),
            int(q.get("points") or 1),
            i,
            q.get("explanation") or "",
        ))
    return rows
