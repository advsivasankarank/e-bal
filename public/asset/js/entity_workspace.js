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

    /* ---- Tally Bridge ---- */
    window.fetchFromTallyBridge = function() {
        fetch('http://127.0.0.1:9123/company',{method:'POST',headers:tallyBridgeToken?{'X-Bridge-Token':tallyBridgeToken}:{}})
        .then(function(r){return r.json();}).then(function(data){if(!data.ok)return;var c=data.company||{};var f=document.getElementById('name');if(f&&c.name)f.value=c.name||c.mailing_name||'';}).catch(function(){});
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
