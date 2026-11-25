# Working in this repository

## UI: follow Refactoring UI

All new or changed UI must follow the project skill at `.claude/skills/refactoring-ui/SKILL.md`. That covers admin pages, Customizer controls, optin and email templates, front-end widgets and the copy in them. Read the skill before designing or building UI, and check the work against its Review Checklist before calling it done.

- Fit the WordPress admin. Use its colours, components and type. Admin pages should feel like part of wp-admin, not a separate app. Apply the skill's hierarchy, spacing, contrast and copy rules inside that frame.
- Use a fixed spacing and type scale, not arbitrary pixel values. Never use pure black text. De-emphasise with lighter text colours rather than smaller sizes alone. Keep visible focus states. Touch targets must be at least 44px on mobile. Pages must work at 320px wide.
- Write plain, specific copy: what the thing does for the site owner. Avoid filler and AI-sounding phrasing (see "Writing Copy That Doesn't Read Like AI").
- UI that predates this rule is brought into line over time, via a roadmap item. When you touch an older screen, fix what you change, but don't widen the change to restyle the whole screen.
