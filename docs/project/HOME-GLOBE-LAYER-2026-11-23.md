# Full-stage infrastructure globe

Base: `develop@3b0aebfb7940c594cd7f2729aee10bb4412187b2`.

On the first desktop slide, the geographic canvas now spans the full hero stage behind the editorial copy. Its responsive square is 76vw (bounded to 740–1440px), roughly twice the prior visible globe diameter at common laptop widths. The globe is at z-index 0, copy at 2 and region controls at 3. A directional fade and a soft background veil behind the copy keep text legible. RTL places the globe left; LTR mirrors it to the right. Region controls use a small glass surface above the geographic artwork. Dragging and keyboard rotation use the existing canvas and handlers.

The change applies only to `#hero-infrastructure` at desktop widths ≥1024px and heights ≥600px. It uses the existing full-viewport introduction layout, including selectors and statistics. Mobile, the other three slide presentations and the five-second carousel behavior retain their current rules. No HTML, JavaScript, translations, account or financial behavior changes.

Two runtime destinations: application/public and the separate public-webroot copy of `studio-carousel.css`. The guarded release at `scripts/home-globe-layer-20261123/` uses the shared release lock, private backups, before/after hashes, targeted activation and homepage-only cache invalidation. The preview shell embeds the real production homepage and loads the proposed stylesheet after its existing assets; it does not modify production application files. Its two temporary files are removed by exact hashes after acceptance.

Status: implementation prepared; visual review, CI and production acceptance pending.
