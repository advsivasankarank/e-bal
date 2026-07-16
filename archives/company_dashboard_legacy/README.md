Archived `public/company_dashboard/*` files, superseded by the canonical entity_* flow.

- `company_create.php` → replaced by `public/entity_create.php`
- `company_edit.php` → replaced by `public/entity_edit.php` (now has full field parity: company type, addresses, statutory auditor, signatories)
- `ajax_auditor_create.php`, `ajax_auditor_search.php`, `ajax_director_create.php`, `ajax_director_search.php`, `ajax_tally_company_detail.php`, `ajax_tally_companies.php`, `ajax_validate_tally_import.php` — had zero live callers anywhere in the codebase (only referenced from `public/asset/js/entity_workspace.js`, which is itself not `<script src>`-included by any page)
- `index.php` — pure redirect stub, no inbound links

`public/company_dashboard/company_list.php`, `company_select.php`, `company_delete.php`, `financial_year.php`, `financial_year_ajax.php`, and `mca_lookup.php` are still live and were not touched.
