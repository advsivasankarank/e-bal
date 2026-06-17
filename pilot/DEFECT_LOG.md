# e-BAL Pilot Validation — Defect Log

**Project:** e-BAL Financial Reporting System
**Phase:** Pilot Validation

---

## Instructions

- Log every defect found during pilot validation in the table below.
- Assign a unique ID: `PILOT-D001`, `PILOT-D002`, etc.
- Severity: **Critical** (blocks pilot), **High** (major feature gap), **Medium** (functional gap), **Low** (cosmetic/minor).
- Component: Entity-specific or cross-cutting (Export, Mapping, Import, etc.).
- Status: **Open** → **In Progress** → **Resolved** → **Closed**.
- Link defects to the entity in the Pilot Validation Register.

---

## Defect Log

| ID | Date | Entity | Component | Severity | Description | Steps to Reproduce | Root Cause | Status | Resolution | Resolved By |
|---|---|---|---|---|---|---|---|---|---|---|
| PILOT-D001 | | | | | | | | Open | | |
| PILOT-D002 | | | | | | | | Open | | |
| PILOT-D003 | | | | | | | | Open | | |
| PILOT-D004 | | | | | | | | Open | | |
| PILOT-D005 | | | | | | | | Open | | |
| PILOT-D006 | | | | | | | | Open | | |
| PILOT-D007 | | | | | | | | Open | | |
| PILOT-D008 | | | | | | | | Open | | |
| PILOT-D009 | | | | | | | | Open | | |
| PILOT-D010 | | | | | | | | Open | | |
| PILOT-D011 | | | | | | | | Open | | |
| PILOT-D012 | | | | | | | | Open | | |
| PILOT-D013 | | | | | | | | Open | | |
| PILOT-D014 | | | | | | | | Open | | |
| PILOT-D015 | | | | | | | | Open | | |

---

## Known Pre-Existing Defects (From Production Readiness Audit)

These defects were identified during the audit and are NOT recorded again in the pilot defect log unless they are re-encountered during testing.

| Audit ID | Component | Severity | Status | Notes |
|---|---|---|---|---|
| C1-C3 | FY Closure | Critical | Unresolved | Foundation for comparative data; affects snapshot validation |
| H1-H2 | FY Closure | High | Unresolved | Reopen cascade, polarity loss |
| H3-H4 | Export | High | Unresolved | Memory limit, CSRF |
| H7 | Corporate | High | Unresolved | Tax hardcoded 0 |
| H9 | LLP | High | Unresolved | Missing BS line items |
| H10 | Partnership | High | Unresolved | No Partner Capital Schedule |
| M1-M15 | Various | Medium | Unresolved | Various gaps |
| L1-L14 | Various | Low | Unresolved | Minor/cosmetic |

---

## Severity Definitions

| Severity | Definition | Pilot Impact |
|---|---|---|
| **Critical** | System crash, data loss, report fails to generate/export | Pilot blocked |
| **High** | Materially incorrect figures, missing statutory requirement | Pilot impacted — requires waiver |
| **Medium** | Functional gap but workaround exists, or non-material disclosure gap | Acceptable with documentation |
| **Low** | Cosmetic, formatting, label issues | Acceptable for pilot |
