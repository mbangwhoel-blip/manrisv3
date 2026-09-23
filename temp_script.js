
function duplicateProfil(event, form) {
    event.stopPropagation();
    const year = prompt('Salin profil ini ke tahun berapa?', String(new Date().getFullYear()));
    if (!year || !/^\d{4}$/.test(year)) return false;
    form.querySelector('input[name="tahun_baru"]').value = year;
    return confirm('Buat salinan Profil Risiko untuk tahun ' + year + '?');
}
function bukaTabDetailProfil() {
    const button = document.querySelector('.tab-btn[onclick*="tabDetail"]');
    if (button) button.click();
    else window.location.hash = 'tabDetail';
    document.getElementById('tabDetail')?.scrollIntoView({behavior:'smooth', block:'start'});
}

// Draft lokal profil: tidak masuk database sampai user menekan Simpan.
(function () {
    const form = document.getElementById('formProfilHeader');
    if (!form) return;
    const draftKey = 'manrisv2:profil-header:' + (form.querySelector('input[name="id"]')?.value || 'new');
    const fields = Array.from(form.querySelectorAll('input:not([type="hidden"]), textarea, select'));
    const status = document.createElement('small');
    status.style.cssText = 'display:block;color:var(--text-muted);font-size:.72rem;margin-top:6px';
    status.textContent = 'Draft tersimpan otomatis di perangkat ini';
    form.querySelector('.modal-body')?.prepend(status);
    const saveDraft = () => {
        const data = {};
        fields.forEach(field => { if (field.name && field.type !== 'file') data[field.name] = field.multiple ? Array.from(field.selectedOptions).map(o => o.value) : field.value; });
        localStorage.setItem(draftKey, JSON.stringify({savedAt: Date.now(), data}));
        status.textContent = 'Draft tersimpan otomatis pukul ' + new Date().toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
    };
    let timer;
    fields.forEach(field => field.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(saveDraft, 500); }));
    fields.forEach(field => field.addEventListener('change', saveDraft));
    try {
        const raw = localStorage.getItem(draftKey);
        if (raw) {
            const draft = JSON.parse(raw);
            if (Date.now() - Number(draft.savedAt || 0) < 7 * 24 * 60 * 60 * 1000 && draft.data && confirm('Ada draft Profil Risiko tersimpan. Pulihkan sekarang?')) {
                fields.forEach(field => { if (!(field.name in draft.data)) return; if (field.multiple) Array.from(field.options).forEach(o => o.selected = draft.data[field.name].includes(o.value)); else field.value = draft.data[field.name]; });
            }
        }
    } catch (e) {}
    form.addEventListener('submit', () => localStorage.removeItem(draftKey));
})();

function autofillNip(inputEl, nipFieldName, scopeSelector) {
    const list = document.getElementById('listUsersWithNip');
    const options = list.options;
    let foundNip = '';
    for(let i=0; i<options.length; i++) {
        if(options[i].value === inputEl.value) {
            foundNip = options[i].getAttribute('data-nip');
            break;
        }
    }
    if(foundNip) {
        const nipEl = document.querySelector(scopeSelector + ' input[name="'+nipFieldName+'"]');
        if(nipEl) nipEl.value = foundNip;
    }
}
function appendMasterIndicators(selectEl, indicatorSelector, targetSelector) {
    const indicators = Array.from(selectEl.selectedOptions)
        .filter(option => option.value)
        .map((option, index) => (index + 1) + '. ' + (option.dataset.indikator || ''))
        .filter(Boolean);
    const targets = Array.from(selectEl.selectedOptions)
        .filter(option => option.value)
        .map((option, index) => (index + 1) + '. ' + (option.dataset.target || ''))
        .filter(Boolean);
    const indicatorEl = document.querySelector(indicatorSelector);
    const targetEl = document.querySelector(targetSelector);
    if (indicatorEl && indicators.length) indicatorEl.value = indicators.join('\n');
    else if (indicatorEl) indicatorEl.value = '';
    
    if (targetEl && targets.length) targetEl.value = targets.join('\n');
    else if (targetEl) targetEl.value = '';
}

