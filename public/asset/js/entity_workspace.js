/**
 * e-BAL Entity Management Workspace — JavaScript
 * Tabs, auditor modal, auditor search/select, director search, CIN rules, MCA lookup.
 */
(function() {
    'use strict';

    /* ---- Tab Navigation ---- */
    var tabs = document.querySelectorAll('.emw-tab');
    var panels = document.querySelectorAll('.emw-panel');
    for (var i = 0; i < tabs.length; i++) {
        tabs[i].addEventListener('click', function() {
            var target = this.getAttribute('data-tab');
            for (var j = 0; j < tabs.length; j++) tabs[j].classList.remove('active');
            for (var k = 0; k < panels.length; k++) panels[k].classList.remove('active');
            this.classList.add('active');
            var panel = document.querySelector('[data-panel="' + target + '"]');
            if (panel) panel.classList.add('active');
        });
    }

    /* ---- Category Chips ---- */
    window.selectCategory = function(value) {
        document.getElementById('category').value = value;
        var chips = document.querySelectorAll('.emw-chip');
        for (var i = 0; i < chips.length; i++) chips[i].classList.toggle('active', chips[i].getAttribute('data-value') === value);
        toggleIdentificationFields();
    };

    function toggleIdentificationFields() {
        var cat = document.getElementById('category').value;
        document.getElementById('section-identification').style.display = cat ? '' : 'none';
        document.getElementById('cin-field').style.display = cat === 'corporate' ? '' : 'none';
        document.getElementById('company-type-field').style.display = cat === 'corporate' ? '' : 'none';
        document.getElementById('llp-field').style.display = cat === 'llp' ? '' : 'none';
        document.getElementById('pan-field').style.display = cat === 'non_corporate' ? '' : 'none';
        document.getElementById('noncorp-field').style.display = cat === 'non_corporate' ? '' : 'none';
    }

    /* ---- CIN Rules ---- */
    window.applyCinRules = function() {
        var cin = (document.getElementById('cin').value || '').trim().toUpperCase();
        document.getElementById('cin').value = cin;
        var match = cin.match(/^([LU])(\d{5})([A-Z]{2})(\d{4})(PLC|PTC|OPC|SGC|FTC|GAP|GOI)(\d{6})$/);
        if (match) { document.getElementById('state_code').value = match[3]; document.getElementById('company_type').value = match[5]; }
    };

    /* ---- MCA Fetch ---- */
    window.fetchEntityData = function(type) {
        var cat = document.getElementById('category').value;
        var field = document.getElementById(type === 'cin' ? 'cin' : 'llp_code');
        var identifier = field ? field.value.trim() : '';
        var statusEl = document.getElementById('lookup-status');
        if (!identifier) { statusEl.style.display='block'; statusEl.style.background='#fef3c7'; statusEl.textContent='Enter the identifier first.'; return; }
        statusEl.style.display='block'; statusEl.style.background='#dbeafe'; statusEl.textContent='Fetching master data...';
        fetch(ebalBaseUrl+'company_dashboard/mca_lookup.php?type='+encodeURIComponent(type)+'&category='+encodeURIComponent(cat)+'&identifier='+encodeURIComponent(identifier))
            .then(function(r){return r.json();})
            .then(function(result){
                if(!result.ok){statusEl.style.background='#fef3c7';statusEl.textContent=result.message||'Lookup failed.';return;}
                var fields=result.fields||{};Object.keys(fields).forEach(function(id){var el=document.getElementById(id);if(el&&!el.value.trim())el.value=fields[id];});
                applyCinRules();statusEl.style.background='#d1fae5';statusEl.textContent='Master data fetched. Review before saving.';
            }).catch(function(e){statusEl.style.background='#fef3c7';statusEl.textContent='Lookup failed: '+e.message;});
    };

    /* ---- Auditor Modal ---- */
    window.openAuditorModal = function() {
        document.getElementById('auditor-modal').classList.add('open');
        document.getElementById('modal_firm_name').focus();
    };
    window.closeAuditorModal = function() {
        document.getElementById('auditor-modal').classList.remove('open');
        ['modal_firm_name','modal_frn','modal_partner','modal_membership','modal_email','modal_mobile','modal_address','modal_peer_review','modal_peer_upto'].forEach(function(id){
            var el=document.getElementById(id);if(el)el.value='';
        });
    };

    window.saveAuditorFromModal = function() {
        var firm = document.getElementById('modal_firm_name').value.trim();
        var frn = document.getElementById('modal_frn').value.trim();
        if (!firm) { showToast('Firm name is required.', 'error'); return; }
        if (!frn) { showToast('FRN is required.', 'error'); return; }

        var data = {
            firm_name: firm, frn: frn,
            partner_name: document.getElementById('modal_partner').value.trim(),
            membership_no: document.getElementById('modal_membership').value.trim(),
            email: document.getElementById('modal_email').value.trim(),
            mobile: document.getElementById('modal_mobile').value.trim(),
            address: document.getElementById('modal_address').value.trim(),
            peer_review_no: document.getElementById('modal_peer_review').value.trim(),
            peer_review_valid_upto: document.getElementById('modal_peer_upto').value || null
        };

        fetch(ebalBaseUrl + 'company_dashboard/ajax_auditor_create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ebalCsrfToken },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.ok && result.auditor_id) {
                closeAuditorModal();
                selectAuditor(result.auditor_id, firm, data.partner_name, frn, data.membership_no, data.email, data.mobile);
                showToast('Auditor created and selected.', 'success');
            } else {
                showToast(result.message || 'Failed to create auditor.', 'error');
            }
        })
        .catch(function() { showToast('Network error.', 'error'); });
    };

    /* ---- Auditor Search & Select ---- */
    var auditorSearch = document.getElementById('auditor-search');
    var auditorResults = document.getElementById('auditor-results');
    var auditorTimeout = null;

    if (auditorSearch) {
        auditorSearch.addEventListener('input', function() {
            var q = this.value.trim();
            clearTimeout(auditorTimeout);
            if (q.length < 2) { auditorResults.classList.remove('open'); return; }
            auditorTimeout = setTimeout(function() {
                fetch(ebalBaseUrl + 'company_dashboard/ajax_auditor_search.php?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.ok || !data.auditors.length) { auditorResults.innerHTML = '<div class="emw-search-item"><span class="emw-search-item-name">No auditors found. <a href="#" onclick="openAuditorModal();return false;" style="color:#12355b;">Create new</a></span></div>'; auditorResults.classList.add('open'); return; }
                        var html = '';
                        data.auditors.forEach(function(a) {
                            html += '<div class="emw-search-item" onclick="selectAuditor('+a.auditor_id+',\''+esc(a.firm_name)+'\',\''+esc(a.partner_name)+'\',\''+esc(a.frn)+'\',\''+esc(a.membership_no)+'\',\''+esc(a.email)+'\',\''+esc(a.mobile)+'\')">';
                            html += '<div class="emw-search-item-name">' + esc(a.firm_name) + '</div>';
                            html += '<div class="emw-search-item-meta">' + esc(a.partner_name) + ' · FRN: ' + esc(a.frn) + ' · M.No: ' + esc(a.membership_no) + '</div>';
                            html += '</div>';
                        });
                        auditorResults.innerHTML = html;
                        auditorResults.classList.add('open');
                    });
            }, 300);
        });
        auditorSearch.addEventListener('blur', function() { setTimeout(function() { auditorResults.classList.remove('open'); }, 200); });
    }

    window.selectAuditor = function(id, firm, partner, frn, membership, email, mobile) {
        document.getElementById('auditor_auditor_id').value = id;
        document.getElementById('aud-display-firm').textContent = firm || '—';
        document.getElementById('aud-display-frn').textContent = frn || '—';
        document.getElementById('aud-display-partner').textContent = partner || '—';
        document.getElementById('aud-display-membership').textContent = membership || '—';
        document.getElementById('aud-display-email').textContent = email || '—';
        document.getElementById('aud-display-mobile').textContent = mobile || '—';
        document.getElementById('auditor-selected').style.display = '';
        document.getElementById('auditor-search').value = '';
        auditorResults.classList.remove('open');
    };

    window.clearAuditor = function() {
        document.getElementById('auditor_auditor_id').value = '';
        document.getElementById('auditor-selected').style.display = 'none';
    };

    /* ---- Director Search ---- */
    var directorSearch = document.getElementById('director-search');
    var directorResults = document.getElementById('director-results');
    var directorTimeout = null;
    var addedDirectors = [];

    if (directorSearch) {
        directorSearch.addEventListener('input', function() {
            var q = this.value.trim();
            clearTimeout(directorTimeout);
            if (q.length < 2) { directorResults.classList.remove('open'); return; }
            directorTimeout = setTimeout(function() {
                fetch(ebalBaseUrl + 'company_dashboard/ajax_director_search.php?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.ok || !data.directors.length) { directorResults.innerHTML = '<div class="emw-search-item"><span class="emw-search-item-name">No directors found. <a href="#" onclick="showNewDirectorForm();return false;" style="color:#12355b;">Create new</a></span></div>'; directorResults.classList.add('open'); return; }
                        var html = '';
                        data.directors.forEach(function(d) {
                            var added = addedDirectors.indexOf(d.director_id) !== -1;
                            html += '<div class="emw-search-item" onclick="'+(added?'':'addDirector('+d.director_id+',\''+esc(d.director_name)+'\',\''+esc(d.din)+'\')')+'" style="'+(added?'opacity:.5;cursor:default;':'')+'">';
                            html += '<div class="emw-search-item-name">' + esc(d.director_name) + (added ? ' (added)' : '') + '</div>';
                            html += '<div class="emw-search-item-meta">DIN: ' + esc(d.din) + '</div></div>';
                        });
                        directorResults.innerHTML = html;
                        directorResults.classList.add('open');
                    });
            }, 300);
        });
        directorSearch.addEventListener('blur', function() { setTimeout(function() { directorResults.classList.remove('open'); }, 200); });
    }

    window.addDirector = function(id, name, din) {
        if (addedDirectors.indexOf(id) !== -1) return;
        addedDirectors.push(id);
        var noDir = document.getElementById('no-directors'); if (noDir) noDir.style.display = 'none';
        var row = document.createElement('div');
        row.className = 'emw-director-row';
        row.setAttribute('data-director-id', id);
        row.innerHTML = '<div><strong>'+esc(name)+'</strong><br><span style="font-size:.72rem;color:#64748b;">DIN: '+esc(din)+'</span></div>'+
            '<div><input type="text" name="director_designations[]" placeholder="Designation" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:.82rem;"></div>'+
            '<div><input type="text" name="director_signing_orders[]" placeholder="Order" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:.82rem;"></div>'+
            '<div><input type="text" name="director_signing_auths[]" placeholder="Signing Authority" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:.82rem;"></div>'+
            '<div><button type="button" class="emw-btn emw-btn-sm emw-btn-danger" onclick="removeDirector(this,'+id+')">×</button></div>'+
            '<input type="hidden" name="director_ids[]" value="'+id+'">';
        document.getElementById('directors-list').appendChild(row);
        directorSearch.value = ''; directorResults.classList.remove('open');
    };

    window.removeDirector = function(btn, id) {
        var row = btn.closest('.emw-director-row'); if (row) row.remove();
        var idx = addedDirectors.indexOf(id); if (idx !== -1) addedDirectors.splice(idx, 1);
        if (addedDirectors.length === 0) { var noDir = document.getElementById('no-directors'); if (noDir) noDir.style.display = ''; }
    };

    window.showNewDirectorForm = function() { document.getElementById('new-director-form').style.display = ''; };
    window.hideNewDirectorForm = function() { document.getElementById('new-director-form').style.display = 'none'; };

    window.createAndAddDirector = function() {
        var name = document.getElementById('new_dir_name').value.trim();
        var din = document.getElementById('new_dir_din').value.trim();
        var pan = document.getElementById('new_dir_pan').value.trim();
        if (!name) { showToast('Director name is required.', 'error'); return; }
        fetch(ebalBaseUrl+'company_dashboard/ajax_director_create.php', {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':ebalCsrfToken},
            body:JSON.stringify({director_name:name,din:din,pan:pan})
        }).then(function(r){return r.json();}).then(function(data){
            if(data.ok){addDirector(data.director_id,name,din);hideNewDirectorForm();document.getElementById('new_dir_name').value='';document.getElementById('new_dir_din').value='';document.getElementById('new_dir_pan').value='';showToast('Director created and added.','success');}
            else showToast(data.message||'Failed.','error');
        });
    };

    /* ---- Tally Company Import ---- */
    /*
     * Architecture: Browser → PHP Server → Smart Bridge (port 9123) → Tally (port 9000)
     * PHP uses bridge /fetch endpoint to forward XML requests.
     */
    var tallySelectedCompany = null;
    var tallyMappedData = null;

    window.openTallyImportModal = function() {
        document.getElementById('tally-modal').classList.add('open');
        document.getElementById('tally-step-list').style.display = '';
        document.getElementById('tally-step-preview').style.display = 'none';
        document.getElementById('tally-loading').style.display = '';
        document.getElementById('tally-error').style.display = 'none';
        document.getElementById('tally-company-list').style.display = 'none';
        document.getElementById('tally-import-btn').style.display = 'none';
        document.getElementById('tally-diagnostics').style.display = 'none';
        document.getElementById('tally-modal-title').textContent = 'Fetch from Tally';
        tallySelectedCompany = null;
        tallyMappedData = null;
        loadTallyCompanies();
    };

    window.closeTallyModal = function() {
        document.getElementById('tally-modal').classList.remove('open');
    };

    window.retryTallyConnection = function() {
        document.getElementById('tally-loading').style.display = '';
        document.getElementById('tally-error').style.display = 'none';
        document.getElementById('tally-diagnostics').style.display = 'none';
        loadTallyCompanies();
    };

    function loadTallyCompanies() {
        fetch(ebalBaseUrl + 'company_dashboard/ajax_tally_companies.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('tally-loading').style.display = 'none';

            /* Show diagnostics */
            var diagEl = document.getElementById('tally-diagnostics');
            if (diagEl) {
                var bridgeStatus = data.bridge_status || 'unknown';
                var tallyStatus = data.tally_status || 'unknown';
                diagEl.innerHTML =
                    '<div style="display:flex;gap:16px;font-size:.78rem;margin-bottom:12px;">' +
                    '<span>Bridge: <strong style="color:' + (bridgeStatus === 'online' ? '#047857' : '#dc2626') + ';">' + esc(bridgeStatus) + '</strong></span>' +
                    '<span>Tally: <strong style="color:' + (tallyStatus === 'connected' ? '#047857' : '#dc2626') + ';">' + esc(tallyStatus) + '</strong></span>' +
                    '</div>';
                diagEl.style.display = '';
            }

            if (!data.ok) {
                document.getElementById('tally-error').style.display = '';
                document.getElementById('tally-error-msg').innerHTML =
                    '<div style="color:#dc2626;font-weight:600;margin-bottom:6px;">' + esc(data.message || 'Connection failed.') + '</div>' +
                    '<div style="font-size:.78rem;">Ensure the e-BAL Smart Bridge is running and Tally is open.</div>';
                return;
            }
            var companies = data.companies || [];
            if (companies.length === 0) {
                document.getElementById('tally-error').style.display = '';
                document.getElementById('tally-error-msg').textContent = 'No companies found in Tally.';
                return;
            }
            var listEl = document.getElementById('tally-companies');
            var html = '';
            companies.forEach(function(name) {
                html += '<div class="emw-tally-company" onclick="selectTallyCompany(this, \'' + esc(name) + '\')">';
                html += '<div class="emw-tally-radio"></div>';
                html += '<div><div class="emw-tally-company-name">' + esc(name) + '</div></div>';
                html += '</div>';
            });
            listEl.innerHTML = html;
            document.getElementById('tally-company-list').style.display = '';
        })
        .catch(function(e) {
            document.getElementById('tally-loading').style.display = 'none';
            document.getElementById('tally-error').style.display = '';
            document.getElementById('tally-error-msg').innerHTML =
                '<div style="color:#dc2626;font-weight:600;margin-bottom:6px;">Network error</div>' +
                '<div style="font-size:.78rem;">' + esc(e.message) + '</div>';
        });
    }

    window.selectTallyCompany = function(el, name) {
        var items = document.querySelectorAll('.emw-tally-company');
        for (var i = 0; i < items.length; i++) items[i].classList.remove('selected');
        el.classList.add('selected');
        tallySelectedCompany = name;
        fetchTallyCompanyDetail(name);
    };

    function fetchTallyCompanyDetail(name) {
        document.getElementById('tally-step-list').style.display = 'none';
        document.getElementById('tally-step-preview').style.display = '';
        document.getElementById('tally-modal-title').textContent = 'Import Preview';
        document.getElementById('tally-import-btn').style.display = '';
        document.getElementById('tally-preview-name').textContent = name;
        document.getElementById('tally-preview-subtitle').textContent = 'Loading company details from Tally...';
        document.getElementById('tally-preview-rows').innerHTML = '<tr><td colspan="3" style="text-align:center;padding:20px;color:#64748b;">Fetching data...</td></tr>';
        document.getElementById('tally-duplicate-warning').style.display = 'none';

        fetch(ebalBaseUrl + 'company_dashboard/ajax_tally_company_detail.php?company_name=' + encodeURIComponent(name))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) {
                document.getElementById('tally-preview-rows').innerHTML = '<tr><td colspan="3" style="color:#dc2626;padding:12px;">' + esc(data.message || 'Failed to fetch details.') + '</td></tr>';
                document.getElementById('tally-import-btn').style.display = 'none';
                return;
            }
            tallyMappedData = data.mapped;
            document.getElementById('tally-preview-subtitle').textContent = 'Review the data below before importing.';
            renderPreviewTable(data.mapped, data.duplicates || []);
        })
        .catch(function(e) {
            document.getElementById('tally-preview-rows').innerHTML = '<tr><td colspan="3" style="color:#dc2626;">Error: ' + esc(e.message) + '</td></tr>';
            document.getElementById('tally-import-btn').style.display = 'none';
        });
    }

    function renderPreviewTable(mapped, duplicates) {
        var rows = [
            { label: 'Entity Name', value: mapped.name },
            { label: 'Category', value: mapped.category === 'corporate' ? 'Corporate' : mapped.category === 'llp' ? 'LLP' : 'Non-Corporate' },
            { label: 'PAN', value: mapped.pan },
            { label: 'CIN', value: mapped.cin },
            { label: 'LLPIN', value: mapped.llp_code },
            { label: 'GSTIN', value: mapped.gstin },
            { label: 'Registered Address', value: mapped.registered_address },
            { label: 'State', value: mapped.state_name || mapped.state_code },
            { label: 'Email', value: mapped.email },
            { label: 'Mobile', value: mapped.mobile },
        ];
        var html = '';
        rows.forEach(function(r) {
            var hasValue = r.value && r.value.trim() !== '';
            html += '<tr><td style="font-weight:600;">' + esc(r.label) + '</td>';
            html += '<td>' + (hasValue ? esc(r.value) : '<span class="emw-preview-empty">—</span>') + '</td>';
            html += '<td>' + (hasValue ? '<span class="emw-preview-ok">✓ Available</span>' : '<span class="emw-preview-empty">Not found</span>') + '</td></tr>';
        });
        document.getElementById('tally-preview-rows').innerHTML = html;

        if (duplicates.length > 0) {
            var msg = duplicates.map(function(d) {
                return d.field + ' "' + d.value + '" already exists (' + d.existing_name + ')';
            }).join('; ');
            document.getElementById('tally-duplicate-msg').textContent = msg;
            document.getElementById('tally-duplicate-warning').style.display = '';
        }
    }

    window.importTallyCompany = function() {
        if (!tallyMappedData) return;
        var m = tallyMappedData;

        var nameField = document.getElementById('name');
        if (nameField) nameField.value = m.name || '';
        if (m.category) selectCategory(m.category);

        var cinField = document.getElementById('cin');
        if (cinField && m.cin) cinField.value = m.cin;
        var llpField = document.getElementById('llp_code');
        if (llpField && m.llp_code) llpField.value = m.llp_code;
        var panField = document.getElementById('pan');
        if (panField && m.pan) panField.value = m.pan;
        var addrField = document.getElementById('registered_address');
        if (addrField) addrField.value = m.registered_address || '';
        var stateField = document.getElementById('state_code');
        if (stateField && m.state_code) stateField.value = m.state_code;
        var emailField = document.getElementById('official_email');
        if (emailField) emailField.value = m.email || '';
        var mobileField = document.getElementById('mobile_no');
        if (mobileField) mobileField.value = m.mobile || '';

        toggleIdentificationFields();
        if (m.cin) applyCinRules();
        closeTallyModal();
        showToast('Company imported from Tally. Review and save.', 'success');
    };

    /* ---- Toast ---- */
    function showToast(msg, type) {
        var t = document.getElementById('emw-toast');
        if (!t) return;
        t.textContent = msg;
        t.className = 'emw-toast emw-toast-' + (type || 'success') + ' show';
        setTimeout(function() { t.classList.remove('show'); }, 3000);
    }

    /* ---- Escape HTML ---- */
    function esc(s) { if (!s) return ''; return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

})();
