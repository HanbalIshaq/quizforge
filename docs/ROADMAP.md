# QuizForge — Build Roadmap to a Sellable SaaS

A staged plan to take QuizForge from MVP to a product schools and businesses will pay for. Each phase is sized to be deliverable in one focused build session.

## Phase 0 — Foundation (DONE)
- Quiz/exam mode, live mode, 10 question types
- Bulk import, CSV/Excel export
- Auto + manual grading
- Real-time host dashboard
- Self-hostable Flask + SQLite

## Phase 1 — Polish & Critical Fixes (in this build)
- **Fix:** Live "Next" button race / silent-fail
- **Fix:** Differentiate Exam vs Poll vs Survey behaviors
- **Add:** Super-admin role + site stats panel
- **Add:** User approval workflow (optional)
- **Add:** Better error messaging in live host
- **Add:** Persistent storage migration guide (Render disk / Fly.io volume)

## Phase 2 — Trust & Formal-Testing Features (next build)
- **Anti-cheating:** copy/paste block, right-click block, tab-switch tracking, full-screen lock, IP allowlist, password-protect quizzes
- **Certificates:** auto-generated PDF on pass, branded template, unique serial number, public verification page
- **Question bank:** reuse questions across quizzes, categories/tags, search
- **Per-question time limits**
- **Pooled randomization:** "Pull 10 random questions out of 50"
- **Branching logic:** show Q5 only if Q4 = "Yes"

## Phase 3 — Question-Type Expansion
- **Matching / pairing** (drag pairs)
- **Ordering / sequencing**
- **Drag-and-drop** into labelled bins
- **Hotspot:** click on an image
- **Label image:** drag labels onto image
- **Equation / math editor** (MathLive)
- **Audio / video questions** (upload media, students play before answering)
- **Audio response** (students record audio answer)

## Phase 4 — Multi-Tenant SaaS
- **Organizations** as first-class objects (schools, companies)
- **Roles:** Org Owner, Org Admin, Teacher, Student
- **Subscription plans:** Free, Teacher, School, District (via Stripe)
- **Trial period + usage limits per plan**
- **Per-org custom branding:** logo, primary color, custom subdomain (e.g. `acme.quizforge.app`)
- **White-label option:** custom domain, hide QuizForge branding
- **Org-level analytics & reports**
- **Invite teachers by email**

## Phase 5 — AI Features (major differentiator)
- **AI quiz from doc/PDF/URL:** upload material, get 10-50 questions
- **AI quiz from topic:** "Generate a 15-question quiz on photosynthesis for 9th grade"
- **AI grading hints:** suggest scores for essay answers
- **AI feedback comments:** auto-write personalised feedback per student
- **AI question improver:** detect ambiguous/biased questions
- **AI proctoring lite:** webcam-based "is someone there?" check without storing video

## Phase 6 — Integrations
- **LTI 1.3** (Learning Tools Interoperability) — major requirement for schools/universities
- **SCORM 1.2 / xAPI export** — for corporate training
- **Google Classroom** — push quiz, pull grades
- **Microsoft Teams / Schoology / Canvas / Moodle** plugins
- **Zapier app** — trigger workflows on quiz completion
- **Public REST API + webhooks**
- **Single sign-on:** Google, Microsoft, SAML

## Phase 7 — Mobile & Accessibility
- **PWA with offline mode** (students take quizzes offline, sync later)
- **iOS + Android wrappers** (Capacitor / React Native)
- **Accessibility:** text-to-speech, font-size controls, dyslexia-friendly mode, color-blind palettes, keyboard-only navigation
- **Multi-language UI:** start with English, Urdu, Arabic, Spanish, French (i18n-ready Jinja)

## Phase 8 — Marketplace & Community
- **Public quiz library:** users can publish quizzes for others to use
- **Featured / verified educators**
- **Quiz templates by subject + grade**
- **Lead-gen:** capture email of every quiz taker, hand-off to creator's mailing list
- **Paywall a quiz:** creators charge $X to take their quiz (rev-share)

## Phase 9 — Enterprise & Compliance
- **SAML SSO**
- **SOC 2 Type 2 + GDPR compliance documentation**
- **Data residency:** EU vs US data centres
- **Audit log:** every admin action logged
- **Bulk roster import (CSV)**
- **Granular permissions / custom roles**
- **Dedicated tenant option (single-tenant install)**

---

## Recommended sequencing for fastest revenue

1. Finish Phase 1 (this session).
2. Phase 2 (anti-cheating + certificates) → enables selling to certification bodies and trainers — willing to pay $50-200/mo.
3. Phase 4 (multi-tenant + Stripe) → enables actual SaaS billing.
4. Phase 5 (AI) → marketing differentiator. AI quiz from a Word doc is the headline feature that gets people to switch.
5. Phase 6 LMS integrations → unlocks universities and large schools (the biggest contracts).
6. Phase 3 (more question types) → ongoing, every release adds 1–2.
7. Phase 7 mobile → after web product is sticky.
8. Phase 8 marketplace → growth play once user base exists.
9. Phase 9 enterprise → when you're ready to do RFPs.

## What this means for the next 4 weeks

- **Week 1:** Phase 1 (polish + super admin) — DONE-able in 2-3 sessions.
- **Week 2:** Phase 2 (anti-cheating + certificates).
- **Week 3:** Phase 4a (orgs + roles + Stripe sandbox).
- **Week 4:** Phase 5a (AI quiz from text — single OpenAI/Anthropic API call wired in).

After 4 weeks you'd have something pitchable to schools as a paid product.
