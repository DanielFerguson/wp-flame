# WP Flame — Commercial and Marketing Strategy

**Status:** Product and marketing handoff

**Last updated:** 2026-07-10

**Engineering dependency:** [Public v1 Release Roadmap](ROADMAP.md)

## Purpose

This document gives the product, commercial, and marketing website work a shared source of truth.

It separates:

- What WP Flame is.
- Who it is for.
- What exists in the current codebase.
- What public v1 must deliver.
- What Community and Pro could contain.
- Which pricing and packaging ideas are hypotheses.
- What must be tested before broad launch.
- Which claims the website may and may not make.

The website must follow the implementation status in `ROADMAP.md`. A feature in this brief is not a public promise until its roadmap acceptance criteria and evidence gate pass.

## Executive recommendation

Launch WP Flame as a WordPress-native performance monitoring and diagnosis product, not as another one-click speed optimization plugin.

Recommended category:

> The performance flight recorder for dynamic WordPress.

Recommended core promise:

> Capture a real WordPress request, see where the observed server-side time went, identify the largest supported contributor, and verify whether a change improved it.

Recommended route to market:

1. Paid, founder-led design-partner programme.
2. A genuinely useful Community edition.
3. A separately distributed Pro add-on for ongoing monitoring, longer history, comparisons, support, and professional workflows.
4. Local-first operation at launch.
5. Optional fleet/cloud services only after recurring multi-site demand is proven.

Diagnosis and verification are the product. The initial release should not automatically alter customer sites.

## Product category and boundaries

### Public v1 will be

- WordPress-specific performance monitoring.
- A bounded real-request recorder.
- A request timeline with flame-graph interaction.
- A diagnosis and evidence tool.
- A before/after verification workflow.
- A local-first product that can work without root access or a PHP profiling extension.

### WP Flame is not

- A page-cache plugin.
- An image, CSS, or JavaScript optimization suite.
- A PageSpeed or Core Web Vitals replacement.
- A full PHP call-stack profiler.
- A generic infrastructure observability platform.
- A promise that every host exposes identical detail.
- An automatic code-rewriting or plugin-disabling tool.
- A security-monitoring product.

### Product truth

WP Flame measures observed WordPress server execution from the earliest available WP Flame bootstrap point. It cannot see requests that never execute PHP, and it does not currently observe every arbitrary PHP function.

This boundary is a positioning advantage when described well:

> WP Flame is built for dynamic requests that still execute WordPress.

## Ideal customer profiles

### Primary ICP — WordPress agencies and freelancers

Profile:

- Maintains approximately 5–50 client sites.
- Supports WooCommerce, memberships, publishing, page builders, REST/AJAX, cron, or other dynamic installations.
- Can act on a diagnosis or hand it to a developer.
- Currently relies on Query Monitor, host support, plugin deactivation, log inspection, generic APM, or guesswork.
- Loses billable time reproducing intermittent performance incidents.
- Needs evidence that can be explained to a client or plugin vendor.

Buying triggers:

- A client reports slow admin, checkout, editing, search, API responses, or intermittent timeouts.
- A plugin, theme, or core update appears to cause a regression.
- An agency needs to justify optimization work.
- A developer needs reproducible evidence for a support escalation.

Why this customer pays:

- One diagnosis can save hours of senior development time.
- Evidence reduces unproductive plugin/host blame.
- Before/after results help demonstrate agency value.
- Ongoing monitoring reduces reactive support.
- Multi-site workflows create recurring rather than one-off value.

### Secondary ICP — Revenue-critical dynamic sites

Profile:

- Runs WooCommerce, memberships, courses, bookings, donations, publishing, or lead-generation workflows.
- Has a developer, agency, or technically capable operator.
- Depends on uncached checkout, admin, search, logged-in, API, or background work.
- Experiences revenue or operational impact when dynamic requests slow down.

Buying triggers:

- Slow cart or checkout.
- Admin/editor delays affecting staff.
- External payment, shipping, CRM, search, email, or licensing API latency.
- A recent deployment or update made a workflow slower.

### Tertiary ICPs

- Plugin and theme vendors investigating customer performance reports.
- Managed WordPress providers improving support diagnostics.
- Headless/WPGraphQL teams, once resolver instrumentation has release evidence.
- Multisite owners with an internal development team.

### Poor-fit customers

