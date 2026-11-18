# ChapterScript form and Hostinger setup

## Scope and decisions

- Add the same form fields and PHP/MariaDB behavior as the Maestro site.
- Preserve the ChapterScript design and existing content.
- Adapt form labels to ChapterScript. Keep its existing footer attribution.
- Push the implementation to main, as Joseph requested.
- Joseph handles Hostinger setup and deployment. Provide the steps.
- Joseph and his friend handle DNS after temporary-host testing.
- Keep credentials outside public_html and Git. Use a separate database.
- Do not send automatic email or publish an email fallback.

## Implementation

The homepage contains the form. The PHP endpoint supports JSON and native POSTs.
The backend retains validation, duplicate handling, rate limits, and safe error responses.
The schema and private configuration example are in setup/.
The configuration supports an explicit temporary HTTPS origin without wildcard access.

## Verification and remaining work

PHP syntax, JavaScript syntax, and pure backend validation checks pass.
Browser tests pass for submission data, success, failures, retry preservation, and the honeypot.
The form fits 1280-, 390-, and 320-pixel viewports. Native form markup works without JavaScript.
The existing page has 384-pixel document width at a 320-pixel viewport, even without the form.
The form itself stays within the viewport. The existing overflow is outside this task.
Apache tests pass for security headers, Markdown denial, asset types, and CSP preservation.
Naming, internal-link, anchor, and whitespace checks pass.
The local Herd URL is unavailable. Browser tests use a disposable PHP localhost server.
No local MariaDB binary is available. Database integration tests are included but not run.
Hostinger deployment and database persistence remain unverified.
The temporary hostname is not supplied. Joseph adds it to the private configuration.
README.md and CLAUDE.md still describe the previous static hosting workflow.
Use the deployment steps below for this task. Reconcile permanent references at accepted wrap-up.

## Git delivery

The implementation is committed on local main. The push is blocked:
- HTTPS Git authentication has no available credentials.
- Existing SSH authentication is rejected.
- The connected GitHub integration rejects repository writes with HTTP 403.

No remote branch update or Hostinger deployment occurred.
Push local main from an authenticated Git client before deploying from GitHub.

## Hostinger setup

1. Open the ChapterScript website dashboard in Hostinger.
2. Select Databases → Management.
3. Create a separate MySQL database and user for ChapterScript.
4. Save the full database name, username, password, and host privately.
5. Open phpMyAdmin for that database.
6. Import setup/schema.sql into the empty database.
7. Select a supported PHP version of 8.2 or later with PDO MySQL enabled.
8. Keep PHP displayErrors off and logErrors on.
9. Create private/config.php beside public_html, outside the public document root.
10. Copy setup/config.example.php into that private file.
11. Replace the database placeholders with the complete Hostinger values.
12. Generate the rate-limit secret with the command below.
13. Replace the secret placeholder with the generated value.
14. Add the exact temporary HTTPS origin to allowed_origins, without a path or trailing slash.
15. Keep the two chapterscript.com origins in that list.
16. Restrict configuration access to the hosting account and PHP process.
17. Upload index.html, format.html, player.html, 404.html, assets/, and api/ into public_html.
18. Merge the root .htaccess rules with existing Hostinger rules.
19. Preserve the existing HTTPS redirect and hosting rules.
20. Exclude setup/, tests/, .git/, private/, and all Markdown files from uploads.
21. Open the temporary HTTPS URL and submit the form.
22. Verify the matching row in early_access_applications through phpMyAdmin.
23. Submit the same email again and verify that only one row exists.
24. Configure database backups in Hostinger.
25. Change DNS only after the temporary site works.
26. Verify HTTPS and a form submission on chapterscript.com after the DNS change.
27. Remove the temporary origin when testing ends.

Generate the secret locally:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Keep the secret and database credentials out of chat and Git.
Escape apostrophes and backslashes correctly inside PHP strings.
The endpoint never creates tables. A GET response of 405 does not verify database access.
The existing GitHub Pages site cannot execute PHP; pushing main does not deploy PHP to Hostinger.

Hostinger references:
- https://www.hostinger.com/support/1583542-how-to-create-a-new-mysql-database-in-hostinger/
- https://www.hostinger.com/support/1884149-how-to-import-a-database-with-phpmyadmin-in-hostinger/
