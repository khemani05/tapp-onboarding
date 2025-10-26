/* =========================================================
 * Public-facing dependent selects (guarded)
 * ========================================================= */
(function ($) {
  if (!window.TAPP_UOR || !TAPP_UOR.ajax_url) return; // only run when public ajax config exists

  function fillDepartments(companyId, $dept) {
    $dept.empty().append(
      $('<option/>', {
        value: '',
        text:
          (TAPP_UOR.i18n && TAPP_UOR.i18n.selectDepartment) ||
          TAPP_UOR.i18n_select_dept ||
          'Select Department',
      })
    );
    if (!companyId) return;

    $.post(
      TAPP_UOR.ajax_url,
      { action: 'tapp_uor_departments', nonce: TAPP_UOR.nonce, company_id: companyId },
      function (resp) {
        if (resp && resp.success) {
          resp.data.forEach(function (d) {
            $dept.append($('<option/>', { value: d.id, text: d.name }));
          });
          $('.tapp-dept-wrap').show();
        }
      }
    );
  }

  function fillRoles(deptId, $role) {
    $role.empty().append(
      $('<option/>', { value: '', text: (TAPP_UOR.i18n && TAPP_UOR.i18n.selectRole) || 'Select Job Role'})
    );
    if (!deptId) return;

    $.post(
      TAPP_UOR.ajax_url,
      { action: 'tapp_uor_jobroles', nonce: TAPP_UOR.nonce, department_id: deptId },
      function (resp) {
        if (resp && resp.success) {
          resp.data.forEach(function (r) {
            $role.append($('<option/>', { value: r.id, text: r.label }));
          });
          $('.tapp-role-wrap').show();
        }
      }
    );
  }

  // Public form listeners
  $(document).on('change', '#tapp_company', function () {
    fillDepartments($(this).val(), $('#tapp_department'));
    $('#tapp_jobrole').empty();
    $('.tapp-role-wrap').hide();
  });
  $(document).on('change', '#tapp_department', function () {
    fillRoles($(this).val(), $('#tapp_jobrole'));
  });
})(jQuery);


/* =========================================================
 * Admin inline-edit UX (row state .is-edit) + dept population
 * ========================================================= */
