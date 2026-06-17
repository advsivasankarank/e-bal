# e-BAL Pilot Validation Register

**Project:** e-BAL Financial Reporting System
**Phase:** Pilot Validation
**Date:** June 2026
**Status:** In Progress

---

## Instructions

1. For each pilot entity, import the real client Trial Balance via the Smart Bridge or XML Import.
2. Complete mapping using the Mapping Workbench (`mapping_workbench.php`).
3. Generate reports via `reports.php`.
4. Export to PDF, XLSX, and DOCX via `report_download.php`.
5. Compare output against manually prepared accounts (audited financials from the CA).
6. Record variances, observations, and defects.
7. Mark each row COMPLETE / FAIL / WIP as you go.

---

## Pre-Validation Checklist

| # | Check | Done |
|---|---|---|
| 1 | Smart Bridge / XML Import configured and tested | ☐ |
| 2 | Mapping Workbench accessible for all entity types | ☐ |
| 3 | Report generation (`reports.php`) loads for all entity types | ☐ |
| 4 | PDF export completes without error | ☐ |
| 5 | XLSX export opens in Excel without corruption | ☐ |
| 6 | DOCX export opens in Word without corruption | ☐ |
| 7 | FY Closure feature accessible (even if not closed yet) | ☐ |
| 8 | Comparative data path verified (prior FY exists or first-year flag shown) | ☐ |
| 9 | All 11 pilot entities registered in the system | ☐ |
| 10 | User accounts with appropriate permissions created | ☐ |

---

## Pilot Validation Register

### 1. Private Limited Companies

| Field | Entity A | Entity B | Entity C |
|---|---|---|---|
| **Entity Name** | | | |
| **Entity Type** | Private Limited | Private Limited | Private Limited |
| **Financial Year** | | | |
| **TB Import Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Mapping Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Report Generation Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **PDF Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **XLSX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **DOCX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **Balance Difference (Current)** | ₹ | ₹ | ₹ |
| **Balance Difference (Previous)** | ₹ | ₹ | ₹ |
| **Variance from Manual Accounts** | ₹ | ₹ | ₹ |
| **Observations** | | | |
| **Defects Found** | ☐ None / ☐ See Defect Log | ☐ None / ☐ See Defect Log | ☐ None / ☐ See Defect Log |
| **Resolution Status** | ☐ Open / ☐ Resolved / ☐ Waived | ☐ Open / ☐ Resolved / ☐ Waived | ☐ Open / ☐ Resolved / ☐ Waived |

**Corporate-Specific Checks (Schedule III):**
- [ ] Share Capital (Authorised, Issued, Paid-up) displayed correctly
- [ ] Reserves & Surplus note reconciles
- [ ] Property, Plant & Equipment schedule matches fixed asset register
- [ ] Trade Receivables aging matches debtor list
- [ ] Inventory valuation matches stock register
- [ ] Current / Non-Current classification correct
- [ ] Deferred Tax (if any) disclosed
- [ ] Related Party Transactions note populated (if data available)
- [ ] Contingent Liabilities note populated (if data available)
- [ ] Directors' Report accessible and complete

---

### 2. Limited Liability Partnerships

| Field | Entity D | Entity E |
|---|---|---|
| **Entity Name** | | |
| **Entity Type** | LLP | LLP |
| **Financial Year** | | |
| **TB Import Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Mapping Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Report Generation Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **PDF Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **XLSX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **DOCX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **Balance Difference (Current)** | ₹ | ₹ |
| **Balance Difference (Previous)** | ₹ | ₹ |
| **Variance from Manual Accounts** | ₹ | ₹ |
| **Observations** | | |
| **Defects Found** | ☐ None / ☐ See Defect Log | ☐ None / ☐ See Defect Log |
| **Resolution Status** | ☐ Open / ☐ Resolved / ☐ Waived | ☐ Open / ☐ Resolved / ☐ Waived |