document.addEventListener('DOMContentLoaded', function() {
    // Membuat select multiple bisa ditoggle tanpa menahan Ctrl
    document.querySelectorAll('select[multiple]').forEach(select => {
        select.addEventListener('mousedown', function(e) {
            e.preventDefault();
            const option = e.target;
            if (option.tagName === 'OPTION') {
                option.selected = !option.selected;
                select.dispatchEvent(new Event('change'));
            }
            select.focus();
        });
    });
    if ($profilRow) {
        // Detail profil ditambahkan secara sadar dari master Identifikasi Risiko.
        // Tidak ada sinkronisasi otomatis agar P/D dan rencana penanganan tidak tertimpa.
        try {
            $s2 = $db->prepare('SELECT * FROM profil_risiko_detail WHERE id_profil=? ORDER BY no_urut, id');
            $s2->bind_param('i',$activeId); $s2->execute();
            $detailRows = $s2->get_result()->fetch_all(MYSQLI_ASSOC); $s2->close();
        } catch (Throwable $e) {
            $detailRows = [];
        }

        $headerFields = ['unit_pemilik_risiko', 'nama_pemilik_risiko', 'tujuan', 'sasaran', 'indikator_kinerja', 'target', 'program', 'kegiatan'];
        $headerComplete = count(array_filter($headerFields, static fn(string $field): bool => trim((string)($profilRow[$field] ?? '')) !== ''));
        $detailTotal = count($detailRows);
        $detailScored = count(array_filter($detailRows, static fn(array $row): bool => $row['probabilitas'] !== null && $row['dampak'] !== null && $row['nilai'] !== null));
        $detailPlan = count(array_filter($detailRows, static fn(array $row): bool => trim((string)($row['rencana_penanganan'] ?? '')) !== '' && trim((string)($row['penanggungjawab'] ?? '')) !== ''));
        $detailTarget = count(array_filter($detailRows, static fn(array $row): bool => $row['target_p'] !== null && $row['target_d'] !== null));
        $missingPlanPic = [];
        foreach ($detailRows as $detailRow) {
            $missing = [];
            if (trim((string)($detailRow['rencana_penanganan'] ?? '')) === '') $missing[] = 'Uraian Pengendalian';
            if (trim((string)($detailRow['penanggungjawab'] ?? '')) === '') $missing[] = 'PIC';
            if ($missing) $missingPlanPic[] = ($detailRow['kode_risiko'] ?? $detailRow['nama_risiko'] ?? 'Risiko') . ': ' . implode(' dan ', $missing);
        }
        $profilChecklist = [
            ['Header profil lengkap', $headerComplete === count($headerFields), $headerComplete . '/' . count($headerFields) . ' field'],
            ['Indikator dan target terisi', trim((string)($profilRow['indikator_kinerja'] ?? '')) !== '' && trim((string)($profilRow['target'] ?? '')) !== '', 'Profil utama'],
            ['Detail risiko ditambahkan', $detailTotal > 0, $detailTotal . ' risiko'],
            ['Penilaian P/D lengkap', $detailTotal > 0 && $detailScored === $detailTotal, $detailScored . '/' . $detailTotal . ' dinilai'],
            ['Rencana dan PIC lengkap', $detailTotal > 0 && $detailPlan === $detailTotal, $detailPlan . '/' . $detailTotal . ' lengkap', $missingPlanPic],
            ['Target risiko lengkap', $detailTotal > 0 && $detailTarget === $detailTotal, $detailTarget . '/' . $detailTotal . ' lengkap'],
        ];
        $profilCompleteness = (int)round(count(array_filter($profilChecklist, static fn(array $item): bool => $item[1])) / count($profilChecklist) * 100);

        $editDetailId = (int)($_GET['edit_detail'] ?? 0);
        if ($editDetailId > 0) {
            try {
                $s3 = $db->prepare('SELECT * FROM profil_risiko_detail WHERE id=? AND id_profil=?');
                $s3->bind_param('ii',$editDetailId, $activeId); $s3->execute();
                $editDetail = $s3->get_result()->fetch_assoc(); $s3->close();
                $activeTab = 'detail';
            } catch (Throwable $e) {
                $editDetail = null;
            }
        }
    }
}

// ── Warna tingkat ─────────────────────────────────────────────
function colorTingkat(string $t): string {
    return match($t) {
        'Sangat Tinggi' => '#991b1b', // badge-danger
        'Tinggi'        => '#c2410c', // badge-orange
        'Sedang'        => '#000', // text hitam untuk bg kuning
        'Rendah'        => '#166534', // badge-success
        default         => '#1e40af', // badge-info
    };
}
function bgTingkat(string $t): string {
    return match($t) {
        'Sangat Tinggi' => '#fee2e2',
        'Tinggi'        => '#ffedd5',
        'Sedang'        => '#FFFF00', // kuning terang background
        'Rendah'        => '#dcfce7',
        default         => '#dbeafe',
    };
}

// ── Export Excel ──────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'excel' && $activeId > 0 && $profilRow) {
    $periode = $_GET['periode'] ?? 'tahunan';
    if (!in_array($periode, ['tahunan','tw1','tw2','tw3','tw4'], true)) $periode = 'tahunan';
    $triwulanRomawi = ['I','II','III','IV'];
    $periodeLabel = $periode === 'tahunan'
        ? 'Laporan Tahunan'
        : 'Laporan Triwulan ' . $triwulanRomawi[(int)substr($periode, 2) - 1];
    $GLOBALS['EXPORT_PERIODE'] = $periodeLabel;

    $headers = ['NO', 'UNIT KERJA PEMILIK RISIKO', 'RISIKO', 'KODE RISIKO', 'P', 'D', 'BOBOT', 'NILAI', 'TINGKAT RISIKO', 'PRIORITAS RISIKO', 'URAIAN PENGENDALIAN', 'JADWAL PELAKSANAAN', 'PENANGGUNGJAWAB', 'P (TARGET)', 'D (TARGET)', 'BOBOT (TARGET)', 'NILAI (TARGET)', 'TINGKAT RISIKO (TARGET)'];
    $excelRows = [];
    foreach ($detailRows as $i => $dr) {
        $rowNum = $i + 2;
        $excelRows[] = [
            $i + 1,
            $dr['unit_kerja'] ?? '',
            $dr['nama_risiko'] ?? '',
            $dr['kode_risiko'] ?? '',
            $dr['probabilitas'] ?? '',
            $dr['dampak'] ?? '',
            "=ARRAY_CONSTRAIN(ARRAYFORMULA(INDEX(Bobot!\$XEY\$2:\$XFC\$6, E{$rowNum}, F{$rowNum})), 1, 1)",
            $dr['nilai'] ?? '',
            $dr['tingkat_risiko'] ?? '',
            "=IF(H{$rowNum}<=4,5,IF(H{$rowNum}<=9,4,IF(H{$rowNum}<=14,3,IF(H{$rowNum}<=19,2,1))))",
            $dr['rencana_penanganan'] ?? '',
            $dr['jadwal_pelaksanaan'] ?? '',
            $dr['penanggungjawab'] ?? '',
            $dr['target_p'] ?? '',
            $dr['target_d'] ?? '',
            "=ARRAY_CONSTRAIN(ARRAYFORMULA(INDEX(Bobot!\$XEY\$2:\$XFC\$6, N{$rowNum}, O{$rowNum})), 1, 1)",
            $dr['target_nilai'] ?? '',
            $dr['target_tingkat_risiko'] ?? '',
        ];
    }
    $namaFile = 'profil_risiko_' . ($profilRow['tahun'] ?? '') . '_' . date('Ymd_His');
    exportExcel($namaFile, $headers, $excelRows);
}
?>




