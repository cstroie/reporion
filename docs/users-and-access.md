# Users, groups and access — a short guide

Who can see and do what in Reporion, and how an owner sets it up (D35–D37, D46, D47).

## 1. The words

| Word | Meaning |
|---|---|
| **Account** | A username and password, made by an owner. There is no self-registration. |
| **Owner** | Manages users, groups and settings, and reads every page. Writes reports only if also a radiologist. |
| **Radiologist** | A flag on an account or a group. Only a radiologist sees patient pages (reports), writes and signs them, and has the report tools. |
| **Grant** | `namespace:editor` (read and write) or `namespace:viewer` (read). It covers the namespace and everything under it: `wiki:editor` covers `wiki:notes:x`. |
| **Group** | `@name`: a set of grants, and optionally the radiologist flag, given to every member. |
| **Own namespace** | `users:{username}`. Every account writes there without a grant. |
| **Visibility** | Per page: `private` (only those with a grant), `unlisted` (anyone with the link), `public` (the public site). |
| **Anonymous** | Not signed in: reads `public` pages only. |

What you can reach is your own grants, plus your groups' grants, plus your own namespace. **A grant on `reports` counts only for a radiologist.**

## 2. Who sees what

| | Private reports | Other pages | Sign | Report tools* | Assistant |
|---|---|---|---|---|---|
| Radiologist with a grant | read / write per grant | per grant | yes | yes | on reports and mapped namespaces |
| Writer (not a radiologist) | no (404) | per grant | no | no | in mapped namespaces where they write |
| Owner, not a radiologist | read only | everything | no | no | outside reports |
| Anonymous | no | `public` only | no | no | no |

\* The report tools are the guided new-report form, worklists, timeline, compare, join/merge, the PACS and HippoBridge tabs, and similar reports.

A **404** means "not there for you". It never says whether a page exists.

## 3. For everyone

- **Profile** (avatar menu → Profile) shows your kind, your groups and every namespace you reach, and where each comes from. You also set:
  - your **signature** (the name and title on reports you sign);
  - your **password**;
  - **API tokens** (`read` or `write`, for scripts using `/api/v1` and exports; a token acts as you).
- **New page:** pick a path, and optionally **Start from** a template. Page templates come from `templates:pages`; a radiologist also sees the report templates. The template is copied in, and nothing links back to it.
- **Your own namespace** `users:{you}` is always yours to write. Pages start `private`.
- **Publishing:** set visibility on a page. Making a page `public` asks for confirmation and is audited.

## 4. For owners

### Accounts — Admin → Users
- **Create:**
  - fill in username, password, display name and title;
  - tick **Radiologist** and/or **Owner**;
  - tick the groups;
  - add own grants, one per line (`wiki:editor`).
- **Open** an account to change identity and access in one form, set a password, or deactivate it. *What this adds up to* lists everything the account reaches. A report grant shown struck out does not count, because the account is not a radiologist.
- The last active owner cannot be deactivated or lose owner status.
- CLI:
  ```
  bin/reporion user:create --username=ana --password-hash="$(php -r 'echo password_hash("…", PASSWORD_ARGON2ID);')" --group=radiologists
  ```

### Groups — Admin → Groups
Set access once per kind of person, not per account. A suggested start:

| Group | Radiologists | Grants |
|---|---|---|
| `@radiologists` | ✓ | `reports:editor`, `templates:viewer` |
| `@writers` | | `wiki:editor`, `templates:pages:viewer` |
| `@readers` | | `docs:viewer` |

- A change to a group reaches its members on their next request.
- A group with members cannot be deleted. Remove the members first, on their account pages.
- CLI:
  ```
  bin/reporion group:create --name=radiologists --radiologist --grant=reports:editor --grant=templates:viewer
  ```

### The rest
- **Page templates:** put pages under `templates:pages:` and give the writers `templates:pages:viewer`.
- **Assistant per namespace:** in Admin → AI → *Which prompts serve which pages*, add a row: the namespace (`wiki`) and its profile (`writing`). The prompts are pages under `ai:profiles:writing:`. The most specific namespace wins.
- **Audit:** account and group changes are logged as `user.create|access|deactivate|reactivate`, `group.create|update|delete` and `password.reset`. Logs record field names only, never values.
- **`bin/reporion doctor`** warns when an account names a group that no longer exists.

### Upgrading from accounts without groups
1. Tick **Radiologist** on every owner who writes or signs reports. Without it they only read reports.
2. Editors on `reports:` who are not radiologists now see no private reports. Tick the flag or add them to `@radiologists`.
3. Move repeated grants into groups.

## 5. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| "I can't see any reports" | Not a radiologist, or no grant on `reports` | Tick Radiologist, or add to `@radiologists` |
| No **Sign** button | Not a radiologist, no write grant there, or the report is already signed | Check the account page |
| "Only a radiologist may sign" (403) | An owner who is not a radiologist | Tick Radiologist |
| *Start from* is missing or has no page templates | No readable pages under `templates:pages` | Add pages there and grant `templates:pages:viewer` |
| No assistant on a page | No profile for that namespace, or no write access | Admin → AI → *Which prompts serve which pages* |