- Hobby or brochure sites seeking a one-click PageSpeed score increase.
- Buyers whose main need is caching, image optimization, or asset minification.
- Users with nobody able to act on technical recommendations.
- Teams already satisfied with host-provided APM and server-level engineering support.
- Buyers expecting automatic repairs rather than diagnosis and verification.

## Jobs to be done

### Main job

> When a WordPress request is slow, show me where the observed time went, identify the likely owner, and give me evidence for the next action.

### High-value jobs

- Diagnose slow wp-admin and editor screens.
- Find the largest measured contributors to WooCommerce cart and checkout latency.
- Identify slow or repeated database queries and their supported WordPress source.
- Find external services delaying a request.
- Diagnose slow REST, AJAX, cron, CLI, and supported GraphQL operations.
- Investigate a suspected regression after a plugin, theme, core, configuration, or deployment change.
- Compare alternative plugins or configurations.
- Create evidence for a plugin vendor, developer, or hosting support ticket.
- Produce a client-facing performance report.
- Verify that implemented work produced a measurable improvement.

## Positioning

### Positioning statement

For WordPress agencies and operators of dynamic sites who need to explain slow server-side requests, WP Flame is a WordPress-native performance flight recorder that captures bounded real requests and turns them into interactive, attributable evidence. Unlike page-speed tests, cache plugins, current-request debug panels, or generic server APMs, WP Flame explains observed WordPress execution without requiring root access or a hosting-specific platform.

### Message pillars

#### 1. Evidence, not guesswork

Show milliseconds, request context, ownership, supporting samples, and capture confidence.

#### 2. WordPress-native understanding

Use WordPress concepts such as plugins, themes, hooks, queries, HTTP requests, cron, REST, AJAX, and lifecycle phases rather than exposing only generic PHP frames.

#### 3. From trace to action

Translate measurement into impact, confidence, next step, developer handoff, and verification.

#### 4. Built for real sites

Use bounded storage, sampling, modes, visible capability reporting, and compatibility-aware fallbacks.

#### 5. Local-first by default

Keep trace data on the customer's WordPress installation unless they explicitly choose a future connected service.

### Competitive frame

- A page-speed test reports the browser-facing symptom.
- A cache plugin bypasses some public PHP requests.
- Query Monitor helps a developer inspect the current request.
- A generic APM offers broader infrastructure visibility but often requires server access and specialist knowledge.
- WP Flame records supported WordPress request evidence, retains it, explains it, and helps verify the fix.

The commercial wedge is not the graph alone. It is the combination of:

- Real dynamic request evidence.
- WordPress-specific semantics.
- Low-friction installation.
- Honest capability reporting.
- Plain-language prioritization.
- Before/after proof.
- Agency reporting and recurring monitoring.

## Current feature inventory and marketing status

This table prevents the website from treating existing code as validated public capability.

| Capability | Current implementation | Public-v1 requirement | Marketing status now |
|---|---|---|---|
| Local trace storage | Custom WordPress table with retention | Quotas, resumable pruning, health, truthful persistence | Describe only as local-first development direction |
| Lifecycle timeline | Early mu-plugin plus degraded fallback | Correct boundaries, request-specific phases, explicit start gap | Do not claim complete request timing |
| Safe/Standard/Deep modes | All three exist | Capability report and time-boxed Deep | May describe modes only with limitations |
| Database spans | Core-compatible `$wpdb` replacement | Strategy decision, custom-DB fallback, overhead evidence | Do not claim every query or every host |
| HTTP spans | WordPress HTTP API hooks | Detail UI, failures, bounds, overhead evidence | Say WordPress HTTP API, not every network call |
| Callback spans | Deep-mode `WP_Hook` wrappers | One-shot use, limits, compatibility evidence | Do not claim every PHP function |
| WPGraphQL spans | Intended root operation/resolver support | Current end-to-end operation/resolver tests | Do not market until gate passes |
| Interactive flame graph | SVG zoom and tooltip UI | Metadata panel, accessibility, incomplete states | Safe to show as product direction, not final UX |
| Performance score | Five-factor score | Score v2, unknown factors, cohorts, versioning | Do not use current scores in proof or comparisons |
| Performance findings | Seven generic rules | Evidence, confidence, impact, action, verification | Describe as early rule engine only |
| Historical dashboard | Lists and aggregate cards | Request segmentation, consistent cohorts, p50/p95 | Do not claim site-wide conclusions yet |
| Trace This Page | Forced trace flow | Standard/Deep choice and persistence truth | Suitable beta workflow after correction |
| Performance budgets | Time/query threshold notices | Route and capability awareness | Do not position as regression monitoring yet |
| WP-CLI | List, show, and prune | Keep compatible with schema and health changes | Safe developer feature after v1 validation |
| Privacy controls | Conservative defaults, exporter/eraser | Full data inventory, policy text, redaction and export parity | Local-first is valid; legal-compliance claims are not |

