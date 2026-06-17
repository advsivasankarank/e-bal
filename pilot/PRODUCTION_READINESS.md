# e-BAL Production Readiness Assessment

**Date:** June 2026
**Phase:** Pilot Validation (Pre-Pilot Score)
**Status:** Pending Pilot Results

---

## Updated Readiness Score

**Score: 58 / 100** (+6 from audit baseline of 52)

| Dimension | Score (Audit) | Score (Current) | Delta | Rationale for Change |
|---|---|---|---|---|
| TB Import | 70 | 70 | 0 | No changes made |
| Mapping | 75 | 75 | 0 | No changes made |
| Corporate Reporting | 60 | 60 | 0 | No changes (engine off-limits) |
| LLP Reporting | 40 | 40 | 0 | No changes (engine off-limits) |
| Proprietorship Reporting | 65 | 65 | 0 | No changes made |
| Partnership Reporting | 20 | 20 | 0 | No changes made |
| Trust Reporting | 25 | **50** | **+25** | New dedicated trust format + notes with R&P, I&E, BS, Capital Fund |
| Society Reporting | 25 | **50** | **+25** | New dedicated society format + notes with R&P, I&E, BS, Accumulated Fund |
| FY Closure | 35 | 35 | 0 | No changes made |
| Comparative Statements | 50 | **55** | **+5** | `$isFirstYear` bug fixed in web view |
| PDF Export | 75 | 75 | 0 | No changes made |
| XLSX Export | 80 | 80 | 0 | No changes made |
| DOCX Export | 75 | 75 | 0 | No changes made |
| Report Generation Pipeline | 65 | 65 | 0 | No changes made |
| Security & Auth | 50 | 50 | 0 | No changes made |

---

## Success Criteria — Pilot Pass Thresholds

| Criterion | Threshold | Measurement Method |
|---|---|---|
| No balancing differences | Current diff < ₹1 AND Previous diff < ₹1 | Check validation banner on `reports.php` |
| No report crashes | 11/11 entities generate without 500 error | Manual test per entity |
| No export failures | 33/33 exports succeed (11 × PDF/XLSX/DOCX) | Manual test per entity per format |
| Notes reconcile correctly | Note total = BS/P&L line item for all sections | Cross-check note footers vs main table |
| Comparative figures reconcile correctly | Previous year column matches prior FY snapshot or = 0 for first year | Visual verification |
| Snapshot data reconciles correctly | Snapshot DR/CR totals match post-close TB (if FY closed) | Compare snapshot table vs ledger totals |

---

## Go / No-Go Decision Framework

### Condition for GO

All of the following must be true after pilot validation:

1. **All 11 entities** complete TB Import → Mapping → Report Generation without error
2. **At least 9 of 11** pass the balancing check (current diff < ₹1)
3. **All 33 exports** complete without crash (PDF, XLSX, DOCX)
4. **No Critical defects** remain open
5. **At most 3 High defects** remain open, each with a documented waiver from the pilot CA
6. **Trust and Society** reports generate correct R&P, I&E, BS format (visual confirmation)
7. **First-year flag** functions correctly for at least 1 entity (if applicable)

### Condition for NO-GO

Any of the following triggers a NO-GO:

1. **Any report generation crashes** (PHP fatal error, blank page, timeout > 30s)
2. **Any export crashes** (zero-byte file, PHP error, corrupt file)
3. **More than 2 entities** have unacceptably high balancing differences (> ₹100 and unexplained)
4. **Any Critical defect** discovered during pilot that blocks report generation
5. **FY Closure fails** for all entities tested (if tested)

### Scoring Guide

| Score Range | Readiness | Recommendation |
|---|---|---|
| 80-100 | Production Ready | GO — Deploy to production |
| 60-79 | Pilot Ready | CONDITIONAL GO — Deploy to pilot, fix remaining defects per priority |
| 40-59 | Pre-Pilot | NO-GO — Complete Priority 1 & 2 defects before pilot |
| 0-39 | Development | NO-GO — Continue development |

---

## Post-Pilot Recommendation

To be filled after pilot validation completes:

| Criterion | Result | Comments |
|---|---|---|
| All 11 entities import TB | ☐ PASS / ☐ FAIL | |
| All 11 entities map | ☐ PASS / ☐ FAIL | |
| All 11 entities generate reports | ☐ PASS / ☐ FAIL | |
| All 33 exports succeed | ☐ PASS / ☐ FAIL | |
| Balancing differences acceptable | ☐ PASS / ☐ FAIL | |
| Notes reconcile | ☐ PASS / ☐ FAIL | |
| Comparative correct | ☐ PASS / ☐ FAIL | |
| No critical defects | ☐ PASS / ☐ FAIL | |

**Final Recommendation:** ☐ GO / ☐ NO-GO

**Authorised By:** _________________ **Date:** _________