<script>
function duplicateProfil(event, form) {
    event.stopPropagation();
    const year = prompt('Salin profil ini ke tahun berapa?', String(new Date().getFullYear()));
    if (!year || !/^\d{4}$/.test(year)) return false;
    form.querySelector('input[name="tahun_baru"]').value = year;
    return confirm('Buat salinan Profil Risiko untuk tahun ' + year + '?');
}
function bukaTabDetailProfil() {
    const button = document.querySelector('.tab-btn[onclick*="tabDetail"]');
    if (button) button.click();
    else window.location.hash = 'tabDetail';
    document.getElementById('tabDetail')?.scrollIntoView({behavior:'smooth', block:'start'});
}

// Draft lokal profil: tidak masuk database sampai user menekan Simpan.
(function () {
    const form = document.getElementById('formProfilHeader');
    if (!form) return;
    const draftKey = 'manrisv2:profil-header:' + (form.querySelector('input[name="id"]')?.value || 'new');
    const fields = Array.from(form.querySelectorAll('input:not([type="hidden"]), textarea, select'));
    const status = document.createElement('small');
    status.style.cssText = 'display:block;color:var(--text-muted);font-size:.72rem;margin-top:6px';
    status.textContent = 'Draft tersimpan otomatis di perangkat ini';
    form.querySelector('.modal-body')?.prepend(status);
    const saveDraft = () => {
        const data = {};
        fields.forEach(field => { if (field.name && field.type !== 'file') data[field.name] = field.multiple ? Array.from(field.selectedOptions).map(o => o.value) : field.value; });
        localStorage.setItem(draftKey, JSON.stringify({savedAt: Date.now(), data}));
        status.textContent = 'Draft tersimpan otomatis pukul ' + new Date().toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
    };
    let timer;
    fields.forEach(field => field.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(saveDraft, 500); }));
    fields.forEach(field => field.addEventListener('change', saveDraft));
    try {
        const raw = localStorage.getItem(draftKey);
        if (raw) {
            const draft = JSON.parse(raw);
            if (Date.now() - Number(draft.savedAt || 0) < 7 * 24 * 60 * 60 * 1000 && draft.data && confirm('Ada draft Profil Risiko tersimpan. Pulihkan sekarang?')) {
                fields.forEach(field => { if (!(field.name in draft.data)) return; if (field.multiple) Array.from(field.options).forEach(o => o.selected = draft.data[field.name].includes(o.value)); else field.value = draft.data[field.name]; });
            }
        }
    } catch (e) {}
    form.addEventListener('submit', () => localStorage.removeItem(draftKey));
})();

