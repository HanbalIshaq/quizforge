# QuizForge — Customer Strategy & Build Plan

The point of this doc: **stop building features in random order**. Pick one customer segment, learn what they pay for, and build to win them. Everything else is a distraction.

---

## 1. The market split into 6 segments

Online assessment is not one market — it's six, with very different buyers, prices and feature priorities. You can't beat the leader of every segment, so we pick one.

### Segment A — K-12 classroom engagement (gamified)

| | |
|---|---|
| **Leaders** | Quizizz, Kahoot!, Blooket, Gimkit, Quizalize |
| **Buyer** | Individual teacher (self-serve, $5–20/mo) OR district admin (volume contract, $4–6/student/year) |
| **What they pay for** | Fun for kids (music, memes, leaderboard, mascots), AI quiz generation, paper-mode (offline), LMS integrations (Google Classroom, Canvas) |
| **Public revenue** | Quizizz ~$50M ARR (2024), Kahoot $150M+ revenue (publicly traded) |
| **Why hard to enter** | Teachers already use 1–2 of these for free, network effect (kids know Kahoot music) |
| **QuizForge fit** | **Bad fit** — we don't have the cartoon/music vibe, and these are entrenched |

### Segment B — Audience engagement / live polls

| | |
|---|---|
| **Leaders** | Mentimeter, Slido (Cisco), Poll Everywhere, Wooclap |
| **Buyer** | Conference speaker, internal corporate meeting host, university lecturer ($12–50/host/mo) |
| **What they pay for** | Beautiful slides with embedded polls, word clouds, Q&A moderation, native Zoom/Teams/PowerPoint integration |
| **Public revenue** | Mentimeter ~$35M revenue, Slido acquired by Cisco for >$100M |
| **QuizForge fit** | **Possible** — we already have live polls, but Mentimeter's PowerPoint plugin is the moat |

### Segment C — Pre-hire / candidate assessment

| | |
|---|---|
| **Leaders** | TestGorilla, iMocha, HackerRank, Codility, Vervoe, Mettl |
| **Buyer** | HR director / recruiter ($9–200/job, or enterprise $10K–500K/year) |
| **What they pay for** | Pre-built test library (400+ tests by job role), anti-cheating with webcam, ATS integrations, candidate ranking algorithms |
| **Public revenue** | HackerRank $100M+, Codility $30M+, TestGorilla ~$20M |
| **QuizForge fit** | **Bad fit** — we have no test library; this segment buys *content + tool*, not just tool |

### Segment D — Online training & professional certification ⭐ (recommended)

| | |
|---|---|
| **Leaders** | ClassMarker, Easy LMS, ProProfs, FlexiQuiz, iSpring QuizMaker, Articulate Quizmaker |
| **Buyer** | Training company owner, freelance corporate trainer, vocational school director, small certification body, NGO L&D ($25–500/mo) |
| **What they pay for** | Branded certificates with verification URL, anti-cheating, time limits, white-label, secure exams, exam analytics, payment-per-quiz |
| **Public revenue** | ClassMarker ~$5–20M (private), ProProfs $50M+ (broad suite), FlexiQuiz ~$2–5M |
| **Why approachable** | Buyers are small, decentralised, search Google for tools, switch frequently. ClassMarker UI is from 2012 — open door |
| **QuizForge fit** | **Excellent fit** — we already have 70% of feature parity. Pricing is healthy ($30–100/mo realistic). Buyers don't need ad campaigns to find us — they search "online certification platform" |

### Segment E — High-stakes proctored exams

| | |
|---|---|
| **Leaders** | Respondus LockDown Browser, ProctorU, Honorlock, Examity, Pearson VUE |
| **Buyer** | University registrar, certification board (CFA, AWS, CompTIA, bar exam) |
| **Pricing** | $10–30 **per exam** proctored. Big contracts ($500K+/year for a university) |
| **Public revenue** | ProctorU ~$100M, Examity ~$50M |
| **What they pay for** | Live human proctors, AI face/object detection, lockdown browser app, audit trail for legal disputes |
| **QuizForge fit** | **Bad fit alone** — needs live proctors, lockdown browser, ISO 27001, SOC 2. Maybe a partnership angle later. |

