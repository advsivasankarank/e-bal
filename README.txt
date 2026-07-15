e-BAL (Balance Sheet Builder)

Deployment layout (important):
The ENTIRE repository must be present on the server filesystem — public/*.php
files use relative includes (require __DIR__ . '/../app/...', '/../config/...')
that reach outside public/. Do NOT upload only the contents of public/.

The cPanel Git Version Control deploy task (.cpanel.yml) already syncs the
whole repo to /home5/etaxadv/public_html/ebal/. What makes public/ the only
web-reachable part is a SEPARATE setting: the subdomain's document root in
cPanel -> Domains must point at .../public_html/ebal/public specifically,
not at .../public_html/ebal. A root .htaccess (repo root) denies all access
as a second line of defense if that document root setting is ever changed.

Steps:
1. Deploy the whole repo (cPanel Git Version Control runs .cpanel.yml automatically)
2. In cPanel -> Domains, confirm ebal.etaxadv.com's document root is
   .../public_html/ebal/public (NOT .../public_html/ebal)
3. Create MySQL DB: etaxadv_ebal
4. Set DB credentials via environment variables / config/env.php (see config/database.php) — do not hardcode
5. Enable Tally XML (Port 9000)
6. Access via browser

Modules included:
- Tally XML Connector
- Mapping Engine
- Basic Report Generator
