# MSCare Admin - Role-Based Access Control (RBAC)

The clinic has two people, so there are two roles. Every account has exactly one role (`admins.role`).
Permissions belong to roles, never to individual users. Anything not explicitly granted is denied.

| Permission | Staff / Assistant | Admin |
|---|:--:|:--:|
| Dashboard, Appointments, Patients, Treatment History, Messages, Notifications | Yes | Yes |
| Own account settings (profile, password) | Yes | Yes |
| Block Time (Settings > Block Time: close dates / time ranges to bookings) | Yes | Yes |
| Revenue & Reports | - | Yes |
| Clinic settings (profile, hours, notifications, email, services & pricing, privacy, audit log) | - | Yes |
| Team & Accounts (create accounts, assign roles, activate/deactivate, dentists, branches) | - | Yes |

## Where it is enforced
* `includes/auth.php` - `rbac_matrix()` (role -> permissions), `can()`, `require_permission()`.
  The role is re-read from the database on every request, so a role change applies immediately.
* Every page calls `require_permission()` right after `require_admin()` (403 page + audit entry).
* `api.php` - every action maps to a permission; unknown actions are denied.
* `settings.php` - each section needs a permission (viewing and saving); the section list and the
  sidebar only show what the signed-in role may open.

## Safeguards
* Only an Admin can create accounts or change roles; nobody can change their own role.
* At least one active Admin must always remain (no last-admin demotion/deactivation).
* Role changes, account creation and denied access attempts are written to `admin_audit_logs`
  (visible in Settings > Privacy & System > Audit log).

## Demo accounts (change the passwords after setup)
| Username | Password | Role |
|---|---|---|
| admin1 / admin2 | admin123 | Admin (Clinic 1 / Clinic 2) |
| staff1 / staff2 | staff123 | Staff / Assistant (Clinic 1 / Clinic 2) |

## Object-oriented structure (v28)
The RBAC and database layer is now class-based. The old function names (`can()`, `require_permission()`,
`db()`, `rbac_role_labels()` ...) still exist and just call the classes, so every page works unchanged.

| Class (`includes/classes/`) | OOP concept | Job |
|---|---|---|
| `Role` (abstract) | Abstraction | Base for all roles: `key()`, `label()`, `permissions()`, `can()` |
| `StaffRole` -> `AdminRole` | Inheritance, Polymorphism | Each role adds its own permissions on top of its parent's |
| `RoleRegistry` | Registry | Finds a role object from `admins.role`; unknown role = deny |
| `AdminUser` | Encapsulation | Signed-in admin with private fields and getters |
| `Auth` | Service class | Login, per-request role re-check, `requirePermission()` |
| `AuditLogger` | Single responsibility | Writes `admin_audit_logs` |
| `Database` | Singleton | One shared PDO connection |

Classes load through `includes/autoload.php` (file name = class name). To add a role, create one class
that extends another role and register it in `RoleRegistry::all()`.

Upgrading an existing database: run `database/two_roles.sql` once (turns any old superadmin into Admin and tightens the role check).

Staff and Admin of the same clinic see exactly the same clinic data (patients, appointments, treatments, messages): data is filtered by the account's branch, the role only decides which pages and actions are allowed.