### Segment F — Forms / lead-gen quizzes

| | |
|---|---|
| **Leaders** | Typeform, Tally, Jotform, Outgrow, Interact |
| **Buyer** | Marketer, small business, content creator ($25–80/mo) |
| **Use case** | "Which avocado are you?" personality quizzes, lead capture, customer surveys |
| **QuizForge fit** | **Marginal** — we'd be a worse Typeform. Skip. |

---

## 2. Why segment D is our target

Segment D (online training & professional certification) is where we win because:

1. **Buyers actively search for tools** — they Google "online exam platform with certificates," compare 5–10, pick one in a week. Quick sales cycle, no ad budget needed.
2. **Leader (ClassMarker) hasn't updated its UI in ~10 years** — visitors land on it and look elsewhere. Direct opening for a modern alternative.
3. **Customers willing to pay $30–200/mo** — much higher per-customer revenue than $5/mo K-12.
4. **Long-tail market** — thousands of small training companies, no single dominant player owns >10%.
5. **Lower regulatory friction** — unlike segment E, we don't need SOC 2 or live human proctors on day one.
6. **Geographic flexibility** — works globally. A trainer in Pakistan can sell to a corporate in Dubai using our tool.

### Who specifically buys

Concrete personas in Segment D:

| Persona | Pays | What they desperately need |
|---|---|---|
| **Freelance corporate trainer** | $20–50/mo | A way to give online tests after their workshops + send certificates so corporate clients see professionalism |
| **Small training academy** (5–20 employees) | $50–200/mo | Branded experience for their students, multiple instructors, certificates, anti-cheating for exams |
| **NGO / education non-profit** | $20–100/mo | Cheap or discounted plan, multiple instructors, simple reports for donors |
| **Small certification body** | $200–1000/mo | Verifiable certificates with unique IDs, anti-cheating, exam blueprints, retake fees, payment-per-attempt |
| **Online course creator (Udemy/Teachable alternative)** | $30/mo | A way to gate course completion certificates behind a real test |
| **Vocational school** (driving school, IT cert prep, English language) | $50–500/mo | Bulk student management, exam centre support, certificates |
| **HR department for compliance training** | $100–500/mo | Quarterly compliance tests, anti-cheating, audit trail for regulators |

**Geographic gold-spot:** South Asia, Middle East, Africa, Eastern Europe — where ClassMarker/ProProfs prices feel expensive in local currency and a $9–19/mo plan beats them. Pricing in tiered local currencies is a real moat.

---

## 3. Feature-vs-segment matrix — what to build for whom

Legend: 🟢 must-have · 🟡 nice-to-have · ⚪ don't need

| Feature | A (K-12) | B (Live polls) | **D (Cert/Training)** | C (Hire) | E (High-stakes) |
|---|---|---|---|---|---|
| Question bank with reuse | 🟡 | ⚪ | 🟢 | 🟢 | 🟢 |
| Beautiful UI for kids | 🟢 | ⚪ | ⚪ | ⚪ | ⚪ |
| Word cloud / open polls | ⚪ | 🟢 | ⚪ | ⚪ | ⚪ |
| PowerPoint/Zoom plugin | ⚪ | 🟢 | ⚪ | ⚪ | ⚪ |
| **Certificates (PDF + verify URL)** | 🟡 | ⚪ | 🟢 | 🟡 | 🟢 |
| **Custom branding / white-label** | 🟡 | 🟡 | 🟢 | 🟡 | 🟢 |
| **Anti-cheating (tab/paste/fullscreen)** | 🟡 | ⚪ | 🟢 | 🟢 | 🟢 |
| **Webcam proctoring** | ⚪ | ⚪ | 🟡 | 🟢 | 🟢 |
| Pre-built question library (job roles) | ⚪ | ⚪ | 🟡 | 🟢 | ⚪ |
| **Charge per quiz attempt (paywall)** | ⚪ | ⚪ | 🟢 | ⚪ | 🟢 |
| **Multi-instructor / org accounts** | 🟢 (district) | 🟡 | 🟢 | 🟢 | 🟢 |
| LMS integration (LTI/SCORM) | 🟢 | ⚪ | 🟡 | ⚪ | 🟢 |
| **AI quiz from doc/PDF** | 🟢 | ⚪ | 🟢 | 🟡 | ⚪ |
| Mobile app | 🟢 | 🟢 | 🟡 | 🟡 | 🟢 |
| ATS integrations | ⚪ | ⚪ | ⚪ | 🟢 | ⚪ |
| Lockdown browser (kiosk) | ⚪ | ⚪ | 🟡 | 🟡 | 🟢 |
| SOC 2 / SAML SSO | ⚪ | 🟡 | 🟡 | 🟢 | 🟢 |

