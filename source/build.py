#!/usr/bin/env python3
"""Rebuild Gap App's Marble index from the Skill Taxonomy v1 files.

Usage (from the repository root):
    python3 source/build.py

Reads source/data/topics.json and source/data/dependencies.json.
Writes public/data/marble.json. The Marble files are not rewritten.
"""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = Path(__file__).resolve().parent / "data"
OUT = ROOT / "public" / "data" / "marble.json"

NOTE = (
    "This is a starting point, not a diagnosis. Ages are approximate and the "
    "taxonomy is not written for learners with additional needs. Treat "
    "prerequisite links as a starting point and use professional judgement."
)
ATTRIBUTION = {
    "title": "Marble Skill Taxonomy (v1)",
    "copyright": "© Generative Spark, Inc. (Marble)",
    "url": "https://withmarble.com",
    "databaseLicence": "ODbL 1.0",
    "contentLicence": "CC BY-SA 4.0",
    "source": "https://github.com/withmarbleapp/os-taxonomy",
    "standards": (
        "Curriculum standards referenced by the taxonomy have their own upstream "
        "licences (see PROVENANCE.md in that repository)."
    ),
    "note": NOTE,
}


def acara_codes(topic: dict) -> list[str]:
    found: list[str] = []
    for entry in topic.get("standards") or []:
        if not isinstance(entry, str) or not entry.startswith("acara-v9:"):
            continue
        code = entry.split(":", 1)[1].strip()
        if code and code not in found:
            found.append(code)
    return found


def main() -> None:
    topics_doc = json.loads((DATA / "topics.json").read_text(encoding="utf-8"))
    deps_doc = json.loads((DATA / "dependencies.json").read_text(encoding="utf-8"))
    if topics_doc.get("version") != "v1" or deps_doc.get("version") != "v1":
        raise SystemExit("Expected Marble taxonomy version v1")
    topics = topics_doc["topics"]
    deps = deps_doc["dependencies"]
    ids = {topic["id"] for topic in topics}
    compact_topics = []
    for topic in topics:
        if topic["id"] not in ids:
            continue
        compact_topics.append({
            "i": topic["id"],
            "n": topic["name"],
            "s": topic["subject"],
            "d": topic["domain"],
            "ds": topic.get("description") or "",
            "p": topic.get("assessmentPrompt") or "",
            "a": int(topic["ageRangeStart"]),
            "b": int(topic["ageRangeEnd"]),
            "c": round(float(topic["centrality"]), 4),
            "ac": acara_codes(topic),
        })
    edges = []
    for edge in deps:
        if edge["topicId"] not in ids or edge["prerequisiteId"] not in ids:
            continue
        if edge["strength"] not in ("hard", "soft"):
            raise SystemExit(f"Unexpected strength {edge['strength']!r}")
        edges.append([
            edge["topicId"],
            edge["prerequisiteId"],
            1 if edge["strength"] == "hard" else 0,
            edge.get("reason") or "",
        ])
    if not compact_topics or not edges:
        raise SystemExit("Marble index is empty")
    payload = {
        "v": "v1",
        "attribution": ATTRIBUTION,
        "topics": compact_topics,
        "edges": edges,
    }
    text = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).replace("</", "<\\/")
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(text, encoding="utf-8")
    print(f"Wrote {OUT.relative_to(ROOT)} ({OUT.stat().st_size} bytes, {len(compact_topics)} skills, {len(edges)} links)")


if __name__ == "__main__":
    main()