**LLP-Specific Checks:**
- [ ] Partners' Capital Account displayed correctly (current + previous)
- [ ] Loans from Partners separately identified
- [ ] Remuneration to Partners (if any) computed correctly
- [ ] LLP Balance Sheet shows all Sch-III face items:
  - [ ] Partners' Funds
  - [ ] Loans (Secured / Unsecured)
  - [ ] Trade Payables
  - [ ] Other Current Liabilities
  - [ ] Short-Term Provisions
  - [ ] Property, Plant & Equipment
  - [ ] Intangible Assets
  - [ ] Investments
  - [ ] Loans
  - [ ] Trade Receivables
  - [ ] Cash & Bank
  - [ ] Other Current Assets
- [ ] Profit & Loss appropriation correct

---

### 3. Partnership Firms

| Field | Entity F | Entity G |
|---|---|---|
| **Entity Name** | | |
| **Entity Type** | Partnership | Partnership |
| **Financial Year** | | |
| **TB Import Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Mapping Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Report Generation Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **PDF Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **XLSX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **DOCX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **Balance Difference (Current)** | ₹ | ₹ |
| **Balance Difference (Previous)** | ₹ | ₹ |
| **Variance from Manual Accounts** | ₹ | ₹ |
| **Observations** | | |
| **Defects Found** | ☐ None / ☐ See Defect Log | ☐ None / ☐ See Defect Log |
| **Resolution Status** | ☐ Open / ☐ Resolved / ☐ Waived | ☐ Open / ☐ Resolved / ☐ Waived |

**Partnership-Specific Checks:**
- [ ] Trading Account displayed correctly (if applicable)
- [ ] Gross Profit / Loss computed correctly
- [ ] Profit & Loss Account shows all expense heads
- [ ] Partner Capital Movement Schedule in notes
- [ ] Drawings shown separately
- [ ] Interest on Capital (if applicable) displayed
- [ ] Partner Salary / Commission (if applicable) displayed
- [ ] Profit sharing ratio disclosed
- [ ] Balance Sheet balances

---

### 4. Proprietorships

| Field | Entity H | Entity I |
|---|---|---|
| **Entity Name** | | |
| **Entity Type** | Proprietorship | Proprietorship |
| **Financial Year** | | |
| **TB Import Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Mapping Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Report Generation Status** | ☐ Not Started / ☐ WIP / ☐ Complete | ☐ Not Started / ☐ WIP / ☐ Complete |
| **PDF Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **XLSX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **DOCX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **Balance Difference (Current)** | ₹ | ₹ |
| **Balance Difference (Previous)** | ₹ | ₹ |
| **Variance from Manual Accounts** | ₹ | ₹ |
| **Observations** | | |
| **Defects Found** | ☐ None / ☐ See Defect Log | ☐ None / ☐ See Defect Log |
| **Resolution Status** | ☐ Open / ☐ Resolved / ☐ Waived | ☐ Open / ☐ Resolved / ☐ Waived |

**Proprietorship-Specific Checks:**
- [ ] Trading Account displayed correctly
- [ ] Gross Profit / Loss computed correctly
- [ ] Profit & Loss Account complete
- [ ] Capital Account schedule in notes
- [ ] Drawings shown
- [ ] Capital Introduced shown (if any)
- [ ] Balance Sheet balances

---

### 5. Trust

| Field | Entity J |
|---|---|
| **Entity Name** | |
| **Entity Type** | Trust |
| **Financial Year** | |
| **TB Import Status** | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Mapping Status** | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Report Generation Status** | ☐ Not Started / ☐ WIP / ☐ Complete |
| **PDF Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **XLSX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **DOCX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **Balance Difference (Current)** | ₹ |
| **Balance Difference (Previous)** | ₹ |
| **Variance from Manual Accounts** | ₹ |
| **Observations** | |
| **Defects Found** | ☐ None / ☐ See Defect Log |
| **Resolution Status** | ☐ Open / ☐ Resolved / ☐ Waived |

**Trust-Specific Checks:**
- [ ] Correct template selected (`trust_format.php`)
- [ ] Receipts & Payments Account displayed correctly
- [ ] Opening cash balance shown separately from receipts
- [ ] Total Receipts excludes opening balance
- [ ] Total Available = Opening + Receipts
- [ ] Closing cash balance matches bank reconciliation
- [ ] Income & Expenditure Account displayed correctly
- [ ] Net Surplus / Deficit computed correctly
- [ ] Net Surplus transferred to Capital Fund
- [ ] Balance Sheet shows Corpus / Capital Fund correctly
- [ ] Corpus Fund not double-counted with Capital Account
- [ ] Total Assets = Total Liabilities
- [ ] Notes: Capital Fund schedule correct
- [ ] Accounting policies note present (fund accounting)
- [ ] Trust Information note present