Reading the column "D (Cert/Training)" tells us exactly what to build next:

**Top 5 to add for segment D**, in order:
1. **Persistent storage** (Postgres migration) — already discussed, blocker for everything else
2. **Branded PDF certificates with verification URL** — biggest "wow" feature for the target buyer
3. **Multi-instructor / organization accounts** — small training companies have 3–10 trainers
4. **AI quiz from PDF / Word / URL** — saves trainers hours, headline marketing feature
5. **Per-quiz payment (Stripe)** — lets certifiers sell tests, lets us charge subscribers

---

## 4. Recommended pricing for segment D

Once features above are in place, this is what a healthy SaaS pricing model for Segment D looks like:

| Plan | Monthly | Annual (20% off) | Target | Limits |
|---|---|---|---|---|
| **Free** | $0 | $0 | Try it | 1 user, 30 attempts/mo, QuizForge watermark on cert |
| **Starter** | $19 | $190 | Solo trainers, hobbyists | 1 user, 500 attempts/mo, white-label cert, basic anti-cheating |
| **Pro** | $49 | $470 | Small academies, online creators | 3 users, 2,000 attempts/mo, AI quiz gen, custom domain, all anti-cheating |
| **Business** | $149 | $1,430 | Training companies, certification bodies | 10 users, 10,000 attempts/mo, API, webhooks, Zapier, priority support |
| **Enterprise** | from $499 | custom | Universities, large vocational schools | unlimited, SSO, audit log, dedicated support, optional on-prem |

Plus a **self-hosted license** at **$999–$2,499/year** for organisations with data-privacy rules (gov, banks, healthcare).

Realistic 12-month outlook with focused execution:
- 50 paying customers at ~$45 ARPU = **$27,000/year ARR** (year 1)
- 300 customers in year 2 = **~$160K ARR**
- This is a one-person-can-run-it lifestyle business; with a team it can be $1M+ in 3 years.

---

## 5. How a software house would build this

For context — if you handed this project to a typical software house and asked "build a sellable SaaS," here's the professional plan they'd run.

### Phase plan (6 months to revenue)

| Phase | Duration | Goal | Output |
|---|---|---|---|
| **0. Discovery** | 2 weeks | Validate Segment D, interview 10 prospects, finalise feature priorities | PRD, user flows, wireframes |
| **1. Architecture** | 1 week | Tech stack, database design, auth model, scalability bounds | Tech doc, infra plan |
| **2. MVP** | 6 weeks | Core feature loop: signup → create quiz → share → grade → certificate | Working private beta |
| **3. Closed beta** | 4 weeks | 10 paying pilot customers, weekly feedback, bug fixes | Refined product, testimonials |
| **4. Public launch** | 4 weeks | Marketing site, pricing page, Stripe billing, docs, support helpdesk | Public-launchable v1.0 |
| **5. Growth** | ongoing | SEO content, paid ads, partnerships, integrations | Customer pipeline |

QuizForge is around the end of phase 2. The next 4 months should be phases 3–4.

### Team a software house would propose

