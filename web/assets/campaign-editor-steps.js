'use strict';

const CAMPAIGN_EDITOR_STEPS = [
  ['basic', '基本情報'],
  ['targets', '対象者'],
  ['scenario', 'シナリオ'],
  ['schedule', '日時・送信量'],
  ['delivery', '送信環境'],
  ['review', '最終確認'],
];

function createCampaignEditorSteps(form, options = {}) {
  const sections = new Map(Array.from(form.querySelectorAll(':scope > [data-campaign-step]'))
    .map((section) => [section.dataset.campaignStep, section]));
  if (CAMPAIGN_EDITOR_STEPS.some(([id]) => !sections.has(id)) || sections.size !== CAMPAIGN_EDITOR_STEPS.length) {
    throw new Error('編集手順の構成が不正です');
  }
  const nav = document.createElement('nav');
  nav.className = 'campaign-editor-nav';
  nav.setAttribute('aria-label', 'キャンペーン編集手順');
  nav.innerHTML = `<p class="campaign-editor-nav-title">下書きの編集</p><ol>${CAMPAIGN_EDITOR_STEPS.map(([id, label], index) =>
    `<li><button type="button" data-editor-step="${id}"><span>${index + 1}</span> ${label}</button></li>`).join('')}</ol>
    <button type="button" class="campaign-editor-mode" data-editor-mode>すべて表示</button>`;
  form.classList.add('campaign-editor-form');
  form.prepend(nav);
  CAMPAIGN_EDITOR_STEPS.forEach(([id]) => form.append(sections.get(id)));
  const controls = document.createElement('div');
  controls.className = 'campaign-editor-controls';
  controls.setAttribute('role', 'group');
  controls.setAttribute('aria-label', '手順移動');
  controls.innerHTML = `<button type="button" data-editor-previous>前へ</button>
    <span data-editor-progress aria-live="polite"></span>
    <button type="button" data-editor-next></button>`;
  form.append(controls);
  let current = Number.isInteger(options.initialStep) && options.initialStep >= 0 && options.initialStep < CAMPAIGN_EDITOR_STEPS.length
    ? options.initialStep : 0;
  let expert = false;

  function render() {
    CAMPAIGN_EDITOR_STEPS.forEach(([id], index) => {
      sections.get(id).hidden = !expert && index !== current;
      const button = nav.querySelector(`[data-editor-step="${id}"]`);
      button.classList.toggle('is-current', !expert && index === current);
      if (!expert && index === current) button.setAttribute('aria-current', 'step');
      else button.removeAttribute('aria-current');
    });
    nav.querySelector('[data-editor-mode]').textContent = expert ? '手順表示' : 'すべて表示';
    controls.hidden = expert;
    controls.querySelector('[data-editor-previous]').disabled = current === 0;
    controls.querySelector('[data-editor-progress]').textContent = `手順 ${current + 1} / ${CAMPAIGN_EDITOR_STEPS.length}`;
    const next = controls.querySelector('[data-editor-next]');
    next.disabled = current === CAMPAIGN_EDITOR_STEPS.length - 1;
    next.textContent = next.disabled ? '最後の手順' : `次へ: ${CAMPAIGN_EDITOR_STEPS[current + 1][1]}`;
  }

  function showStep(index) {
    current = index;
    expert = false;
    render();
    sections.get(CAMPAIGN_EDITOR_STEPS[current][0]).querySelector('h3')?.focus();
  }

  nav.addEventListener('click', (event) => {
    const stepButton = event.target.closest('[data-editor-step]');
    if (stepButton) {
      showStep(CAMPAIGN_EDITOR_STEPS.findIndex(([id]) => id === stepButton.dataset.editorStep));
      return;
    }
    if (event.target.closest('[data-editor-mode]')) {
      expert = !expert;
      render();
    }
  });
  controls.addEventListener('click', (event) => {
    if (event.target.closest('[data-editor-previous]') && current > 0) showStep(current - 1);
    if (event.target.closest('[data-editor-next]') && current < CAMPAIGN_EDITOR_STEPS.length - 1) showStep(current + 1);
  });

  function validateBeforeSave() {
    const invalid = form.querySelector(':invalid');
    if (!invalid) return true;
    const section = invalid.closest('[data-campaign-step]');
    if (section) current = CAMPAIGN_EDITOR_STEPS.findIndex(([id]) => id === section.dataset.campaignStep);
    expert = false;
    render();
    invalid.focus();
    invalid.reportValidity();
    return false;
  }

  render();
  return { validateBeforeSave };
}

window.createCampaignEditorSteps = createCampaignEditorSteps;