(function () {
  function fillDeptSelect(selectEl, companyId, selectedId) {
    const map  = (window.TAPP_UOR && TAPP_UOR.deptsByCompany) || {};
    const list = map[parseInt(companyId || 0, 10)] || [];
    const keep = selectedId || selectEl.dataset.selected || selectEl.dataset.orig || selectEl.value;

    selectEl.innerHTML = '';
    const opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = (TAPP_UOR.i18n && TAPP_UOR.i18n.selectDepartment) || 'Select Department';
    selectEl.appendChild(opt0);

    list.forEach((d) => {
      const o = document.createElement('option');
      o.value = String(d.id);
      o.textContent = d.name;
      selectEl.appendChild(o);
    });

    if (keep) selectEl.value = String(keep);
  }

  function setModeRow(row, mode) {
    const isEdit = mode === 'edit';
    row.classList.toggle('is-edit', isEdit);
    // enable inputs only in edit mode
    row.querySelectorAll('.edit-field').forEach((el) => {
      if (['INPUT','SELECT','TEXTAREA'].includes(el.tagName)) el.disabled = !isEdit;
    });
  }

  function snapshotOriginals(row) {
    row.querySelectorAll('input[type="text"], input[type="checkbox"], textarea, select').forEach((el) => {
      el.dataset.orig = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
    });
  }

  // HTML5 + explicit department validation — keeps row in EDIT on failure
  function validateFormRequired(form) {
    // native validity
    if (form.checkValidity && !form.checkValidity()) {
      const firstInvalid = form.querySelector(':invalid');
      if (firstInvalid) firstInvalid.focus();
      return false;
    }
    // explicit dept guard for job role rows
    const deptSel = form.querySelector('select[name="department_id"]');
    if (deptSel && !deptSel.value) {
      alert((window.TAPP_UOR?.i18n?.selectDepartment) || 'Please select a Department before saving.');
      deptSel.focus();
      return false;
    }
    return true;
  }

  // Initialize per row
  document.querySelectorAll('.tapp-row').forEach((row) => {
    snapshotOriginals(row);
    setModeRow(row, 'view');

    // Edit
    const btnEdit = row.querySelector('.tapp-row-edit');
    if (btnEdit) {
      btnEdit.addEventListener('click', () => {
        setModeRow(row, 'edit');
        const companySel = row.querySelector('select.tapp-company');
        const deptSel    = row.querySelector('select.tapp-dept');
        if (companySel && deptSel) {
          fillDeptSelect(deptSel, companySel.value, deptSel.dataset.selected || '');
        }
      });
    }

    // Cancel → restore originals and return to view
    const btnCancel = row.querySelector('.tapp-row-cancel');
    if (btnCancel) {
      btnCancel.addEventListener('click', () => {
        row.querySelectorAll('input[type="text"], input[type="checkbox"], textarea, select').forEach((el) => {
          if (!('orig' in el.dataset)) return;
          if (el.type === 'checkbox') el.checked = el.dataset.orig === '1';
          else el.value = el.dataset.orig || '';
          el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        setModeRow(row, 'view');
      });
    }

    // Company → Dept (job roles)
    const companySel = row.querySelector('select.tapp-company');
    const deptSel    = row.querySelector('select.tapp-dept');
    if (companySel && deptSel) {
      companySel.addEventListener('change', () => fillDeptSelect(deptSel, companySel.value, ''));
    }

    // Prevent submit with invalid data; stay in EDIT (no revert)
    const form = row.querySelector('form.tapp-inline-row');
    if (form) {
      form.addEventListener('submit', (e) => {
        if (!validateFormRequired(form)) {
          e.preventDefault();
          // remain in edit mode so user can fix errors
        }
      });
    }
  });
})();


/* =========================================================
 * Add Job Role form: disable fields until company is selected
 * and populate Department from localized deptsByCompany
 * ========================================================= */
(function () {
  const addCompany = document.getElementById('tapp-company-select');   // company in Add form
  const addDept    = document.getElementById('tapp-dept-select');      // department in Add form
  if (!addCompany || !addDept) return; // not on this screen

  const form = addCompany.closest('form');
  // everything except company; include both button and input submit (WP can output either)
  const controls = form.querySelectorAll(
    'select[name="department_id"], input[name="label"], input[name="slug"], select[name="mapped_wp_role"], button[type="submit"], input[type="submit"]'
  );

  function setEnabled(enabled) {
    controls.forEach((el) => (el.disabled = !enabled));
    // dept stays disabled until a company is chosen
    addDept.disabled = !enabled;
  }

  function fillDepartmentsForCompany() {
    const map  = (window.TAPP_UOR && TAPP_UOR.deptsByCompany) || {};
    const cid  = parseInt(addCompany.value || 0, 10);
    const list = map[cid] || [];

    addDept.innerHTML = '';
    const opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = (TAPP_UOR.i18n && TAPP_UOR.i18n.selectDepartment) || 'Select Department';
    addDept.appendChild(opt0);

    list.forEach((d) => {
      const o = document.createElement('option');
      o.value = String(d.id);
      o.textContent = d.name;
      addDept.appendChild(o);
    });

    addDept.disabled = !cid;
  }

  // Initial: everything disabled until a company is selected
  setEnabled(false);
  addDept.disabled = true;

  // When company changes → enable controls and populate depts
  addCompany.addEventListener('change', () => {
    const hasCompany = !!addCompany.value;
    fillDepartmentsForCompany();
    setEnabled(hasCompany);
  });

  // If company is preselected, sync on load
  if (addCompany.value) {
    fillDepartmentsForCompany();
    setEnabled(true);
  }
})();


/* =========================================================
 * Admin › User Profile: Company → Departments → Roles
 * (Select2/SelectWoo-safe placeholders, no duplicates)
 * ========================================================= */
(function () {
  // find fields (support multiple possible ids/names)
  const $company = document.querySelector(
    '#tapp_company_id, #tapp_company, select[name="tapp_company_id"], select[name="tapp_company"], select[name="company_id"], select[data-tapp="company"]'
  );
  const $dept = document.querySelector(
    '#tapp_department_id, #tapp_department, select[name="tapp_department_id"], select[name="tapp_department"], select[name="department_id"], select[data-tapp="department"]'
  );
  const $role = document.querySelector(
    '#tapp_job_role_id, #tapp_jobrole, select[name="tapp_job_role_id"], select[name="tapp_jobrole"], select[name="job_role_id"], select[data-tapp="jobrole"]'
  );
  if (!$company || !$dept) return;

  const deptLabel = ($dept.getAttribute('data-placeholder') || ($window?.TAPP_UOR?.i18n?.selectDepartment)) || 'Select Department';
  const roleLabel = ($role && ($role.getAttribute('data-placeholder') || (window?.TAPP_UOR?.i18n?.selectRole))) || 'Select Job Role';

  function isSelect2(el) {
    if (!window.jQuery) return false;
    const $ = window.jQuery;
    return !!($.fn.select2 || $.fn.selectWoo) && $(el).hasClass('select2-hidden-accessible');
  }

  function refreshSelect2(el) {
    if (!window.jQuery) return;
    const $ = window.jQuery;
    if ($.fn.selectWoo && $(el).data('select2')) {
      $(el).trigger('change.select2');
    } else if ($.fn.select2 && $(el).data('select2')) {
      $(el).trigger('change.select2');
    }
  }

  function resetSelect(sel, label) {
    sel.innerHTML = '';
    const opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = label || '';
    sel.appendChild(opt0);
    sel.value = ''; // ensure placeholder is active
    sel.setAttribute('data-placeholder', label || '');
    refreshSelect2(sel);
  }

  function fillDept(cid, selectedId) {
    const map  = (window.TAPP_UOR && TAPP_UOR.deptsByCompany) || {};
    const list = map[parseInt(cid || 0, 10)] || [];

    resetSelect($dept, deptLabel);

    list.forEach((d) => {
      const o = document.createElement('option');
      o.value = String(d.id);
      o.textContent = d.name;
      $dept.appendChild(o);
    });

    $dept.disabled = !cid || list.length === 0;

    if (selectedId) {
      $dept.value = String(selectedId);
    } else {
      $dept.value = '';
    }
    refreshSelect2($dept);

    // whenever company changes, clear and lock role until dept picked
    if ($role) {
      resetSelect($role, roleLabel);
      $role.disabled = true;
      refreshSelect2($role);
    }
  }

  let lastLoadedDept = null;
  function fillRoles(deptId, selectedId) {
    if (!$role) return;

    resetSelect($role, roleLabel);       // ensure single placeholder, no dups
    $role.disabled = !deptId;
    refreshSelect2($role);

    if (!deptId || !window.TAPP_UOR || !TAPP_UOR.ajax_url) return;

    lastLoadedDept = String(deptId);

    const payload = new FormData();
    payload.append('action', 'tapp_uor_jobroles');
    if (TAPP_UOR.nonce) payload.append('nonce', TAPP_UOR.nonce);
    payload.append('department_id', String(deptId));

    fetch(TAPP_UOR.ajax_url, { method: 'POST', body: payload })
      .then((r) => r.json())
      .then((resp) => {
        // guard against out-of-order responses
        if (String(deptId) !== lastLoadedDept) return;

        if (!resp || !resp.success || !Array.isArray(resp.data)) return;

        // append roles
        resp.data.forEach((row) => {
          const o = document.createElement('option');
          o.value = String(row.id);
          o.textContent = row.label;
          $role.appendChild(o);
        });

        // keep or force placeholder
        if (selectedId) {
          $role.value = String(selectedId);
        } else {
          $role.value = ''; // leave on placeholder
        }
        $role.disabled = false;
        refreshSelect2($role);
      })
      .catch(() => {});
  }

  // wire events
  $company.addEventListener('change', () => fillDept($company.value, ''));
  $dept.addEventListener('change', () =>
    fillRoles($dept.value, ($role && ($role.dataset.selected || $role.value)) || '')
  );

  // initial hydrate (keep selection if present)
  const selectedDept = $dept.dataset.selected || $dept.getAttribute('data-selected') || $dept.value || '';
  const selectedRole = $role ? ($role.dataset.selected || $role.getAttribute('data-selected') || $role.value || '') : '';

  if ($company.value) {
    fillDept($company.value, selectedDept);
    if (selectedDept) fillRoles(selectedDept, selectedRole);
  } else {
    $dept.disabled = true;
    if ($role) {
      resetSelect($role, roleLabel);
      $role.disabled = true;
    }
  }
})();


/* =========================================================
 * Admin › User Profile: client-side submit validation
 * (blocks submit until all 3 fields are chosen once any is used)
 * ========================================================= */
(function () {
  const form =
    document.querySelector('#your-profile') ||
    document.querySelector('form[action*="profile.php"]') ||
    document.querySelector('form[action*="user-edit.php"]');
  if (!form) return;

  // Robust selectors (same as the loader block)
  const company = form.querySelector(
    '#tapp_company_id, #tapp_company, select[name="tapp_company_id"], select[name="tapp_company"], select[name="company_id"], select[data-tapp="company"]'
  );
  const dept = form.querySelector(
    '#tapp_department_id, #tapp_department, select[name="tapp_department_id"], select[name="tapp_department"], select[name="department_id"], select[data-tapp="department"]'
  );
  const role = form.querySelector(
    '#tapp_job_role_id, #tapp_jobrole, select[name="tapp_job_role_id"], select[name="tapp_jobrole"], select[name="job_role_id"], select[data-tapp="jobrole"]'
  );
  if (!company || !dept || !role) return;

  function clearNotice() {
    const n = form.querySelector('.tapp-inline-error-notice');
    if (n) n.remove();
  }
  function showNotice(msg) {
    clearNotice();
    const div = document.createElement('div');
    div.className = 'notice notice-error tapp-inline-error-notice';
    div.innerHTML = `<p>${msg}</p>`;
    form.prepend(div);
    div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  form.addEventListener('submit', function (e) {
    const c = company.value;
    const d = dept.value;
    const r = role.value;

    const anyChosen = !!(c || d || r);
    const allChosen = !!(c && d && r);

    // If user started choosing anything, require all three before submit
    if (anyChosen && !allChosen) {
      e.preventDefault();
      if (c && !d) {
        showNotice(
          (window.TAPP_UOR?.i18n?.selectDepartment) ||
            'Please select a Department before saving.'
        );
        dept.focus();
        return;
      }
      if ((c && d) && !r) {
        showNotice(
          (window.TAPP_UOR?.i18n?.selectRole) || 'Please select a Job Role before saving.'
        );
        role.focus();
        return;
      }
      // catch-all
      showNotice('Please select Company, Department, and Job Role before saving.');
    } else {
      clearNotice();
    }
  });
})();

/* =========================================================
 * My Account: Company → Department → Role
 * ========================================================= */
(function () {
  if (!window.TAPP_UOR || !TAPP_UOR.ajax_url) return;

  const $company = document.getElementById('tapp_company_account');
  const $dept    = document.getElementById('tapp_department_account');
  const $role    = document.getElementById('tapp_jobrole_account');
  if (!$company || !$dept) return;

  function resetSelect(sel, label) {
    sel.innerHTML = '';
    const o = document.createElement('option');
    o.value = '';
    o.textContent = label;
    sel.appendChild(o);
  }

  function fillDepartments(cid, selected) {
    resetSelect($dept, (TAPP_UOR.i18n && TAPP_UOR.i18n.selectDepartment) || 'Select Department');
    $dept.disabled = true;

    // Always reset role too
    if ($role) {
      resetSelect($role, (TAPP_UOR.i18n && TAPP_UOR.i18n.selectRole) || 'Select Job Role');
      $role.disabled = true;
    }
    if (!cid) return;

    const fd = new FormData();
    fd.append('action', 'tapp_uor_departments');
    if (TAPP_UOR.nonce) fd.append('nonce', TAPP_UOR.nonce);
    fd.append('company_id', String(cid));

    fetch(TAPP_UOR.ajax_url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(resp => {
        if (!resp || !resp.success || !Array.isArray(resp.data)) return;
        resp.data.forEach(d => {
          const opt = document.createElement('option');
          opt.value = String(d.id);
          opt.textContent = d.name;
          $dept.appendChild(opt);
        });
        if (selected) $dept.value = String(selected);
        $dept.disabled = false;
      })
      .catch(() => {});
  }

  function fillRoles(deptId, selected) {
    if (!$role) return;
    resetSelect($role, (TAPP_UOR.i18n && TAPP_UOR.i18n.selectRole) || 'Select Job Role');
    $role.disabled = true;
    if (!deptId) return;

    const fd = new FormData();
    fd.append('action', 'tapp_uor_jobroles');
    if (TAPP_UOR.nonce) fd.append('nonce', TAPP_UOR.nonce);
    fd.append('department_id', String(deptId));

    fetch(TAPP_UOR.ajax_url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(resp => {
        if (!resp || !resp.success || !Array.isArray(resp.data)) return;
        resp.data.forEach(x => {
          const opt = document.createElement('option');
          opt.value = String(x.id);
          opt.textContent = x.label;
          $role.appendChild(opt);
        });
        if (selected) $role.value = String(selected);
        $role.disabled = false;
      })
      .catch(() => {});
  }

  // Events
  $company.addEventListener('change', () => fillDepartments($company.value, ''));
  $dept.addEventListener('change', () =>
    fillRoles($dept.value, ($role && ($role.dataset.selected || $role.value)) || '')
  );

  // Initial hydrate (keeps current selection on first load)
  const selDept = $dept.dataset.selected || $dept.value || '';
  const selRole = $role ? ($role.dataset.selected || $role.value || '') : '';
  if ($company.value) {
    fillDepartments($company.value, selDept);
    if (selDept) fillRoles(selDept, selRole);
  }
})();