| Role | FTE | Responsibility |
|---|---|---|
| **Product manager** | 0.5 | Prospect interviews, prioritisation, roadmap |
| **Designer (UX/UI)** | 0.5 | Wireframes, design system, marketing site visuals |
| **Backend engineer** (Python/Postgres) | 1.0 | API, DB, auth, billing, anti-cheating server logic |
| **Frontend engineer** (React or Tailwind+JS) | 1.0 | Student & teacher UI, dashboards, polish |
| **Full-stack / DevOps** | 0.5 | CI/CD, monitoring, security, deployments |
| **QA engineer** | 0.5 | Test suite, manual QA, exploratory testing |
| **Customer success / marketing** | 0.5 | Demos, onboarding, content, support |
| **Total** | **~4.5 FTE for 6 months** | |

A software house typically charges **$80–$200/hr** per FTE, so 6 months = roughly **$300K–$700K** outsourced. (That's why most founders try to do v1 themselves — like we are.)

### What we replicate solo (what they do; how we shortcut)

| Pro process | What it really is | Our shortcut |
|---|---|---|
| Sprint planning (2-week cadence) | Pick 3-5 stories to ship | Pick 1 feature per session |
| Daily standups | Status sync | We chat directly |
| Code review | Catch bugs before merge | I run smoke tests, you visually QA |
| Test coverage | Unit/integration tests | `smoke_*.py` scripts for each feature |
| CI/CD pipeline | Auto-deploy on merge | GitHub → Render auto-deploy ✓ |
| Issue tracker (Jira/Linear) | Track work | This chat + todo list |
| Documentation | Confluence / Notion | `docs/` folder in repo ✓ |
| Monitoring / error tracking | DataDog / Sentry | Render logs (add Sentry later) |
| Support helpdesk | Zendesk / Intercom | Email forwarding (add later) |
| Billing | Stripe + Chargebee | Direct Stripe integration |
| Marketing site | Webflow / Framer | Hosted alongside app or simple Next.js |

This means **two people (you + me, or you + one engineer if you scale)** can absolutely ship phase 3 and start charging customers.

---

## 6. Concrete 90-day plan for QuizForge to be sellable

Pre-condition: data persistence solved (Postgres migration). This blocks everything else.

| Week | Deliverable | Why |
|---|---|---|
| **1** | Migrate to Postgres + persistent hosting | No more data loss |
| **2** | PDF certificates with verification URL | The biggest "shut up and take my money" feature for Segment D |
| **3** | Organisations + multi-instructor accounts | Required so small academies can adopt |
| **4** | Stripe billing (4 plans + free trial) | Now we can collect money |
| **5** | AI quiz generator (paste doc/PDF/URL → questions) | Headline marketing feature |
| **6** | Question bank with categories + tags | Reuse questions across quizzes |
| **7** | Custom branding (logo, primary colour) on student-facing pages | White-label feel |
| **8** | Marketing site (landing page, pricing, signup) | Stop being just an app, become a product |
| **9** | Lead-magnet content (3 blog posts on "online certification") | Free organic traffic |
| **10** | Open beta — invite 20 prospects | First paying customers within this window |
| **11** | Support setup (help docs, contact form, demo Calendly) | Stop you drowning in 1:1 emails |
| **12** | Webcam proctoring (basic — periodic snapshots, no AI yet) | Trust signal for higher-tier buyers |
| **13** | First case study / testimonial published | Social proof to acquire more |

At week 13 you should have: 5–15 paying customers, $200–$800 MRR, real testimonials, a clear next set of features they're asking for.

---

## 7. Where the conversation goes next

Three immediate decisions:

1. **Do you agree Segment D is our target?** If yes, I optimise every future build for it. If you prefer A, B, C or E — different roadmap.
2. **Postgres migration: do we do it now (free, ~1 hour) or upgrade Render to keep SQLite (~$8/mo, 5 min)?**
3. **Pricing plan above — are the numbers reasonable for your market?** We can adjust before we wire up Stripe.

Once those are settled, I have a clear week-by-week plan above to execute.