## Public v1 value proposition

Public v1 should make one complete promise:

> Diagnose a slow dynamic WordPress request and prove whether the next change improved it.

The minimum complete experience is:

1. Run a health check.
2. Choose a page or workflow.
3. Capture a bounded trace.
4. See capture completeness.
5. Receive the top measured opportunities.
6. Inspect supporting evidence.
7. Make or delegate a change.
8. Compare the same workflow before and after.
9. Export a redacted report.

Continuous monitoring, longer history, comparable workflow tracking, professional reporting, and later fleet workflows are the natural recurring paid value.

## Recommended Community and Pro boundary

This is the leading packaging hypothesis and must be validated with customers before being treated as final. Run the first paid design-partner transactions through manual billing or a lightweight checkout before committing to the full licensing architecture. The M7 edition boundary must remain reversible until those paid signals are reviewed.

For `1.3.0-rc.1`, the implemented offer is therefore one complete directly supplied paid design-partner package, not separate Community and Pro binaries. `docs/architecture/RC-DISTRIBUTION.md` is the candidate-specific decision. The Community/Pro material below remains a hypothesis for the decision after paid evidence.

The free edition must solve a complete problem. WP Flame should monetize ongoing confidence and professional workflow, not hide the basic diagnosis.

### WP Flame Community

Promise:

> Diagnose where this slow WordPress request spent observed server-side time.

Include:

- Manual Trace This Page.
- Safe, Standard, and focused one-shot Deep diagnostics.
- Interactive trace and span details.
- Plugin, theme, callback, query, HTTP, and lifecycle attribution where supported.
- Visible capture capabilities and limitations.
- Bounded recent local history.
- Basic top-contributor findings.
- Request-type and route filtering.
- A single-trace redacted export.
- Privacy, health, storage, and retention controls.
- No account requirement.
- No expiry or deliberately unusable results.

Community should let a user reproduce one incident, identify a credible measured contributor, and share the evidence.

### WP Flame Pro

Promise:

> Monitor important WordPress workflows over time and compare changes with confidence.

Public-v1 Pro candidates:

- Automatic controlled sampling.
- Longer configurable history.
- Route p50, p95, and distributions.
- Trace and before/after comparisons.
- Saved monitored workflows and filters.
- Workflow-specific aggregation and guidance.
- Client-ready redacted reports and CSV export.
- Priority support.

Community and Pro must use the same trace schema, attribution truth, scoring rules, and essential performance findings. Paid workflow-specific guidance may add context and aggregation, but it must not make a Community trace less accurate or withhold the basic diagnosis.

Post-v1 Pro candidates, subject to validation:

- Automatic plugin, theme, core, configuration, and deployment change markers.
- Regression alerts and scheduled baselines.
- WooCommerce transaction packs.
- Advanced REST, AJAX, cron, and supported GraphQL workflow packs.
- PDF and white-label agency reporting.
- Multisite and multi-site fleet workflows.
- Team annotations and resolution status.
- Configuration export/import across sites.

### Optional connected service later

Defer until customers prove fleet demand:

- Central multi-site dashboard.
- Remote alerts.
- Hosted secure report links.
- Fleet-level comparisons.
- Shared rule/intelligence updates.
- Team access.

Only redacted aggregates or explicitly selected traces should leave a site, and only with explicit consent.

## Pricing hypotheses

### Public pricing hypothesis A

- Personal: **$59/year**, one production site.
- Studio: **$149/year**, five production sites.
- Agency: **$299/year**, 25 production sites.
- Larger fleets or hosting partners: negotiated.

Rules to test:

- Staging and local installations do not consume activations.
- Annual billing first.
- Clear automatic-renewal notice.
- 14-day refund period.
- No lifetime license.
- Expiration leaves installed local functionality running while updates, support, and connected services stop.
- Price by active production sites, not trace volume, for the first public offer.

### Public pricing hypothesis B

If beta and later post-v1 customers consistently value monitoring, reports, and regression protection more highly:

- **$99/year**, one site.
- **$249/year**, ten sites.
- **$499/year**, 50 sites.

