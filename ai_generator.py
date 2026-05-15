"""AI question generation. Reads ANTHROPIC_API_KEY (or OPENAI_API_KEY) from env.

Returns a list of question dicts in the same shape used by the rest of the app:
    {"type": "mcq_single", "text": "...", "options": [...], "correct_answers": [int], "points": 1}
"""
import json
import os
import re
import urllib.request


SYSTEM_PROMPT = (
    "You are an expert quiz writer. Given source material and a target number of questions, "
    "produce JSON exactly in this schema, with NO surrounding prose or markdown fences:\n"
    "{\"questions\": [\n"
    "  {\"type\": \"mcq_single\", \"text\": \"...\", \"options\": [\"A\", \"B\", \"C\", \"D\"], "
    "\"correct_answers\": [<index of the correct option>], \"points\": 1, \"explanation\": \"...\"}\n"
    "]}\n"
    "Allowed types: mcq_single, mcq_multi, true_false, short_answer. "
    "For mcq_single and mcq_multi include exactly 4 options. "
    "For true_false set options to [\"True\", \"False\"]. "
    "For short_answer omit options and set correct_answers to a list of one acceptable string."
)


def generate_questions(material: str, n: int = 10, qtype: str = "mcq_single") -> list[dict]:
    if not material or not material.strip():
        raise ValueError("Source material is empty.")
    n = max(1, min(int(n), 50))
    api_key = os.environ.get("ANTHROPIC_API_KEY") or os.environ.get("OPENAI_API_KEY")
    if not api_key:
        raise RuntimeError(
            "No AI provider key configured. Set ANTHROPIC_API_KEY (or OPENAI_API_KEY) "
            "in the environment to enable AI quiz generation."
        )

    user_prompt = (
        f"Source material:\n---\n{material[:18000]}\n---\n"
        f"Write {n} {qtype.replace('_',' ')} questions based on this material. "
        f"Return JSON only."
    )

    if os.environ.get("ANTHROPIC_API_KEY"):
        return _call_anthropic(api_key, SYSTEM_PROMPT, user_prompt)
    return _call_openai(api_key, SYSTEM_PROMPT, user_prompt)


def _call_anthropic(api_key: str, system: str, user: str) -> list[dict]:
    body = json.dumps({
        "model": os.environ.get("ANTHROPIC_MODEL", "claude-3-5-sonnet-latest"),
        "max_tokens": 4096,
        "system": system,
        "messages": [{"role": "user", "content": user}],
    }).encode("utf-8")
    req = urllib.request.Request(
        "https://api.anthropic.com/v1/messages",
        data=body,
        headers={
            "x-api-key": api_key,
            "anthropic-version": "2023-06-01",
            "content-type": "application/json",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        data = json.loads(resp.read())
    text = "".join(b.get("text", "") for b in data.get("content", []) if b.get("type") == "text")
    return _extract_questions(text)


def _call_openai(api_key: str, system: str, user: str) -> list[dict]:
    body = json.dumps({
        "model": os.environ.get("OPENAI_MODEL", "gpt-4o-mini"),
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ],
        "response_format": {"type": "json_object"},
    }).encode("utf-8")
    req = urllib.request.Request(
        "https://api.openai.com/v1/chat/completions",
        data=body,
        headers={"Authorization": f"Bearer {api_key}", "content-type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        data = json.loads(resp.read())
    text = data["choices"][0]["message"]["content"]
    return _extract_questions(text)


def _extract_questions(text: str) -> list[dict]:
    # Strip code fences if any
    text = text.strip()
    text = re.sub(r"^```(?:json)?\s*", "", text)
    text = re.sub(r"\s*```$", "", text)
    try:
        payload = json.loads(text)
    except json.JSONDecodeError:
        # Best-effort: find the JSON object
        m = re.search(r"\{[\s\S]*\}", text)
        if not m:
            raise RuntimeError("AI did not return JSON.")
        payload = json.loads(m.group(0))
    questions = payload.get("questions") or []
    cleaned = []
    for q in questions:
        if not isinstance(q, dict):
            continue
        qtype = (q.get("type") or "mcq_single").strip().lower()
        text_q = (q.get("text") or "").strip()
        if not text_q:
            continue
        options = q.get("options") or []
        if qtype == "true_false":
            options = ["True", "False"]
        correct = q.get("correct_answers") or []
        if isinstance(correct, int):
            correct = [correct]
        cleaned.append({
            "type": qtype,
            "text": text_q,
            "options": options,
            "correct_answers": correct,
            "points": int(q.get("points") or 1),
            "explanation": q.get("explanation") or "",
        })
    return cleaned