function autofillNip(inputEl, nipFieldName, scopeSelector) {
    const list = document.getElementById('listUsersWithNip');
    const options = list.options;
    let foundNip = '';
    for(let i=0; i<options.length; i++) {
        if(options[i].value === inputEl.value) {
            foundNip = options[i].getAttribute('data-nip');
            break;
        }
    }
    if(foundNip) {
        const nipEl = document.querySelector(scopeSelector + ' input[name="'+nipFieldName+'"]');
        if(nipEl) nipEl.value = foundNip;
    }
}
function appendMasterIndicators(selectEl, indicatorSelector, targetSelector) {
    const indicators = Array.from(selectEl.selectedOptions)
        .filter(option => option.value)
        .map((option, index) => (index + 1) + '. ' + (option.dataset.indikator || ''))
        .filter(Boolean);
    const targets = Array.from(selectEl.selectedOptions)
        .filter(option => option.value)
        .map((option, index) => (index + 1) + '. ' + (option.dataset.target || ''))
        .filter(Boolean);
    const indicatorEl = document.querySelector(indicatorSelector);
    const targetEl = document.querySelector(targetSelector);
    if (indicatorEl && indicators.length) indicatorEl.value = indicators.join('\n');
    else if (indicatorEl) indicatorEl.value = '';
    
    if (targetEl && targets.length) targetEl.value = targets.join('\n');
    else if (targetEl) targetEl.value = '';
}

document.addEventListener('DOMContentLoaded', function() {
    // Membuat select multiple bisa ditoggle tanpa menahan Ctrl
    document.querySelectorAll('select[multiple]').forEach(select => {
        select.addEventListener('mousedown', function(e) {
            e.preventDefault();
            const option = e.target;
            if (option.tagName === 'OPTION') {
                option.selected = !option.selected;
                select.dispatchEvent(new Event('change'));
            }
            select.focus();
        });
    });
});

