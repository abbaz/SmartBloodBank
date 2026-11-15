# Release Validation Checklist

Run these checks before presenting or deploying the project. Use synthetic data only.

## Functional and security checks

- [ ] Register as `USER`/Donor; verify the account is active, donor profile is pending staff verification, and donor APIs expose only that donor's information. Submit Staff, Hospital Requester, and Lab applications; verify each remains pending and cannot sign in until Admin approval.
- [ ] Verify the registration API rejects `ADMIN`, Admin approval is facility-scoped for staff/lab roles, and an Admin can promote only an approved regular User with a visible audit event.
- [ ] Verify Admin password resets are limited to the Admin's facility, the temporary password is not written to the audit log, and the reset user must change it before using protected APIs.
- [ ] Verify an approved Hospital Requester sees only requests they submitted, and that pending applicants cannot read protected data or place requests before approval.
- [ ] Register a Donor, verify the donor profile stays pending while account access works, then verify it through Donor Queue. Confirm a second account cannot access that donor's profile, appointment, or history by changing submitted IDs.
- [ ] Request and cancel a donor appointment; confirm it from Staff, then record collection through the queue and verify a quarantined unit is created and the appointment becomes completed.
- [ ] Confirm the linked unit remains unavailable to allocation until Lab clearance; verify the Donor sees only a safe history status and no screening details or recipient data.
- [ ] Confirm an unlinked historic donor account requires staff identity review before linking and that a donor record cannot be linked to multiple user accounts.
- [ ] Have an administrator provision facility staff and a verified hospital requester; confirm each role sees only its permitted facility/institution records.
- [ ] Confirm a staff member at facility A cannot list, inspect, recommend units for, allocate, or print a slip for a request assigned to facility B.
- [ ] Exercise each of the eight recipient groups against the compatibility table; verify every returned unit is compatible and expired units are always blocked.
- [ ] Allocate a multi-unit request partially, confirm it stays pending, and confirm a second allocation marks it fulfilled at the exact requested quantity.
- [ ] Verify every create, edit, discard, deletion, auto-expiry, and allocation operation creates an audit entry. Verify issued units and units linked to a transaction cannot be deleted.
- [ ] Import `audit-immutability.sql` once, then confirm direct UPDATE and DELETE attempts against `audit_logs` are rejected.
- [ ] Confirm all core mutable tables use InnoDB before accepting writes; the API should return 503 with migration instructions if any required table is missing or non-transactional.
- [ ] Force an audit insert failure in an isolated test database and confirm the associated user, donor, inventory, request, or allocation write is rolled back.
- [ ] Call protected APIs without a session and confirm they return 401 JSON and disclose no database counts or records.
- [ ] Submit malformed values directly to the APIs (bypassing browser checks): weak passwords, invalid email/phone/date values, blank required fields, fractional/out-of-range quantities and thresholds, and incomplete lab screening confirmations must be rejected without changing the database.

## Performance and browser checks

- [ ] Measure allocation recommendation and confirmation endpoints with representative inventory at or below the project target of 3 seconds under normal local WAMP load.
- [ ] Repeat the same policy simulation seed and compare outcome metrics; the three strategies must have zero compatibility violations.
- [ ] Open every page and exercise menus, forms, modals, tables, charts, and dark mode in current Chrome, Firefox, and Edge.
- [ ] Record browser versions, dataset size, elapsed times, and any defects in the final report.

## Operations

- [ ] Take and verify a database backup before schema migrations or a presentation demo.
- [ ] Restore a backup into a separate test database and confirm the site can read it.
- [ ] Run `tests/wamp-integration.ps1` on Windows to validate isolated registration, facility-A/facility-B boundaries, partial then full allocation, slips, reports, lab clearance, and audit-failure rollback.
- [ ] Verify Analytics presets and a custom date range, confirm invalid/reversed ranges are rejected, and ensure stock snapshot cards remain independent of the selected period.

The automated policy check is `node tests/allocation-policy.test.js`. It does not replace the manual browser, WAMP integration, privacy, or performance checks above.