---

### 6. Society

| Field | Entity K |
|---|---|
| **Entity Name** | |
| **Entity Type** | Society |
| **Financial Year** | |
| **TB Import Status** | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Mapping Status** | ☐ Not Started / ☐ WIP / ☐ Complete |
| **Report Generation Status** | ☐ Not Started / ☐ WIP / ☐ Complete |
| **PDF Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **XLSX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **DOCX Export Status** | ☐ Not Started / ☐ PASS / ☐ FAIL |
| **Balance Difference (Current)** | ₹ |
| **Balance Difference (Previous)** | ₹ |
| **Variance from Manual Accounts** | ₹ |
| **Observations** | |
| **Defects Found** | ☐ None / ☐ See Defect Log |
| **Resolution Status** | ☐ Open / ☐ Resolved / ☐ Waived |

**Society-Specific Checks:**
- [ ] Correct template selected (`society_format.php`)
- [ ] Receipts & Payments Account displayed correctly
- [ ] Membership Subscriptions shown
- [ ] Grants-in-Aid shown
- [ ] Income & Expenditure Account displayed correctly
- [ ] Balance Sheet shows Accumulated Fund correctly
- [ ] Society terminology used throughout
- [ ] Notes: Accumulated Fund schedule correct
- [ ] Accounting policies note present
- [ ] Total Assets = Total Liabilities

---

## Cross-Cutting Validation Checks

### Export Validation Matrix

For each entity, test all 3 export formats and record results:

| Entity | PDF Pages | PDF Valid | XLSX Sheets | XLSX Valid | DOCX Sections | DOCX Valid |
|---|---|---|---|---|---|---|
| Entity A | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |
| Entity B | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |
| Entity C | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |
| Entity D | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |
| Entity E | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |
| Entity F | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |
| Entity G | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |
| Entity H | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |
| Entity I | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |
| Entity J | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |
| Entity K | | ☐ / ☐ | | ☐ / ☐ | | ☐ / ☐ |

### Notes Reconciliation

For each entity, verify note totals match the main statement:

| Note | BS Amount | Note Total | Match? |
|---|---|---|---|
| Capital / Capital Fund | ₹ | ₹ | ☐ |
| Borrowings | ₹ | ₹ | ☐ |
| Payables | ₹ | ₹ | ☐ |
| Fixed Assets | ₹ | ₹ | ☐ |
| Loans | ₹ | ₹ | ☐ |
| Investments | ₹ | ₹ | ☐ |
| Inventory | ₹ | ₹ | ☐ |
| Receivables | ₹ | ₹ | ☐ |
| Cash & Bank | ₹ | ₹ | ☐ |
| Revenue | ₹ | ₹ | ☐ |
| Expenses | ₹ | ₹ | ☐ |

### Comparative Figures Reconciliation

| Check | Entity A | Entity B | Entity C | Entity D | Entity E | Entity F | Entity G | Entity H | Entity I | Entity J | Entity K |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Current Year matches TB | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| Previous Year matches prior FY data | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| First-year flag works (if applicable) | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| DR/CR totals match | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |

### Snapshot Integrity (Post FY Closure)

| Check | Entity A | Entity B | Entity C | Entity D | Entity E |
|---|---|---|---|---|---|
| FY Close succeeds | ☐ | ☐ | ☐ | ☐ | ☐ |
| Snapshot created | ☐ | ☐ | ☐ | ☐ | ☐ |
| Snapshot DR/CR correct | ☐ | ☐ | ☐ | ☐ | ☐ |
| Opening balances match snapshot | ☐ | ☐ | ☐ | ☐ | ☐ |
| FY Reopen succeeds | ☐ | ☐ | ☐ | ☐ | ☐ |
| Data restored correctly after reopen | ☐ | ☐ | ☐ | ☐ | ☐ |

---

## Sign-Off

| Role | Name | Signature | Date |
|---|---|---|---|
| Pilot Tester | | | |
| CA / Auditor | | | |
| Technical Lead | | | |
| Project Manager | | | |
