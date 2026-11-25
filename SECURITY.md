# Security policy

## Reporting a vulnerability

Please report security issues privately through GitHub's [private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability) on this repository, not in a public issue.

Include what you found, how to reproduce it and which versions are affected. You'll get a reply within a few days, and a fix will be released as soon as it's ready.

## Supported versions

Security fixes are made for the latest release.

## Handling credentials

This package handles chat and SMS credentials in DSNs. See the README's security notes for how to store them, limit which bridges user-supplied DSNs can use, and protect against requests to private addresses.
