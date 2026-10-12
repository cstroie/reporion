# Security policy

Reporion stores medical documents, so please report vulnerabilities privately.

**Do not open a public issue.** Use GitHub's
[private vulnerability reporting](https://github.com/cstroie/reporion/security/advisories/new)
for this repository.

Particularly relevant: anything that exposes `data/` or `conf/` over HTTP, lets an anonymous or
unentitled caller read a non-public page (search, tree, sitemap, feed, API), leaks a patient path
or name into a log, URL, audit line or AI prompt, or alters a signed revision.

Please never include real patient data in a report. The project is pre-alpha; only the latest
`main` is supported.
