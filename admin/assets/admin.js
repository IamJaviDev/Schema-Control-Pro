document.addEventListener('DOMContentLoaded', function () {
  const editor = document.getElementById('schema_json');
  const preview = document.getElementById('scm-json-preview');
  const status = document.getElementById('scm-json-status');
  const btn = document.getElementById('scm-validate-json');
  const targetType = document.getElementById('target_type');
  const targetValueRow = document.querySelector('.scm-target-value-row');
  const targetValue = document.getElementById('target_value');
  const targetHelp = document.getElementById('scm-target-help');
  const mode = document.getElementById('mode');
  const replacedTypesRow = document.querySelector('.scm-replaced-types-row');
  const modeHelp = document.getElementById('scm-mode-help');
  const summaryAioseo = document.getElementById('scm-summary-aioseo');
  const summaryReplacements = document.getElementById('scm-summary-replacements');
  const summaryOutput = document.getElementById('scm-summary-output');

  const modeDescriptions = {
    aioseo_only: 'Uses only the schema graph from AIOSEO.',
    aioseo_plus_custom: 'Keeps the AIOSEO graph and adds your custom nodes.',
    custom_override_selected: 'Replaces selected types and rewires key references when possible.',
    custom_only: 'Disables AIOSEO on this target and outputs only your custom graph.'
  };

  function normalizeForPreview(parsed) {
    let graph = [];
    if (Array.isArray(parsed)) {
      graph = parsed;
    } else if (parsed && typeof parsed === 'object' && Array.isArray(parsed['@graph'])) {
      graph = parsed['@graph'];
    } else if (parsed && typeof parsed === 'object') {
      graph = [parsed];
    }
    return {
      '@context': 'https://schema.org',
      '@graph': graph
    };
  }

  function refreshPreview() {
    if (!editor || !preview) return;
    try {
      const parsed = JSON.parse(editor.value);
      preview.textContent = JSON.stringify(normalizeForPreview(parsed), null, 2);
    } catch (err) {
      preview.textContent = editor.value;
    }
  }

  function validateJson() {
    if (!editor || !status) return;
    try {
      const parsed = JSON.parse(editor.value);
      status.textContent = 'Valid JSON';
      status.style.color = '#008a20';
      preview.textContent = JSON.stringify(normalizeForPreview(parsed), null, 2);
    } catch (err) {
      status.textContent = err.message;
      status.style.color = '#b32d2e';
    }
  }

  function updateTargetUi() {
    if (!targetType || !targetValueRow) return;
    const value = targetType.value;

    if (value === 'home') {
      targetValueRow.style.display = '';
      if (targetValue) targetValue.value = '';
      if (targetValue) targetValue.setAttribute('disabled', 'disabled');
      if (targetHelp) targetHelp.textContent = 'Not required for Home.';
      return;
    }

    if (targetValue) targetValue.removeAttribute('disabled');
    targetValueRow.style.display = '';

    if (!targetHelp) return;
    if (value === 'author') {
      targetHelp.textContent = 'Use the author user_nicename, for example: javier-perez';
    } else if (value === 'exact_url') {
      targetHelp.textContent = 'Use the full canonical URL, including protocol.';
    } else {
      targetHelp.textContent = 'Use the exact slug without the domain, for example: dar-de-baja-un-coche';
    }
  }

  function updateModeUi() {
    if (!mode) return;
    const currentMode = mode.value;
    if (replacedTypesRow) {
      replacedTypesRow.style.display = currentMode === 'custom_override_selected' ? '' : 'none';
    }
    if (modeHelp) {
      modeHelp.textContent = modeDescriptions[currentMode] || '';
    }
    if (summaryAioseo) {
      summaryAioseo.textContent = currentMode === 'custom_only' ? 'Disabled' : (currentMode === 'aioseo_only' ? 'Active only' : 'Active');
    }
    if (summaryOutput) {
      summaryOutput.textContent = currentMode === 'custom_only' ? 'Only custom output' : (currentMode === 'aioseo_only' ? 'Only AIOSEO output' : (currentMode === 'custom_override_selected' ? 'AIOSEO filtered + custom' : 'AIOSEO + custom merge'));
    }
    if (summaryReplacements) {
      const checked = Array.from(document.querySelectorAll('input[name="replaced_types[]"]:checked')).map((el) => el.value);
      summaryReplacements.textContent = checked.length ? checked.join(', ') : '—';
    }
  }

  if (editor) {
    refreshPreview();
    editor.addEventListener('input', refreshPreview);
  }
  if (btn) {
    btn.addEventListener('click', validateJson);
  }
  if (targetType) {
    updateTargetUi();
    targetType.addEventListener('change', updateTargetUi);
  }
  if (mode) {
    updateModeUi();
    mode.addEventListener('change', updateModeUi);
  }
  document.querySelectorAll('input[name="replaced_types[]"]').forEach((checkbox) => checkbox.addEventListener('change', updateModeUi));
});