Do not choose between these ladders using survey answers alone. Use real checkout, deposits, or paid beta conversion.

### Design-partner founding offer

Test:

- **$99/year for up to five sites.**
- Direct founder onboarding.
- Weekly feedback and issue review.
- Renewal converts to the final public tier with advance notice.
- No lifetime entitlement.
- Participants must bring a real slow workflow.
- Obtain separate written authorization for every customer or client production site.
- Obtain separate consent for trace review, anonymized research notes, testimonials, and case studies; participation alone grants none of these.

This tests willingness to pay for the software rather than only for consulting.

### Optional expert service

Test a fixed-scope **WP Flame Performance Review** at **$199–299**:

- One defined slow workflow.
- Controlled capture.
- Findings review.
- Developer/agency handoff report.
- One bounded baseline/after cohort comparison.

Treat this as an add-on and research tool, not the core business model.

### Market anchors

Adjacent annual pricing checked on 10 July 2026 includes:

- [WP Rocket](https://wp-rocket.me/pricing/): $59 for one site, $119 for three, and $299 for 50.
- [FlyingPress](https://flyingpress.com/pricing/): $59 for one, $109 for three, and $229 for 25.
- [Perfmatters](https://perfmatters.io/pricing/): $29.95 for one, $59.95 for three, and $124.95 for unlimited sites.
- [Code Profiler](https://nintechnet.com/codeprofiler/): $79 for 10 sites and $199 for 50.
- [F12 Profiler](https://www.forge12.com/de/shop/f12-profiler): approximately €58 for one, €118 for five, and €237 for 25.

These are positioning anchors, not proof that WP Flame should copy their tiers. Displayed tax treatment differs: the F12 figures include German VAT, while the quoted USD prices generally exclude taxes that may apply. WP Flame must earn recurring pricing through monitoring, comparison, evidence, reporting, support, and agency workflow.

## Commercial validation programme

### Experiment 1 — Problem interviews

Recruit:

- 15 agencies or freelancers.
- 10 WooCommerce or membership operators.
- Five plugin vendors or hosting support teams.

Discuss the last real performance incident, not hypothetical interest.

Questions to validate:

- How often do meaningful incidents occur?
- Which requests are affected?
- How are they diagnosed now?
- Who performs the work?
- What does the incident cost in time, support, or revenue?
- What evidence is needed before a customer approves work?
- Which results need to be shared?
- What would justify annual rather than one-off payment?

Provisional pass signals:

- At least 60% experienced two or more meaningful incidents in the prior six months.
- At least 50% used a developer, agency, host escalation, or paid tool.
- At least 40% identify a recurring workflow WP Flame could replace.
- At least 30% accept the proposed annual range without requiring automatic optimization.

### Experiment 2 — Concierge diagnosis

With explicit permission, run controlled WP Flame builds on 15–20 real slow sites.

Measure:

- Activation to first useful trace.
- Time to credible diagnosis.
- Whether the finding changes the next action.
- Whether a developer agrees with the attribution.
- Whether a fix can be verified.
- Mode-specific overhead.
- Compatibility incidents.
- Support effort required.

Provisional pass signals:

- First useful trace in under five minutes on 80% of eligible sites.
- Credible actionable finding in under 15 minutes for 70% of eligible incidents.
- At least 70% rate the diagnosis/report 8 out of 10 or higher.
- No critical compatibility failures.
- All overhead remains within the published mode-specific budgets.

### Experiment 3 — Paid design-partner beta

Offer the real founding plan rather than a free beta.

Provisional pass signals:

- Ten paying partners.
- At least 30% conversion from qualified demonstrations.
- At least 70% activate on a second site or return for another incident.
- Fewer than 20% request refunds.
- At least three permissioned before/after case studies.
- At least half express a credible intention to renew.

### Experiment 4 — Positioning test

Test two primary frames:

- A: **Stop guessing why WordPress is slow.**
- B: **The performance flight recorder for dynamic WordPress.**

Test use-case traffic separately:

- Slow wp-admin.
- Slow WooCommerce checkout.
- Plugin-update regression.
- Slow external API.
- REST/AJAX/cron performance.

Provisional pass signals:

- At least 8% of qualified visitors start the demo or Community installation.
- At least 20% of demo viewers reach pricing.
- One or two use cases consistently produce materially higher activation.

Do not scale paid acquisition until a clear use-case winner and reliable activation flow exist.

### Experiment 5 — Price test

Present real checkout or refundable deposit options to matched qualified cohorts:

- $59 one-site plan.
- $79 one-site plan.
- $99 one-site plan with monitoring/reporting emphasized.

Evaluate:

- Revenue per qualified visitor.
- Refund-adjusted conversion.
- Support cost per account.
- Site expansion.
- Renewal intent.

Do not optimize for conversion rate alone.

### Experiment 6 — Community-to-Pro boundary

Test whether professionals receive enough value in Community to trust the result and enough recurring pain to need Pro.

With explicit consent, measure:

- Installation.
- First trace started.
- First trace completed.
- Capture capability report viewed.
- First actionable finding viewed.
- Second diagnosis session.
- Before/after comparison.
- Report export.
- Pro pricing viewed.
- Pro purchase.

Initial targets:

- 50% of activated installations complete a trace.
- 40% view an actionable finding.
- 25% return within 30 days.
- 5–8% of activated professional users enter a Pro purchase flow.
- 2–4% overall activated free-to-paid conversion after the product matures.

### Experiment 7 — Report and agency value

Give agencies alternative outputs:

- Technical trace link.
- Plain-language report.
- Before/after comparison.
- White-label client report.
- Plugin-vendor issue report.

Measure what they actually send, not what they say they prefer. Move reporting into the initial Pro tier only if it materially affects conversion, retention, or billable workflow.

## KPI framework

### North-star metric

> Opted-in sites completing a compatible before/after comparison with a measured improvement in the previous 90 days.

Keep this comparison local unless explicit product-analytics consent exists. Count only compatible sample cohorts that meet the minimum evidence threshold in the release roadmap.

### Activation

- Installation-to-first-trace rate.
- Median time to first completed trace.
- Median time to first actionable finding.
- Trace failure rate.
- Percentage of users who understand the top finding without documentation.
- Percentage of users who see a complete versus degraded capture.

### Product value

- Actionable finding rate.
- Median time to credible diagnosis.
- Percentage of findings supported by a compatible baseline/after cohort comparison.
- Before/after comparisons completed.
- Reports exported.
- Sites returning for a second diagnosis.
- Pro routes or workflows actively monitored.
- Agency sites monitored per account.

### Commercial

- Qualified visitor-to-demo/install rate.
- Community activation rate.
- Activated free-to-paid conversion.
- Demonstration-to-paid conversion.
- Annual recurring revenue.
- Average revenue per account.
- Site expansion revenue.
- Refund rate.
- Gross and net revenue retention.
- Account and site churn.
- Partner-sourced revenue.
- Customer acquisition cost and payback once paid acquisition begins.

### Product reliability guardrails

- Sampled-out request overhead.
- Captured-request overhead by mode.
- Fatal error or request-interference rate.
- Instrumentor failure rate.
- Trace truncation rate.
- Storage growth per site.
- Cleanup success and backlog age.
- Compatibility-related support tickets.
- Deactivation after first trace.

### Support

- Tickets per 100 active sites.
- First-response time.
- Resolution time.
- Percentage caused by compatibility.
- Documentation deflection.
- Most common unsupported host/plugin combinations.
- Founder time per design-partner account.

## Website messaging

### Paid-beta hero

**Bring us the WordPress workflow that is slowing you down.**

Join the paid design-partner programme to capture a real dynamic request, review the measured contributors with us, and help shape the public release.

Primary CTA: **Apply for the paid design-partner programme**

Candidate-ready landing, application, disclosure, fulfillment, and onboarding copy is maintained in `docs/commercial/RC-OFFER-AND-ONBOARDING.md`. It must remain subordinate to the final seller, checkout, terms, support, privacy, refund, and compatibility inputs.

Secondary CTA: **Explore a sample trace**

### Community/public-v1 hero after M8

**Stop guessing why WordPress is slow.**

WP Flame records real dynamic WordPress requests and shows where supported server-side spans spent time—plugins, observed callbacks, compatible database queries, supported WordPress HTTP API calls, and lifecycle work—with evidence for the next action.

Primary CTA: **Trace a slow page free**

Secondary CTA: **Explore a sample trace**

Proof strip:

> Local-first · No server agent required · Bounded capture · Built for dynamic WordPress

### Alternative category-led hero

**The performance flight recorder for dynamic WordPress.**

Capture dynamic requests that still execute WordPress, find the largest measured contributor, and verify whether your change worked.

### Problem section

**Page-speed tests show the symptom. WP Flame shows the measured contributors inside WordPress.**

A slow checkout, admin screen, API request, or cron task is rarely explained by a browser score. WP Flame captures supported server-side work and attributes supported spans to the best available WordPress owner where possible.

### How it works

1. **Capture.** Trace one request manually or monitor a controlled sample.

2. **Understand.** See where the observed time went, what was captured, and which component contributed most.

3. **Act and verify.** Follow or hand off the evidence, repeat the same workflow, and compare the result.

### Dynamic-workflow section

**Built for dynamic requests that still execute WordPress**

- WooCommerce checkout and cart.
- wp-admin and editing.
- Logged-in membership workflows.
- REST and AJAX.
- Cron and background work.
- External APIs.
- Supported GraphQL operations once compatibility is verified.

### Agency section

**Turn a vague client complaint into a defensible action plan**

- Reproduce the incident.
- Show the measured contributor.
- Export the evidence.
- Implement or delegate the next action.
- Prove the improvement.

### Pricing headline

**Community for one-off diagnosis. Pro for continuous confidence.**

Community helps diagnose a slow request now. Pro tracks comparable workflows over time and creates professional evidence.

## Homepage structure

Recommended order:

1. Hero, primary CTA, and interactive sample.
2. High-intent problem statement.
3. Three-step capture, understand, verify workflow.
4. Dynamic requests that still execute WordPress.
5. Example Top Opportunities output.
6. Interactive flame graph and detail panel.
7. Before/after comparison.
8. Agency/client evidence section.
9. Local-first privacy and capability transparency.
10. Community versus Pro.
11. Benchmarks and compatibility proof.
12. Case study.
13. FAQ.
14. Final CTA.

Avoid leading with a wall of profiler terminology or a generic feature grid.

## Recommended website information architecture

### Core pages

- Home.
- Product.
- Interactive demo trace.
- Community versus Pro.
- Pricing.
- Documentation and quickstart.
- Benchmarks.
- Compatibility and known limitations.
- Privacy and data handling.
- Support.
- Changelog.

### Use-case pages

- Diagnose slow wp-admin.
- Diagnose slow WooCommerce checkout.
- Find external APIs slowing WordPress.
- Investigate a plugin-update regression.
- Diagnose REST and AJAX latency.
- Diagnose cron and Action Scheduler.
- Create a client performance report.

### Comparison and education pages

- WP Flame versus Query Monitor.
- WP Flame versus generic APM.
- WP Flame versus a cache plugin.
- What a WordPress request timeline can and cannot measure.
- Safe versus Standard versus Deep.

Comparison pages should acknowledge what the alternative does well and explain the workflow difference, not manufacture feature-checklist victories.

## Claims guardrails

### Safe claims after the relevant v1 gate passes

- “Helps identify where a WordPress request spends observed server-side time.”
- “Attributes supported spans to plugins, themes, callbacks, queries, external services, and WordPress lifecycle work.”
- “Records a bounded sample of eligible requests.”
- “Does not require a server-level agent.”
- “Stores trace data on the WordPress site by default.”
- “Shows which capture capabilities were available for each trace.”
- “Compares measured performance before and after a change.”
- “Continues in visibly degraded mode when early loading or an instrumentor is unavailable.”

### Claims requiring published evidence

- Exact overhead percentages or milliseconds.
- “Production-safe.”
- Compatibility with a named host, plugin, database layer, or cache.
- Accuracy compared with another profiler or APM.
- Time saved per incident.
- Percentage performance improvements.
- Number of requests, sites, or rows supported.
- Any fastest, most accurate, lowest-overhead, or unique-market claim.

Every quantified claim needs:

- A date.
- Methodology.
- Environment.
- Sample size.
- Comparison basis.
- Limitations.

### Claims to avoid

- “Zero overhead.”
- “Finds every slow function.”
- “Captures every query.”
- “Always identifies the root cause.”
- “Measures the full request from the web server.”
- “Guarantees a faster website.”
- “Automatically optimizes WordPress.”
- “Improves SEO, rankings, conversions, or revenue.”
- “Works on every host.”
- “Replaces New Relic, Blackfire, or Tideways.”
- “Measures CPU” when the product measured wall time.
- “Measures real-user Core Web Vitals” without browser field telemetry.
- “GDPR compliant” as a blanket legal promise.
- Calling the largest measured contributor the definitive cause without evidence and confidence.

Prefer:

- “Largest measured contributor.”
- “Likely cause.”
- “Observed in N compatible traces.”
- “Database capture unavailable on this stack.”
- “Estimated impact.”
- “Verify with a before/after capture.”

## Objection handling

### “Does WP Flame make my site faster automatically?”

No. It identifies measured causes, recommends the next action, and verifies the result. Automatic site modification creates compatibility risk and is not part of v1.

### “How is this different from a cache plugin?”

A cache plugin can bypass PHP for some public pages. WP Flame diagnoses the dynamic requests that still execute WordPress, such as checkout, admin, logged-in pages, APIs, AJAX, and background jobs.

### “How is this different from Query Monitor?”

Query Monitor is an excellent developer tool for the current request. WP Flame's intended v1 differentiation is bounded history, guided diagnosis, capability-aware findings, compatible before/after comparison, and agency evidence. Automated regression monitoring is a later expansion.

### “How is this different from New Relic or a hosting APM?”

Those products provide broader infrastructure visibility and may offer deeper PHP instrumentation. WP Flame focuses on low-friction WordPress semantics and workflows without requiring a particular host or server agent.

### “Will it slow the site down?”

Every profiler adds some work. WP Flame will publish mode-specific overhead evidence, use bounded capture, and reserve Deep mode for focused diagnostics. Do not answer with an unsupported fixed number.

### “Where does my data go?”

Trace data remains in the site's WordPress database by default. Any future licensing, analytics, fleet, or report service must be separately disclosed and consented to.

## Required launch assets

### Product proof

- Public interactive sample trace.
- Two-minute product walkthrough.
- Trace a slow request in five minutes quickstart.
- Published overhead methodology.
- Mode-by-mode benchmark results.
- Compatibility matrix.
- Known-limitations page.
- Example slow-query diagnosis.
- Example external-API diagnosis.
- Example WooCommerce diagnosis.
- Example before/after comparison.
- Sample client/developer report.

### Trust

- Privacy policy.
- Terms and refund policy.
- GPL explanation.
- Data inventory, retention, and redaction documentation.
- Responsible disclosure process for WP Flame itself.
- Clear support scope and response target.
- Changelog and immutable release notes.
- License-expiry and update behavior.
- Service status page if connected services are introduced.

### Marketing

- Homepage.
- Pricing.
- Community versus Pro.
- Interactive demo.
- Use-case pages.
- Comparison pages.
- Three customer case studies.
- Agency one-pager.
- Sample report download.
- Email onboarding sequence.
- Design-partner outreach sequence.
- Screenshot and short-video library using real validated product states.

## Launch phases

### Phase 1 — Research and concierge diagnosis

- Interview target users.
- Run controlled real-site diagnoses.
- Measure time to value and support effort.
- Finalize the measurement contract and the most valuable use cases.
- Do not run broad public acquisition.

### Phase 2 — Paid design-partner beta

- 10–20 paid partners.
- Founder-led onboarding.
- Weekly trace and evidence review.
- Conservative claims.
- Produce benchmarks, compatibility evidence, and first case studies.

### Phase 3 — Community public release

- Useful one-off diagnosis.
- WordPress.org listing if that channel is chosen.
- Interactive demo.
- Fast onboarding.
- Public documentation, benchmarks, and compatibility matrix.
- Capture explicit interest in Pro workflows.

### Phase 4 — Pro general availability

Launch only after:

- At least three strong before/after case studies.
- Stable monitoring, comparison, and report workflows.
- Published mode-specific overhead evidence.
- Reliable licensing and update delivery.
- Support documentation and response expectations.
- At least ten paying beta renewals or explicit continuation commitments.

### Phase 5 — Agency fleet product

Only after multi-site demand is proven:

- Central dashboard.
- Fleet alerts.
- Hosted share/report workflows.
- Central configuration and retention.
- Partner or reseller plans.

## Distribution, GPL, and updates

Recommended structure:

1. WP Flame Community on WordPress.org.
2. WP Flame Pro as a separate off-directory add-on.
3. Optional substantive connected services later.

The WordPress.org edition must not be trialware or contain locally implemented features that are merely unlocked by payment. Follow the [WordPress.org detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).

WP Flame can be sold under the GPL, but customers who receive GPL code may redistribute it. The durable commercial value must therefore be:

- Trusted updates.
- Compatibility work.
- Support.
- Expert rules.
- Reports and workflow.
- Brand and customer relationship.
- Connected services where they deliver substantive value.

References:

- [WordPress licensing](https://wordpress.org/about/license/)
- [GNU GPL charging and redistribution FAQ](https://www.gnu.org/licenses/gpl-faq.en.html#DoesTheGPLAllowMoney)
- [WordPress plugin privacy guidance](https://developer.wordpress.org/plugins/privacy/)
- [WordPress trademark policy](https://wordpressfoundation.org/trademark-policy/)

## Sales and support motion

### Design-partner sales

- Founder-led discovery call.
- Require a real incident.
- Demonstrate an interactive sample first.
- Sell the outcome and support relationship, not access to unfinished telemetry.
- Document the customer's current diagnosis cost.
- Agree on a specific success criterion.
- Capture permission separately for testimonials and case studies.

### Public self-service

- Community is the product-led entry.
- Demo trace precedes installation for lower-risk evaluation.
- Pro upgrade appears after recurring value moments such as a second incident, comparison, monitoring need, or report.
- Avoid aggressive admin notices.
- Keep upgrade prompts contextual and dismissible.

### Agency channel

- Offer staging/local activations without consuming licenses.
- Provide configuration export/import.
- Make reports and developer handoff first-class.
- Consider an agency partner directory only after product quality and support capacity are proven.
- Do not add reseller complexity before direct agency demand exists.

### Support hypothesis

- Community: documentation and public support channel.
- Personal/Studio Pro: ticket support with documented response expectations.
- Agency: priority queue and onboarding materials.
- Expert diagnosis: separately scoped paid engagement.

Track support cost before promising fast SLAs.

## Open commercial decisions

| Decision | Recommended starting position | Must be decided before |
|---|---|---|
| Primary buyer | Agencies managing dynamic WordPress sites | Paid beta |
| Initial vertical | WooCommerce plus slow admin/dynamic requests | Website use-case build |
| Community/Pro model | Useful Community plus separate Pro add-on | Edition architecture |
| Paid boundary | Monitoring, history, compatible comparisons, reports, and professional workflow; automated regression alerts later | Pro implementation |
| Price metric | Active production sites; staging/local free | Checkout implementation |
| Annual price | Test $59/$149/$299 against higher ladder | Public pricing |
| Billing | Annual first | Paid beta |
| License expiry | Local features continue; updates/support/services stop | Licensing code |
| Refund | Test 14 days | Public checkout |
| Hosted service | Defer until fleet demand is proven | Post-v1 planning |
| Automatic actions | Diagnose and verify only | Product copy |
| Product analytics | Off by default and explicit opt-in | Community release |
| Agency reporting | Pro launch candidate, validate in beta | Pro scope lock |
| White label | Later agency tier | Post-launch |
| Expert service | Optional fixed-price add-on | Paid beta |
| Lifetime pricing | Do not offer | Pricing page |
| Checkout/vendor | Evaluate tax, licensing, updater, and data terms | Pro build |
| Support SLA | Set from measured beta capacity | Pro general availability |
| Product name/domain | Complete normal trademark and domain clearance | Public website |
| Currency/tax presentation | Decide primary currency and regional tax handling | Public checkout |

## Marketing handoff checklist

Before publishing or revising the website:

- [ ] Read the current milestone status in `ROADMAP.md`.
- [ ] Mark each proposed feature as current, RC-required, Pro hypothesis, or post-v1.
- [ ] Link quantified claims to dated evidence.
- [ ] Use observed, measured, supported, likely, and confidence language accurately.
- [ ] Never translate a roadmap item into present-tense copy before its release gate passes.
- [ ] Keep performance monitoring and diagnosis as the only product category.
- [ ] Lead with dynamic use cases and outcomes, not profiler internals.
- [ ] Show a real interactive trace rather than only screenshots.
- [ ] Explain what caching and generic APM do well.
- [ ] Put compatibility, overhead, privacy, and limitations near the buying decision.
- [ ] Keep Community genuinely useful.
- [ ] Treat pricing, tier boundaries, and conversion targets as hypotheses until paid tests validate them.

## Recommended next commercial actions

1. Recruit the first five agency interviews.
2. Select three real slow-workflow examples for the demo and benchmark fixtures.
3. Prototype the interactive public sample trace.
4. Draft the landing page using the two positioning variants.
5. Define the paid founding offer and real checkout/deposit test.
6. Create the case-study template before the first partner diagnosis.
7. Decide the Community/Pro boundary before licensing work begins.
8. Keep the website in evidence-gathering mode until the M8 release gate passes.
